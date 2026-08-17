<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Defect extends Model
{
    use HasFactory;

    protected $fillable = [
        'requirement_id', 'project_id', 'title', 'description',
        'severity', 'defect_type', 'reporter_id', 'discovered_at',
        'discovery_phase', 'assignee_id', 'status', 'screenshot',
        'fix_description', 'closed_at', 'created_by_id',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
