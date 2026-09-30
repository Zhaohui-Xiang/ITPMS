<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use SoftDeletes;

    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username', 'password', 'first_name', 'last_name', 'email',
        'user_type', 'phone', 'display_name', 'is_active', 'is_staff',
        'is_disabled', 'must_change_password', 'created_by_id',
        'last_login', 'date_joined',
    ];

    protected $hidden = [
        'password',
        'seed_marker',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_staff' => 'boolean',
        'is_disabled' => 'boolean',
        'must_change_password' => 'boolean',
        'last_login' => 'datetime',
        'date_joined' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('assigned_by_id', 'assigned_at');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role_in_org', 'is_primary', 'assigned_at');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role_in_project', 'assigned_at');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function reportedDefects(): HasMany
    {
        return $this->hasMany(Defect::class, 'reporter_id');
    }

    public function assignedDefects(): HasMany
    {
        return $this->hasMany(Defect::class, 'assignee_id');
    }

    public function inAppNotifications(): HasMany
    {
        return $this->hasMany(InAppNotification::class);
    }

    public function notificationConfig(): HasOne
    {
        return $this->hasOne(NotificationConfig::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()->where('code', 'super_admin')->exists();
    }

    /**
     * 有效超管（v1.8 §2.10 唯一判定）：运行时授权、最后超管统计、会话检查共同调用。
     */
    public function isEffectiveSuperAdmin(): bool
    {
        return $this->isSuperAdmin() && $this->is_active && ! $this->is_disabled;
    }

    public function hasPermission(string $code): bool
    {
        // v1.8 §2.2：委托 PermissionResolver（无配置规则时行为与 permission_role 现状一致）
        return app(\App\Services\Permissions\PermissionResolver::class)->allows($this, $code);
    }

    public function getSupplierDescendantOrgIds(): array
    {
        return cache()->remember("user_{$this->id}_supplier_org_ids", 300, function (): array {
            $rows = DB::select(
                <<<'SQL'
                    WITH RECURSIVE supplier_ancestors AS (
                        SELECT organization.id, organization.parent_id
                        FROM organizations AS organization
                        INNER JOIN organization_user AS membership
                            ON membership.organization_id = organization.id
                        WHERE membership.user_id = :user_id
                            AND organization.org_type = 2

                        UNION

                        SELECT parent.id, parent.parent_id
                        FROM organizations AS parent
                        INNER JOIN supplier_ancestors AS child
                            ON child.parent_id = parent.id
                        WHERE parent.org_type = 2
                    ),
                    supplier_roots AS (
                        SELECT DISTINCT ancestor.id, ancestor.parent_id
                        FROM supplier_ancestors AS ancestor
                        LEFT JOIN organizations AS parent
                            ON parent.id = ancestor.parent_id
                            AND parent.org_type = 2
                        WHERE parent.id IS NULL
                    ),
                    supplier_tree AS (
                        SELECT root.id, root.parent_id
                        FROM supplier_roots AS root

                        UNION

                        SELECT child.id, child.parent_id
                        FROM organizations AS child
                        INNER JOIN supplier_tree AS parent
                            ON child.parent_id = parent.id
                        WHERE child.org_type = 2
                    )
                    SELECT DISTINCT id
                    FROM supplier_tree
                    ORDER BY id
                SQL,
                ['user_id' => $this->id],
            );

            return array_map(
                static fn (object $row): int => (int) $row->id,
                $rows,
            );
        });
    }

    /**
     * 软删除对齐原硬删除的 FK 语义：SET NULL 字段置空、CASCADE 关系表清除。
     * RESTRICT 引用（需求提交人、项目负责人、审计日志等）保持可追溯。
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->isForceDeleting()) {
                return;
            }
            $id = $user->id;
            // SET NULL 语义
            DB::table('tasks')->where('assignee_id', $id)->update(['assignee_id' => null]);
            DB::table('defects')->where('assignee_id', $id)->update(['assignee_id' => null]);
            DB::table('requirements')->where('reviewer_id', $id)->update(['reviewer_id' => null]);
            DB::table('requirements')->where('dev_lead_id', $id)->update(['dev_lead_id' => null]);
            DB::table('requirements')->where('updated_by_id', $id)->update(['updated_by_id' => null]);
            // requirement_project 受版本门禁写保护：在授权写上下文中置空
            DB::transaction(function () use ($id): void {
                $linkIds = DB::table('requirement_project')
                    ->where('version_assigned_by_id', $id)
                    ->pluck('id')
                    ->map(fn ($linkId) => (int) $linkId)
                    ->sort()->values()->all();
                if ($linkIds === []) {
                    return;
                }
                DB::select(
                    "SELECT set_config('itpms.requirement_project_write_ids', ?, true)",
                    [json_encode($linkIds, JSON_THROW_ON_ERROR)],
                );
                DB::table('requirement_project')
                    ->where('version_assigned_by_id', $id)
                    ->update(['version_assigned_by_id' => null]);
            });
            DB::table('project_versions')->where('released_by_id', $id)->update(['released_by_id' => null]);
            DB::table('api_documents')->where('updated_by_id', $id)->update(['updated_by_id' => null]);
            DB::table('documents')->where('deleted_by_id', $id)->update(['deleted_by_id' => null]);
            DB::table('users')->where('created_by_id', $id)->update(['created_by_id' => null]);
            DB::table('role_user')->where('assigned_by_id', $id)->update(['assigned_by_id' => null]);
            DB::table('project_members')->where('assigned_by_id', $id)->update(['assigned_by_id' => null]);
            DB::table('permission_rules')->where('granted_by_id', $id)->update(['granted_by_id' => null]);
            DB::table('field_permissions')->where('granted_by_id', $id)->update(['granted_by_id' => null]);
            DB::table('resource_auth_builds')->where('activated_by', $id)->update(['activated_by' => null]);
            // CASCADE 语义
            DB::table('role_user')->where('user_id', $id)->delete();
            DB::table('organization_user')->where('user_id', $id)->delete();
            DB::table('project_members')->where('user_id', $id)->delete();
            DB::table('in_app_notifications')->where('user_id', $id)->delete();
            DB::table('notification_configs')->where('user_id', $id)->delete();
            DB::table('permission_rules')->where('user_id', $id)->delete();
            DB::table('data_scopes')->where('user_id', $id)->delete();
            DB::table('field_permissions')->where('user_id', $id)->delete();
            DB::table('superadmin_recovery_credentials')->where('target_user_id', $id)->delete();
        });
    }
}
