<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Fixe ou change le tarif d'un acte pour un contexte (`kind`).
 *
 * Règle centrale : à un instant donné, un acte n'a qu'UN tarif actif par
 * contexte. Changer un prix ne modifie jamais la ligne existante, on la
 * désactive et on en crée une nouvelle, pour que l'historique financier
 * reste lisible et qu'une facture passée garde son explication.
 */
final class SetTariff
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(
        Act $act,
        int $amount,
        string $kind = Tariff::KIND_STANDARD,
        ?string $label = null,
        ?string $effectiveFrom = null,
        ?Authenticatable $actor = null,
    ): Tariff {
        if ($amount < 0) {
            throw new FinanceRuleViolation('Un tarif ne peut pas être négatif.');
        }

        $kind = Text::clean($kind) ?? Tariff::KIND_STANDARD;
        $label = Text::clean($label);
        $date = $this->date($effectiveFrom);

        return DB::transaction(function () use ($act, $amount, $kind, $label, $date, $actor): Tariff {
            // Verrou sur l'acte : deux changements de prix simultanés se
            // succèdent au lieu de laisser deux tarifs actifs derrière eux.
            $act = Act::query()->whereKey($act->getKey())->lockForUpdate()->firstOrFail();

            if (! $act->is_active) {
                throw new FinanceRuleViolation("L'acte « {$act->name} » est désactivé : réactivez-le avant de fixer son tarif.");
            }

            $current = $act->tariffs()
                ->where('kind', $kind)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($current !== null && (int) $current->amount === $amount && $current->label === $label) {
                throw new FinanceRuleViolation(
                    sprintf('Le tarif « %s » de %s est déjà à %s.', $kind, $act->name, Money::format($amount))
                );
            }

            $current?->update(['is_active' => false]);

            $tariff = Tariff::create([
                'act_id' => $act->id,
                'kind' => $kind,
                'label' => $label,
                'amount' => $amount,
                'effective_from' => $date,
                'is_active' => true,
            ]);

            $this->auditor->record(
                $current === null ? 'tariff_set' : 'tariff_changed',
                $tariff,
                $current === null
                    ? sprintf('Tarif « %s » de %s fixé à %s', $kind, $act->name, Money::format($amount))
                    : sprintf('Tarif « %s » de %s : %s -> %s', $kind, $act->name, Money::format((int) $current->amount), Money::format($amount)),
                $current === null ? [] : ['amount' => (int) $current->amount, 'tariff_id' => $current->id],
                ['act' => $act->code, 'kind' => $kind, 'amount' => $amount, 'effective_from' => $date->toDateString()],
                $actor,
            );

            return $tariff;
        });
    }

    /**
     * Aujourd'hui par défaut ; une date illisible est refusée plutôt
     * qu'interprétée.
     */
    private function date(?string $effectiveFrom): Carbon
    {
        $effectiveFrom = Text::clean($effectiveFrom);

        if ($effectiveFrom === null) {
            return Carbon::today();
        }

        try {
            return Carbon::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable) {
            throw new FinanceRuleViolation("La date d'application « {$effectiveFrom} » n'est pas une date valide.");
        }
    }
}
