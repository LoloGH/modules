<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Trancher une demande : approuver, ou refuser avec un motif.
 */
final class DecisionRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'refuse'])],
            // Un refus s'explique ; une approbation n'a rien à ajouter.
            'reason' => ['required_if:decision,refuse', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
