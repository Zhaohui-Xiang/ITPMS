<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiDocumentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_document_id', 'version_number', 'updated_by_id',
        'updated_at', 'snapshot',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function apiDocument(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ApiDocument::class);
    }

    public function updater(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
