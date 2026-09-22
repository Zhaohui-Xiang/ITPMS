<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\StoreApiDocumentRequest;
use App\Models\ApiDocument;
use App\Models\ApiDocumentVersion;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiDocumentController extends Controller
{
    /**
     * 接口文档列表
     * GET /api/projects/{projectId}/api-docs
     */
    public function index(Request $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($projectId);

        if ((!$user->isSuperAdmin() && !$user->hasPermission('document.view')) || !$user->can('view', $project)) {
            return ApiResponse::error('FORBIDDEN', '您无权查看该项目接口文档', 403);
        }

        $query = ApiDocument::where('project_id', $projectId)
            ->with(['updater:id,display_name', 'folder:id,name', 'requirement:id,title']);

        // 按文件夹筛选
        if ($request->has('folder_id')) {
            $query->where('folder_id', $request->integer('folder_id'));
        }

        // 按请求方法筛选
        if ($request->has('request_method')) {
            $query->where('request_method', strtoupper($request->input('request_method')));
        }

        // 搜索
        if ($request->has('keyword')) {
            $search = $request->input('keyword');
            $query->where(function ($q) use ($search) {
                $q->where('api_name', 'like', "%{$search}%")
                    ->orWhere('request_path', 'like', "%{$search}%");
            });
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($pageSize);

        return ApiResponse::paginated($paginator);
    }

    /**
     * 创建接口文档
     * POST /api/projects/{projectId}/api-docs
     */
    public function store(StoreApiDocumentRequest $request, int $projectId): JsonResponse
    {
        $user = $request->user();
        $project = Project::findOrFail($projectId);

        if (!$user->can('view', $project)) {
            return ApiResponse::error('FORBIDDEN', '您无权操作该项目', 403);
        }

        $apiDoc = ApiDocument::create([
            'project_id' => $projectId,
            'folder_id' => $request->input('folder_id'),
            'api_name' => $request->input('api_name'),
            'request_path' => $request->input('request_path'),
            'request_method' => $request->input('request_method'),
            'auth_type' => $request->input('auth_type'),
            'request_params' => $request->input('request_params') ?? [],
            'response_params' => $request->input('response_params') ?? [],
            'rich_text_body' => $request->input('rich_text_body'),
            'requirement_id' => $request->input('requirement_id'),
            'version' => 1,
            'updated_by_id' => $user->id,
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'api_document',
            'action_type' => 'create',
            'target_type' => 'api_document',
            'target_id' => $apiDoc->id,
            'target_name' => $apiDoc->api_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '接口文档创建成功',
            'data' => $apiDoc,
        ], 201);
    }

    /**
     * 接口文档详情
     * GET /api/api-docs/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $apiDoc = ApiDocument::with([
            'project:id,name',
            'folder:id,name',
            'requirement:id,title',
            'updater:id,display_name',
        ])->findOrFail($id);

        if ((!$user->isSuperAdmin() && !$user->hasPermission('document.view')) || !$user->can('view', $apiDoc->project)) {
            return ApiResponse::error('FORBIDDEN', '您无权查看该接口文档', 403);
        }

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $apiDoc,
        ]);
    }

    /**
     * 编辑接口文档（自动版本号递增）
     * PUT /api/api-docs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $apiDoc = ApiDocument::findOrFail($id);

        if ((!$user->isSuperAdmin() && !$user->hasPermission('document.edit_api')) || !$user->can('view', $apiDoc->project)) {
            return ApiResponse::error('FORBIDDEN', '您无权编辑该接口文档', 403);
        }

        $request->validate([
            'api_name' => ['sometimes', 'string', 'max:200'],
            'request_path' => ['sometimes', 'string', 'max:500'],
            'request_method' => ['sometimes', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'auth_type' => ['nullable', 'string', 'max:50'],
            'request_params' => ['nullable', 'array'],
            'response_params' => ['nullable', 'array'],
            'rich_text_body' => ['nullable', 'string'],
            'requirement_id' => ['nullable', 'integer', Rule::exists('requirement_project', 'requirement_id')->where('project_id', $apiDoc->project_id)],
        ]);

        $data = $request->only([
            'api_name', 'request_path', 'request_method', 'auth_type',
            'request_params', 'response_params', 'rich_text_body', 'requirement_id',
        ]);
        foreach (['request_params', 'response_params'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = [];
            }
        }
        $data['updated_by_id'] = $user->id;

        // 版本号递增
        $newVersion = $apiDoc->version + 1;
        $data['version'] = $newVersion;

        $apiDoc->update($data);

        // 保存版本快照
        ApiDocumentVersion::create([
            'api_document_id' => $apiDoc->id,
            'version_number' => $newVersion,
            'updated_by_id' => $user->id,
            'updated_at' => now(),
            'snapshot' => $apiDoc->toArray(),
        ]);

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'api_document',
            'action_type' => 'update',
            'target_type' => 'api_document',
            'target_id' => $apiDoc->id,
            'target_name' => $apiDoc->api_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => '接口文档更新成功',
            'data' => $apiDoc->fresh(),
        ]);
    }

    /**
     * 版本列表
     * GET /api/api-docs/{id}/versions
     */
    public function versions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $apiDoc = ApiDocument::findOrFail($id);

        if ((!$user->isSuperAdmin() && !$user->hasPermission('document.view')) || !$user->can('view', $apiDoc->project)) {
            return ApiResponse::error('FORBIDDEN', '您无权查看该接口文档', 403);
        }

        $versions = $apiDoc->versions()
            ->with('updater:id,display_name')
            ->orderBy('version_number', 'desc')
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $versions,
        ]);
    }

    /**
     * 导出 OpenAPI 规范（JSON）
     * POST /api/api-docs/{id}/export
     */
    public function export(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $apiDoc = ApiDocument::with('project:id,name')->findOrFail($id);

        if ((!$user->isSuperAdmin() && !$user->hasPermission('document.view')) || !$user->can('view', $apiDoc->project)) {
            return ApiResponse::error('FORBIDDEN', '您无权导出该接口文档', 403);
        }

        // 构建 OpenAPI 3.0 规范 JSON
        $openapi = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $apiDoc->api_name,
                'description' => $apiDoc->project->name ?? '',
                'version' => (string) $apiDoc->version,
            ],
            'paths' => [
                $apiDoc->request_path => [
                    strtolower($apiDoc->request_method) => [
                        'summary' => $apiDoc->api_name,
                        'description' => $apiDoc->rich_text_body ?? '',
                        'parameters' => $apiDoc->request_params ?? [],
                        'responses' => $apiDoc->response_params ?? [],
                    ],
                ],
            ],
        ];

        // 操作日志
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'api_document',
            'action_type' => 'export',
            'target_type' => 'api_document',
            'target_id' => $apiDoc->id,
            'target_name' => $apiDoc->api_name,
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $openapi,
        ]);
    }
}
