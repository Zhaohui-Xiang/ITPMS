<?php

namespace App\Models;

use App\Enums\ProjectVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'code',
        'name',
        'description',
        'status',
        'owner_id',
        'planned_start_date',
        'planned_release_date',
        'released_at',
        'released_by_id',
        'release_notes',
        'lock_version',
        'created_by_id',
    ];

    protected $casts = [
        'status' => ProjectVersionStatus::class,
        'planned_start_date' => 'date',
        'planned_release_date' => 'date',
        'released_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }

    public function requirementLinks(): HasMany
    {
        return $this->hasMany(RequirementProject::class);
    }

    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(
            Requirement::class,
            'requirement_project',
            'project_version_id',
            'requirement_id',
        )->using(RequirementProject::class)
            ->withPivot([
                'id',
                'project_id',
                'delivery_status',
                'version_assigned_by_id',
                'version_assigned_at',
                'created_at',
            ]);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ProjectVersionHistory::class);
    }

    public function releaseSnapshot(): HasOne
    {
        return $this->hasOne(ProjectVersionReleaseSnapshot::class);
    }
}
