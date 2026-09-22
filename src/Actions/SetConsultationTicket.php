<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;

/**
 * Désigne (ou retire) l'acte « ticket de consultation » : celui que l'hôte
 * encaisse à l'accueil, via `CatalogProvider::ticketAct()`.
 *
 * Règle centrale : un seul acte le porte à la fois. Marquer un acte retire
 * la marque de l'ancien, dans la même transaction et sous verrou, pour que
 * deux administrateurs simultanés ne laissent jamais deux tickets derrière eux.
 * Reposer l'état déjà en place ne fait rien et n'écrit rien au journal.
 */
final class SetConsultationTicket
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Act $act, bool $isTicket, ?Authenticatable $actor = null): Act
    {
        return DB::transaction(function () use ($act, $isTicket, $actor): Act {
            // Le ticket en place d'abord, puis l'acte visé : tous les
            // marquages prennent les verrous dans le même ordre et se
            // succèdent au lieu de se croiser.
            $previous = Act::query()
                ->where('is_consultation_ticket', true)
                ->whereKeyNot($act->getKey())
                ->lockForUpdate()
                ->get();

            $act = Act::query()->whereKey($act->getKey())->lockForUpdate()->firstOrFail();

            if ((bool) $act->is_consultation_ticket === $isTicket) {
                return $act;
            }

            if (! $isTicket) {
                $act->update(['is_consultation_ticket' => false]);

                $this->auditor->record(
                    'consultation_ticket_unset',
                    $act,
                    "L'acte « {$act->name} » n'est plus le ticket de consultation",
                    ['is_consultation_ticket' => true],
                    ['is_consultation_ticket' => false],
                    $actor,
                );

                return $act;
            }

            if (! $act->is_active) {
                throw new FinanceRuleViolation(
                    "L'acte « {$act->name} » est désactivé : réactivez-le avant d'en faire le ticket de consultation."
                );
            }

            foreach ($previous as $old) {
                $old->update(['is_consultation_ticket' => false]);
            }

            $act->update(['is_consultation_ticket' => true]);

            $this->auditor->record(
                'consultation_ticket_set',
                $act,
                $previous->isEmpty()
                    ? "L'acte « {$act->name} » devient le ticket de consultation"
                    : sprintf(
                        "L'acte « %s » remplace « %s » comme ticket de consultation",
                        $act->name,
                        $previous->pluck('name')->implode(' », « '),
                    ),
                ['ticket_act_ids' => $previous->pluck('id')->all()],
                ['is_consultation_ticket' => true, 'act' => $act->code],
                $actor,
            );

            return $act;
        });
    }
}
