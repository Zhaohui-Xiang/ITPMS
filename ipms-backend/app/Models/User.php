<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('assigned_by_id', 'assigned_at')
            ->withTimestamps();
    }

    public function organizations(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role_in_org', 'is_primary', 'assigned_at');
    }

    public function projects(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role_in_project', 'assigned_at');
    }

    public function assignedTasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function reportedDefects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Defect::class, 'reporter_id');
    }

    public function assignedDefects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Defect::class, 'assignee_id');
    }

    public function notificationConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
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
        return cache()->remember("user_{$this->id}_supplier_org_ids", 300, function () {
            $supplierOrg = $this->organizations()
                ->where('org_type', 2)
                ->first();
            if (!$supplierOrg) return [];
            return \Illuminate\Support\Facades\DB::table('organizations')
                ->withRecursiveExpression('org_tree', function ($q) use ($supplierOrg) {
                    $q->select('id')->from('organizations')->where('id', $supplierOrg->id)
                        ->unionAll(
                            \Illuminate\Support\Facades\DB::table('organizations as o')
                                ->join('org_tree as ot', 'o.parent_id', '=', 'ot.id')
                                ->select('o.id')
                        );
                })
                ->from('org_tree')->pluck('id')->toArray();
        });
    }
}
