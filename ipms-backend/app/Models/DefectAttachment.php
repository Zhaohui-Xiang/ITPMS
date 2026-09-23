<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DefectAttachment extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'defect_id', 'file', 'filename', 'file_size',
        'file_type', 'uploaded_by_id', 'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(Defect::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
