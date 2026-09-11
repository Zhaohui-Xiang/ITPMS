<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
    protected $table = 'project_members';

    public $timestamps = false;

    protected $fillable = ['project_id', 'user_id', 'role_in_project', 'assigned_by_id', 'assigned_at'];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
