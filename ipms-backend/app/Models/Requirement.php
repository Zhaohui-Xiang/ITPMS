<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'priority', 'requirement_type',
        'submitter_id', 'submitted_at', 'expected_completion_date',
        'status', 'reviewer_id', 'review_comment', 'reviewed_at',
        'dev_lead_id', 'version', 'created_by_id', 'updated_by_id',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expected_completion_date' => 'date',
    ];

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function devLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dev_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'requirement_project')
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

    public function projectLinks(): HasMany
    {
        return $this->hasMany(RequirementProject::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RequirementVersion::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequirementAttachment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class);
    }

    public function apiDocuments(): HasMany
    {
        return $this->hasMany(ApiDocument::class);
    }
}
