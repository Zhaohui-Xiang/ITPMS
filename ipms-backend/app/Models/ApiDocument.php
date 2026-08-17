<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'folder_id', 'api_name', 'request_path',
        'request_method', 'auth_type', 'request_params',
        'response_params', 'rich_text_body', 'requirement_id',
        'version', 'updated_by_id',
    ];

    protected $casts = [
        'request_params' => 'array',
        'response_params' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function folder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function requirement(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function updater(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApiDocumentVersion::class);
    }
}
