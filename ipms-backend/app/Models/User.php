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
            $supplierOrgIds = $this->organizations()
                ->where('org_type', 2)
                ->pluck('organizations.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $descendantOrgIds = array_fill_keys($supplierOrgIds, true);
            $frontier = $supplierOrgIds;

            while ($frontier !== []) {
                $children = DB::table('organizations')
                    ->whereIn('parent_id', $frontier)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                $frontier = array_values(array_filter(
                    $children,
                    static fn (int $id): bool => ! isset($descendantOrgIds[$id]),
                ));

                foreach ($frontier as $id) {
                    $descendantOrgIds[$id] = true;
                }
            }

            return array_keys($descendantOrgIds);
        });
    }
}
