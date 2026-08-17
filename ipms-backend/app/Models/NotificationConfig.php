<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationConfig extends Model
{
    protected $table = 'notification_configs';

    protected $fillable = ['user_id', 'remind_enabled', 'remind_days_before'];

    protected $casts = [
        'remind_enabled' => 'boolean',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
