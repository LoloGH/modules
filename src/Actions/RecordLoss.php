<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;

/**
 * Sortir du stock ce qui ne sera pas délivré : périmé, cassé, volé, détérioré.
 *
 * C'est une décision, pas une correction discrète. Elle porte un motif, un
 * auteur, une valeur, parce qu'une perte coûte quelque chose et doit se lire
 * dans les rapports, et, pour une destruction, le nom du témoin.
 *
 * Détruire un lot entièrement le marque comme détruit : il ne reviendra pas
 * en stock par erreur.
 */
final class RecordLoss
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{witness?: ?string, destroyed?: bool}  $details
     */
    public function handle(
        Batch $batch,
        Location $location,
        int $quantity,
        string $kind,
        string $reason,
        Authenticatable $actor,
        array $details = [],
    ): Loss {
        if (! array_key_exists($kind, Loss::kindLabels())) {
            throw new PharmacieRuleViolation('Nature de perte inconnue.');
        }

        if ($quantity <= 0) {
            throw new PharmacieRuleViolation('La quantité perdue doit être supérieure à zéro.');
        }

        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Une perte ou une destruction doit avoir un motif.');
        }

        $destroyed = (bool) ($details['destroyed'] ?? false);
        $witness = Text::clean($details['witness'] ?? null);

        if ($destroyed && $witness === null) {
            throw new PharmacieRuleViolation(
                'Une destruction se fait devant témoin : indiquez qui y a assisté.'
            );
        }

        return DB::transaction(function () use ($batch, $location, $quantity, $kind, $reason, $actor, $destroyed, $witness): Loss {
            $movement = $this->ledger->issue(
                $batch,
                $location,
                $quantity,
                $destroyed ? StockMovement::KIND_DESTRUCTION : StockMovement::KIND_LOSS,
                $actor,
                ['reason' => $reason],
            );

            $value = $quantity * (int) ($batch->purchase_price ?? 0);

            $loss = Loss::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('loss'),
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'kind' => $kind,
                'quantity' => $quantity,
                'value' => $value,
                'reason' => $reason,
                'destroyed' => $destroyed,
                'witness' => $witness,
                'recorded_by_id' => Actor::id($actor),
                'recorded_by_name' => Actor::name($actor),
            ]);

            // Un lot entièrement détruit ne doit jamais revenir en stock par
            // une manipulation distraite : on le marque.
            $fresh = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if ($destroyed && $fresh->onHand() === 0) {
                $fresh->update(['status' => Batch::STATUS_DESTROYED]);
            }

            $this->auditor->record(
                $destroyed ? 'stock_destroyed' : 'stock_lost',
                $loss,
                sprintf(
                    '%s %s : %d unité(s) du lot %s à %s (%s), motif : %s',
                    $destroyed ? 'Destruction' : 'Perte',
                    $loss->number,
                    $quantity,
                    $batch->number,
                    $location->name,
                    Money::format($value),
                    $reason,
                ),
                [],
                [
                    'batch' => $batch->number,
                    'quantity' => $quantity,
                    'kind' => $kind,
                    'value' => $value,
                    'destroyed' => $destroyed,
                    'witness' => $witness,
                    'movement' => $movement->id,
                ],
                $actor,
            );

            return $loss->refresh();
        });
    }
}
