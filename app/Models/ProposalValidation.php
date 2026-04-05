<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalValidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'licitacion_id',
        'status',
        'traffic_light',
        'report',
        'override_applied',
        'override_reason',
        'audited_at',
        'ready_at',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'override_applied' => 'boolean',
            'audited_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function licitacion(): BelongsTo
    {
        return $this->belongsTo(Licitacion::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ProposalValidationFinding::class)->orderBy('id');
    }
}
