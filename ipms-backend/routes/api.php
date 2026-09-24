<?php

use App\Http\Controllers\Api\ApiDocumentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DefectAttachmentController;
use App\Http\Controllers\Api\DefectController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\InAppNotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\ProjectWorkOptionsController;
use App\Http\Controllers\Api\ProjectVersionController;
use App\Http\Controllers\Api\RequirementController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes -- IPMS项目管理系统
|--------------------------------------------------------------------------
|
| All routes use Sanctum SPA auth middleware.
| Permission checks are handled within controllers via policies.
|
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Sanctum SPA authenticated routes
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Auth
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ========================================================================
    // Dashboard
    // ========================================================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary']);
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/recent-requirements', [DashboardController::class, 'recentRequirements']);
        Route::get('/recent-tasks', [DashboardController::class, 'recentTasks']);
    });

    // ========================================================================
    // Projects
    // ========================================================================
    Route::prefix('projects')->group(function () {
        Route::get('/', [ProjectController::class, 'index']);
        Route::post('/', [ProjectController::class, 'store']);
        Route::get('/{projectId}/versions', [ProjectVersionController::class, 'index'])->whereNumber('projectId');
        Route::post('/{projectId}/versions', [ProjectVersionController::class, 'store'])->whereNumber('projectId');
        Route::get('/{id}/members', [ProjectMemberController::class, 'index'])->whereNumber('id');
        Route::get('/{id}/member-options', [ProjectMemberController::class, 'options'])->whereNumber('id');
        Route::post('/{id}/members', [ProjectMemberController::class, 'store'])->whereNumber('id');
        Route::delete('/{id}/members/{userId}', [ProjectMemberController::class, 'destroy'])->whereNumber(['id', 'userId']);
        Route::get('/{id}/assignee-options', [ProjectWorkOptionsController::class, 'index'])->whereNumber('id');
        Route::get('/{id}', [ProjectController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [ProjectController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ProjectController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/archive', [ProjectController::class, 'archive'])->whereNumber('id');

        // Documents (scoped under project)
        Route::get('/{projectId}/documents', [DocumentController::class, 'index']);
        Route::post('/{projectId}/documents/upload', [DocumentController::class, 'upload']);
        Route::post('/{projectId}/documents/folder', [DocumentController::class, 'createFolder']);

        // API Documents (scoped under project)
        Route::get('/{projectId}/api-docs', [ApiDocumentController::class, 'index']);
        Route::post('/{projectId}/api-docs', [ApiDocumentController::class, 'store']);
    });

    // ========================================================================
    // Project release versions
    // ========================================================================
    Route::prefix('project-versions')->group(function () {
        Route::post('/{id}/status', [ProjectVersionController::class, 'transition'])->whereNumber('id');
        Route::get('/{id}/gate-check', [ProjectVersionController::class, 'gateCheck'])->whereNumber('id');
        Route::post('/{id}/release', [ProjectVersionController::class, 'release'])->whereNumber('id');
        Route::get('/{id}/history', [ProjectVersionController::class, 'history'])->whereNumber('id');
        Route::get('/{id}', [ProjectVersionController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [ProjectVersionController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ProjectVersionController::class, 'destroy'])->whereNumber('id');
    });

    // ========================================================================
    // Requirements
    // ========================================================================
    Route::prefix('requirements')->group(function () {
        Route::get('/project-options', [RequirementController::class, 'projectOptions']);
        Route::get('/', [RequirementController::class, 'index']);
        Route::post('/', [RequirementController::class, 'store']);
        Route::put('/{requirementId}/projects/{projectId}/version', [ProjectVersionController::class, 'assignRequirement'])->whereNumber(['requirementId', 'projectId']);
        Route::delete('/{requirementId}/projects/{projectId}/version', [ProjectVersionController::class, 'unassignRequirement'])->whereNumber(['requirementId', 'projectId']);
        Route::get('/{id}', [RequirementController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [RequirementController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/review', [RequirementController::class, 'review'])->whereNumber('id');
        Route::post('/{id}/resubmit', [RequirementController::class, 'resubmit'])->whereNumber('id');
        Route::post('/{id}/status', [RequirementController::class, 'transition'])->whereNumber('id');
        Route::get('/{id}/execution-owner-options', [RequirementController::class, 'executionOwnerOptions'])->whereNumber('id');
        Route::get('/{id}/versions', [RequirementController::class, 'versions'])->whereNumber('id');
        Route::get('/{id}/versions/{vid}', [RequirementController::class, 'versionDetail'])->whereNumber(['id', 'vid']);
        Route::post('/{id}/tasks', [RequirementController::class, 'storeTask'])->whereNumber('id');
    });

    // ========================================================================
    // Tasks
    // ========================================================================
    Route::prefix('tasks')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::post('/', [TaskController::class, 'store']);
        Route::get('/{id}', [TaskController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [TaskController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/claim', [TaskController::class, 'claim'])->whereNumber('id');
        Route::post('/{id}/status', [TaskController::class, 'transition'])->whereNumber('id');
        Route::post('/{id}/hold', [TaskController::class, 'hold'])->whereNumber('id');
    });

    // ========================================================================
    // Defects
    // ========================================================================
    Route::prefix('defects')->group(function () {
        Route::get('/', [DefectController::class, 'index']);
        Route::post('/', [DefectController::class, 'store']);
        Route::get('/{id}', [DefectController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [DefectController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/confirm', [DefectController::class, 'confirm'])->whereNumber('id');
        Route::post('/{id}/assign', [DefectController::class, 'assign'])->whereNumber('id');
        Route::post('/{id}/resolve', [DefectController::class, 'resolve'])->whereNumber('id');
        Route::post('/{id}/verify', [DefectController::class, 'verify'])->whereNumber('id');
        Route::post('/{id}/reopen', [DefectController::class, 'reopen'])->whereNumber('id');
        Route::post('/{id}/attachments', [DefectAttachmentController::class, 'store'])->whereNumber('id');
    });

    // ========================================================================
    // Defect Attachments (standalone)
    // ========================================================================
    Route::prefix('defect-attachments')->group(function () {
        Route::get('/{id}/download', [DefectAttachmentController::class, 'download'])->whereNumber('id');
        Route::delete('/{id}', [DefectAttachmentController::class, 'destroy'])->whereNumber('id');
    });

    // ========================================================================
    // Documents (standalone)
    // ========================================================================
    Route::prefix('documents')->group(function () {
        Route::get('/{id}/download', [DocumentController::class, 'download']);
        Route::delete('/{id}', [DocumentController::class, 'destroy']);
    });

    // ========================================================================
    // API Documents (standalone)
    // ========================================================================
    Route::prefix('api-docs')->group(function () {
        Route::get('/{id}', [ApiDocumentController::class, 'show']);
        Route::put('/{id}', [ApiDocumentController::class, 'update']);
        Route::get('/{id}/versions', [ApiDocumentController::class, 'versions']);
        Route::post('/{id}/export', [ApiDocumentController::class, 'export']);
    });

    // ========================================================================
    // Organizations
    // ========================================================================
    Route::prefix('organizations')->group(function () {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::get('/{id}/children', [OrganizationController::class, 'children']);
        Route::post('/', [OrganizationController::class, 'store']);
        Route::put('/{id}', [OrganizationController::class, 'update']);
        Route::delete('/{id}', [OrganizationController::class, 'destroy']);
        Route::post('/{id}/users', [OrganizationController::class, 'addUsers']);
        Route::delete('/{id}/users/{userId}', [OrganizationController::class, 'removeUser'])->whereNumber(['id', 'userId']);
    });

    // ========================================================================
    // Audit Logs
    // ========================================================================
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/export', [AuditLogController::class, 'export']);
    });

    // ========================================================================
    // Users
    // ========================================================================
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::post('/{id}/disable', [UserController::class, 'disable']);
    });

    // ========================================================================
    // Settings (current user)
    // ========================================================================
    Route::prefix('settings')->group(function () {
        Route::put('/profile', [UserController::class, 'updateProfile']);
        Route::put('/password', [UserController::class, 'updatePassword']);
    });

    // ========================================================================
    // Notifications
    // ========================================================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [InAppNotificationController::class, 'index']);
        Route::get('/unread-count', [InAppNotificationController::class, 'unreadCount']);
        Route::post('/read-all', [InAppNotificationController::class, 'readAll']);
        Route::post('/{id}/read', [InAppNotificationController::class, 'read'])->whereNumber('id');
    });

    Route::prefix('notification-configs')->group(function () {
        Route::get('/', [NotificationController::class, 'config']);
        Route::put('/', [NotificationController::class, 'updateConfig']);
    });

    Route::get('/notification-logs', [NotificationController::class, 'logs']);
});
