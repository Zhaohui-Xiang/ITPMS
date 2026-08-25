<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RequirementController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\DefectController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ApiDocumentController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DashboardController;

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
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ========================================================================
    // Dashboard
    // ========================================================================
    Route::prefix('dashboard')->group(function () {
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
        Route::get('/{id}', [ProjectController::class, 'show']);
        Route::put('/{id}', [ProjectController::class, 'update']);
        Route::delete('/{id}', [ProjectController::class, 'destroy']);
        Route::post('/{id}/archive', [ProjectController::class, 'archive']);

        // Documents (scoped under project)
        Route::get('/{projectId}/documents', [DocumentController::class, 'index']);
        Route::post('/{projectId}/documents/upload', [DocumentController::class, 'upload']);
        Route::post('/{projectId}/documents/folder', [DocumentController::class, 'createFolder']);

        // API Documents (scoped under project)
        Route::get('/{projectId}/api-docs', [ApiDocumentController::class, 'index']);
        Route::post('/{projectId}/api-docs', [ApiDocumentController::class, 'store']);
    });

    // ========================================================================
    // Requirements
    // ========================================================================
    Route::prefix('requirements')->group(function () {
        Route::get('/', [RequirementController::class, 'index']);
        Route::post('/', [RequirementController::class, 'store']);
        Route::get('/{id}', [RequirementController::class, 'show']);
        Route::put('/{id}', [RequirementController::class, 'update']);
        Route::post('/{id}/review', [RequirementController::class, 'review']);
        Route::post('/{id}/resubmit', [RequirementController::class, 'resubmit']);
        Route::post('/{id}/status', [RequirementController::class, 'transition']);
        Route::get('/{id}/versions', [RequirementController::class, 'versions']);
        Route::get('/{id}/versions/{vid}', [RequirementController::class, 'versionDetail']);
        Route::post('/{id}/tasks', [RequirementController::class, 'storeTask']);
    });

    // ========================================================================
    // Tasks
    // ========================================================================
    Route::prefix('tasks')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::post('/', [TaskController::class, 'store']);
        Route::get('/{id}', [TaskController::class, 'show']);
        Route::put('/{id}', [TaskController::class, 'update']);
        Route::post('/{id}/claim', [TaskController::class, 'claim']);
        Route::post('/{id}/status', [TaskController::class, 'transition']);
        Route::post('/{id}/hold', [TaskController::class, 'hold']);
    });

    // ========================================================================
    // Defects
    // ========================================================================
    Route::prefix('defects')->group(function () {
        Route::get('/', [DefectController::class, 'index']);
        Route::post('/', [DefectController::class, 'store']);
        Route::get('/{id}', [DefectController::class, 'show']);
        Route::put('/{id}', [DefectController::class, 'update']);
        Route::post('/{id}/confirm', [DefectController::class, 'confirm']);
        Route::post('/{id}/assign', [DefectController::class, 'assign']);
        Route::post('/{id}/resolve', [DefectController::class, 'resolve']);
        Route::post('/{id}/verify', [DefectController::class, 'verify']);
        Route::post('/{id}/reopen', [DefectController::class, 'reopen']);
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
    Route::prefix('notification-configs')->group(function () {
        Route::get('/', [NotificationController::class, 'config']);
        Route::put('/', [NotificationController::class, 'updateConfig']);
    });

    Route::get('/notification-logs', [NotificationController::class, 'logs']);
});
