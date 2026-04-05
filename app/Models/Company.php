<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nombre',
        'rfc',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actas(): HasMany
    {
        return $this->hasMany(Acta::class);
    }

    public function opinionesCumplimiento(): HasMany
    {
        return $this->hasMany(OpinionCumplimiento::class);
    }

    public function financialStatements(): HasMany
    {
        return $this->hasMany(FinancialStatement::class);
    }

    public function taxDeclarations(): HasMany
    {
        return $this->hasMany(TaxDeclaration::class);
    }

    public function licitaciones(): HasMany
    {
        return $this->hasMany(Licitacion::class);
    }
}