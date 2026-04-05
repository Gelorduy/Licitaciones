<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Acta extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'tipo',
        'fecha_registro',
        'apoderados',
        'participacion_accionaria',
        'rpc_folio',
        'rpc_fecha_inscripcion',
        'rpc_lugar',
        'consejo_administracion',
        'direccion_empresa',
        'notaria_numero',
        'notaria_lugar',
        'notario_nombre',
        'escritura_numero',
        'libro_numero',
        'fecha_inscripcion',
        'acto',
        'documento_path',
        'documento_original_name',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'date',
            'rpc_fecha_inscripcion' => 'date',
            'fecha_inscripcion' => 'date',
            'apoderados' => 'array',
            'participacion_accionaria' => 'array',
            'consejo_administracion' => 'array',
            'direccion_empresa' => 'array',
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