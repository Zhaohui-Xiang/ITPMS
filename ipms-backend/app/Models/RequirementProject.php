<?php

namespace App\Models;

use App\Enums\ProjectDeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RequirementProject extends Pivot
{
    use HasFactory;

    protected $table = 'requirement_project';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'requirement_id',
        'project_id',
        'project_version_id',
        'delivery_status',
        'version_assigned_by_id',
        'version_assigned_at',
        'created_at',
    ];

    protected $casts = [
        'delivery_status' => ProjectDeliveryStatus::class,
        'version_assigned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class);
    }

    public function versionAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'version_assigned_by_id');
    }
}
