<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'name', 'system_type', 'description', 'status',
        'manager_id', 'supplier_org_id', 'created_by_id',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function manager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function supplierOrg(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_org_id');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function requirements(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Requirement::class, 'requirement_project')
            ->withTimestamps();
    }

    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function defects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Defect::class);
    }

    public function folders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function apiDocuments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApiDocument::class);
    }
}
