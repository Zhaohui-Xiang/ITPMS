<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVersionReleaseSnapshot extends Model
{
    private static int $releaseCreationDepth = 0;

    protected static function booted(): void
    {
        static::creating(static function (): void {
            if (self::$releaseCreationDepth === 0) {
                throw new \LogicException(
                    'Project version release snapshots may only be created by the release service.',
                );
            }
        });

        $rejectMutation = static function (): never {
            throw new \LogicException('Project version release snapshots are immutable.');
        };

        static::updating($rejectMutation);
        static::deleting($rejectMutation);
    }

    public $timestamps = false;

    protected $fillable = [
        'project_version_id',
        'requirement_scope',
        'task_count',
        'defect_count',
        'gate_result',
        'release_notes',
        'is_override',
        'override_reason',
        'released_by_id',
        'released_at',
    ];

    protected $casts = [
        'requirement_scope' => 'array',
        'task_count' => 'integer',
        'defect_count' => 'integer',
        'gate_result' => 'array',
        'is_override' => 'boolean',
        'released_at' => 'datetime',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createForRelease(array $attributes): self
    {
        self::$releaseCreationDepth++;

        try {
            /** @var self $snapshot */
            $snapshot = self::query()->create($attributes);

            return $snapshot;
        } finally {
            self::$releaseCreationDepth--;
        }
    }

    public function projectVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }
}
