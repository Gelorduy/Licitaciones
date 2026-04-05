<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'periodicity',
        'year',
        'month',
        'format',
        'document_path',
        'document_original_name',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
