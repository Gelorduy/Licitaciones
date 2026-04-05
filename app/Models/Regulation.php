<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Regulation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'country_code',
        'scope',
        'regulatory_body',
        'general_description',
        'source_pdf_path',
        'source_pdf_original_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentIndex(): MorphOne
    {
        return $this->morphOne(DocumentIndex::class, 'documentable');
    }

    public function licitaciones(): BelongsToMany
    {
        return $this->belongsToMany(Licitacion::class, 'licitacion_regulation');
    }
}