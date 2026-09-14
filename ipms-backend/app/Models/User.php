<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
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

    public function hasPermission(string $code): bool
    {
        return cache()->remember("user_{$this->id}_perm_{$code}", 300, function () use ($code) {
            return $this->roles()
                ->whereHas('permissions', fn ($q) => $q->where('code', $code))
                ->exists();
        });
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
}
