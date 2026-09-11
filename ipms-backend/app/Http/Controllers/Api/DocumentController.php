<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    /**
     * 项目文档列表/目录树
     * GET /api/projects/{projectId}/documents
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($projectId);

        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权查看该项目文档'], 403);
        }

        // 获取文件夹树
        $folders = Folder::where('project_id', $projectId)
            ->whereNull('parent_id')
            ->with(['children.documents.uploader:id,display_name', 'children.children.documents.uploader:id,display_name', 'children.children.children.documents.uploader:id,display_name', 'documents' => function ($q) {
                $q->with('uploader:id,display_name')->orderBy('created_at', 'desc');
            }])
            ->orderBy('name')
            ->get();

        // 获取根目录下的文档
        $rootDocuments = Document::where('project_id', $projectId)
            ->whereNull('folder_id')
            ->with('uploader:id,display_name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'folders' => $folders,
                'root_documents' => $rootDocuments,
            ],
        ]);
    }

    /**
     * 上传文件
     * POST /api/projects/{projectId}/documents/upload
     */
    public function upload(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($projectId);

        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权上传文件到该项目'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 最大 20MB
            'folder_id' => ['nullable', 'integer', Rule::exists('folders', 'id')->where('project_id', $projectId)],
        ]);

        $file = $request->file('file');
        $path = $file->store("projects/{$projectId}/documents", 'public');

        $document = Document::create([
            'project_id' => $projectId,
            'folder_id' => $request->input('folder_id'),
            'title' => $file->getClientOriginalName(),
            'file' => $path,
            'file_size' => $file->getSize(),
            'file_type' => $file->getClientMimeType(),
            'uploaded_by_id' => $user->id,
            'version' => 1,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'document',
            'action_type' => 'upload',
            'target_type' => 'document',
            'target_id' => $document->id,
            'target_name' => $document->title,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '文件上传成功',
            'data' => $document,
        ], 201);
    }

    /**
     * 创建文件夹
     * POST /api/projects/{projectId}/documents/folder
     */
    public function createFolder(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($projectId);

        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权操作该项目文档'], 403);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        $parentId = $request->input('parent_id');
        $level = 0;

        // 计算层级（文件夹最多 3 层）
        if ($parentId) {
            $parent = Folder::findOrFail($parentId);
            if ($parent->project_id != $projectId) {
                return response()->json(['code' => 422, 'message' => '父文件夹不属于该项目'], 422);
            }
            $level = $parent->level + 1;
            if ($level > 3) {
                return response()->json(['code' => 422, 'message' => '文件夹层级不能超过 3 层'], 422);
            }
        }

        $folder = Folder::create([
            'project_id' => $projectId,
            'parent_id' => $parentId,
            'name' => $request->input('name'),
            'level' => $level,
            'created_by_id' => $user->id,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'document',
            'action_type' => 'create_folder',
            'target_type' => 'folder',
            'target_id' => $folder->id,
            'target_name' => $folder->name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '文件夹创建成功',
            'data' => $folder,
        ], 201);
    }

    /**
     * 下载文件
     * GET /api/documents/{id}/download
     */
    public function download(Request $request, int $id): mixed
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        $project = $document->project;
        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权下载该文件'], 403);
        }

        if (!Storage::disk('public')->exists($document->file)) {
            return response()->json(['code' => 404, 'message' => '文件不存在'], 404);
        }

        return Storage::disk('public')->download($document->file, $document->title);
    }

    /**
     * 删除文件/文件夹（软删，进回收站）
     * DELETE /api/documents/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        $project = $document->project;
        if (!$user->can('view', $project)) {
            return response()->json(['code' => 403, 'message' => '您无权删除该文件'], 403);
        }

        $document->deleted_by_id = $user->id;
        $document->save();
        $document->delete(); // SoftDeletes

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'document',
            'action_type' => 'delete',
            'target_type' => 'document',
            'target_id' => $document->id,
            'target_name' => $document->title,
            'detail' => ['soft_delete' => true],
        ]);

        return response()->json([
            'code' => 200,
            'message' => '文件已移至回收站',
        ]);
    }
}
