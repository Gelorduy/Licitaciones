<?php

namespace App\Services;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Licitacion;
use App\Models\ProposalValidation;
use App\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class WorkflowStateMachine
{
    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public function matrices(): array
    {
        return [
            Licitacion::class => [
                'draft' => ['analyzing', 'ready', 'sent_for_approval'],
                'analyzing' => ['ready', 'draft', 'sent_for_approval'],
                'ready' => ['analyzing', 'draft', 'sent_for_approval'],
                'sent_for_approval' => ['ready', 'draft', 'committed'],
                'committed' => [],
            ],
            ProposalValidation::class => [
                'draft' => ['reviewed', 'ready_for_export'],
                'reviewed' => ['reviewed', 'ready_for_export'],
                'ready_for_export' => ['reviewed', 'ready_for_export'],
            ],
        ];
    }

    /**
     * @throws InvalidStateTransitionException
     */
    public function transition(Model $model, string $toState, ?int $triggeredByUserId = null, ?string $reason = null, array $metadata = [], string $fieldName = 'status'): bool
    {
        $modelClass = $model::class;
        $fromState = (string) ($model->{$fieldName} ?? '');

        if ($fromState === $toState) {
            return false;
        }

        $matrix = $this->matrices()[$modelClass] ?? null;

        if (! $matrix) {
            throw new InvalidStateTransitionException('No workflow matrix configured for model '.$modelClass);
        }

        $allowedTargets = $matrix[$fromState] ?? [];

        if (! in_array($toState, $allowedTargets, true)) {
            throw new InvalidStateTransitionException(
                sprintf('Invalid transition for %s: %s -> %s', class_basename($modelClass), $fromState, $toState)
            );
        }

        $model->{$fieldName} = $toState;
        $model->save();

        WorkflowTransition::create([
            'entity_type' => $modelClass,
            'entity_id' => $model->getKey(),
            'field_name' => $fieldName,
            'from_state' => $fromState,
            'to_state' => $toState,
            'triggered_by_user_id' => $triggeredByUserId,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
        ]);

        return true;
    }
}
