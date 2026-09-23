<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Models\Defect;
use App\Models\DefectAttachment;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class DefectAttachmentController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $defect = Defect::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('uploadAttachment', $defect);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 最大 20MB，与文档模块一致
        ]);

        $user = $request->user();
        $file = $request->file('file');
        $path = $file->store("defects/{$defect->id}/attachments", 'public');

        $attachment = DefectAttachment::query()->create([
            'defect_id' => $defect->id,
            'file' => $path,
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_type' => $file->getClientMimeType(),
            'uploaded_by_id' => $user->id,
        ]);

        $this->audit($user, 'upload', $defect, $attachment->filename);

        return ApiResponse::success(
            $this->present($attachment->fresh('uploader:id,display_name')),
            '附件上传成功',
            201,
        );
    }

    public function download(Request $request, int $id): mixed
    {
        $attachment = DefectAttachment::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $attachment->defect);

        if (! Storage::disk('public')->exists($attachment->file)) {
            return ApiResponse::error('ATTACHMENT_NOT_FOUND', '文件不存在或已被删除', 404);
        }

        return Storage::disk('public')->download($attachment->file, $attachment->filename);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $attachment = DefectAttachment::query()->findOrFail($id);
        Gate::forUser($request->user())
            ->authorize('deleteAttachment', [$attachment->defect, $attachment]);

        $defect = $attachment->defect;
        $filename = $attachment->filename;
        $attachment->delete();

        $this->audit($request->user(), 'delete', $defect, $filename);

        return ApiResponse::success(null, '附件已删除');
    }

    private function present(DefectAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'file_size' => (int) $attachment->file_size,
            'file_type' => $attachment->file_type,
            'uploaded_by' => $attachment->uploader === null ? null : [
                'id' => $attachment->uploader->id,
                'display_name' => $attachment->uploader->display_name,
            ],
            'uploaded_at' => $attachment->uploaded_at?->toISOString(),
        ];
    }

    private function audit(User $user, string $action, Defect $defect, string $filename): void
    {
        AuditLogger::log($user->id, [
            'user_name' => $user->username,
            'user_display_name' => $user->display_name,
            'user_type' => $user->user_type,
            'module' => 'defect',
            'action_type' => $action,
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
            'detail' => ['attachment' => $filename],
        ]);
    }
}
