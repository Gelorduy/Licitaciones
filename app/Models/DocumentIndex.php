<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentIndex extends Model
{
    use HasFactory;

    protected $table = 'document_indexes';

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'user_id',
        'document_type',
        'storage_disk',
        'storage_path',
        'extraction_method',
        'extracted_text',
        'metadata',
        'chunk_count',
        'indexed_at',
        'status',
        'error_message',
        'vector_index_error',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
