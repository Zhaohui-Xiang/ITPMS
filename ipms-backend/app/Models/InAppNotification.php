<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class InAppNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'event_code', 'dedup_key', 'title', 'body',
        'target_type', 'target_id', 'target_url', 'payload', 'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'read_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function setTargetUrlAttribute(?string $value): void
    {
        if ($value !== null && (! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\\x00-\\x20]/', $value))) {
            throw new InvalidArgumentException('Notification links must be relative application paths.');
        }
        $this->attributes['target_url'] = $value;
    }
}
