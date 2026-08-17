<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'system_type', 'description', 'status',
        'manager_id', 'supplier_org_id', 'created_by_id',
    ];

    protected $hidden = [
        'seed_marker',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function supplierOrg(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(Requirement::class, 'requirement_project')
            ->using(RequirementProject::class)
            ->withPivot([
                'id',
                'project_version_id',
                'delivery_status',
                'version_assigned_by_id',
                'version_assigned_at',
                'created_at',
            ]);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function apiDocuments(): HasMany
    {
        return $this->hasMany(ApiDocument::class);
    }
}
