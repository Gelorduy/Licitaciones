<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class OpinionCumplimiento extends Model
{
    use HasFactory;

    protected $table = 'opinion_cumplimientos';

    protected $fillable = [
        'company_id',
        'tipo',
        'estado',
        'fecha_emision',
        'fecha_vigencia',
        'documento_path',
        'documento_original_name',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_vigencia' => 'date',
            'vigencia_calculada' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentIndex(): MorphOne
    {
        return $this->morphOne(DocumentIndex::class, 'documentable');
    }
}