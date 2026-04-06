<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Licitacion extends Model
{
    use HasFactory;

    protected $table = 'licitaciones';

    protected $fillable = [
        'user_id',
        'company_id',
        'company_letterhead_id',
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

    public function letterhead(): BelongsTo
    {
        return $this->belongsTo(CompanyLetterhead::class, 'company_letterhead_id');
    }

    public function regulations(): BelongsToMany
    {
        return $this->belongsToMany(Regulation::class, 'licitacion_regulation');
    }

    public function validation(): HasOne
    {
        return $this->hasOne(ProposalValidation::class);
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'sent_for_approval';
    }
}
