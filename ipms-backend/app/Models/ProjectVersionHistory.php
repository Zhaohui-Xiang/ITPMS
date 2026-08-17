<?php

namespace App\Models;

use App\Enums\ProjectVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVersionHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_version_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'from_status' => ProjectVersionStatus::class,
        'to_status' => ProjectVersionStatus::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function projectVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
