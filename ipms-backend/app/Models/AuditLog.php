<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'user_display_name', 'user_type',
        'module', 'action_type', 'target_type', 'target_id',
        'target_name', 'detail', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'detail' => 'array',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
