<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequirementVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'requirement_id', 'version_number', 'changed_by_id',
        'changed_at', 'changes', 'change_summary',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'changes' => 'array',
    ];

    public function requirement(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function changedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
