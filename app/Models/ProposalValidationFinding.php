<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalValidationFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_validation_id',
        'severity',
        'category',
        'rule_code',
        'message',
        'status',
        'resolution_note',
        'owner_user_id',
    ];

    public function validation(): BelongsTo
    {
        return $this->belongsTo(ProposalValidation::class, 'proposal_validation_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
