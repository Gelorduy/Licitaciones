<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Licitacion extends Model
{
    use HasFactory;

    protected $table = 'licitaciones';

    protected $fillable = [
        'user_id',
        'company_id',
        'title',
        'process_type',
        'legal_signer_name',
        'status',
        'bases_document_path',
        'bases_document_original_name',
        'checklist',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function regulations(): BelongsToMany
    {
        return $this->belongsToMany(Regulation::class, 'licitacion_regulation');
    }
}
