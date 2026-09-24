<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationBatch;
use Keneya\Pharmacie\Models\Recall;
use Keneya\Pharmacie\Models\RecallPatient;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Rappeler un lot : l'empêcher de sortir, et remonter jusqu'aux patients.
 *
 * L'ouverture d'un rappel fait deux choses d'un coup :
 *
 *   1. le lot est **bloqué** — le grand livre refusera désormais de le
 *      délivrer, sans qu'aucun écran n'ait besoin d'y penser ;
 *   2. la liste des patients servis de ce lot est **figée**, à partir du
 *      détail par lot des dispensations.
 *
 * Elle ne détruit rien : ce qui reste en stock est mis de côté, pas effacé —
 * le fournisseur voudra peut-être le reprendre, et la destruction a son
 * propre écran, avec témoin.
 *
 * Un rappel qui vise les patients ne se clôt pas tant qu'il reste quelqu'un
 * à joindre : sinon, « rappel clos » ne voudrait rien dire.
 */
final class RecallBatch
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{origin?: string, reference?: ?string, level?: string, reason?: ?string}  $details
     */
    public function open(Batch $batch, array $details, Authenticatable $actor): Recall
    {
        $reason = Text::clean($details['reason'] ?? null);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Un rappel de lot doit dire pourquoi : le motif sera lu par ceux qui appellent les patients.');
        }

        $level = $details['level'] ?? Recall::LEVEL_STOCK;

        if (! array_key_exists($level, Recall::levelLabels())) {
            throw new PharmacieRuleViolation('Niveau de rappel inconnu.');
        }

        $origin = $details['origin'] ?? Recall::ORIGIN_INTERNAL;

        if (! array_key_exists($origin, Recall::originLabels())) {
            throw new PharmacieRuleViolation('Origine de rappel inconnue.');
        }

        return DB::transaction(function () use ($batch, $reason, $level, $origin, $details, $actor): Recall {
            $fresh = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            $running = Recall::query()->ofFacility()
                ->where('batch_id', $fresh->id)
                ->where('status', Recall::STATUS_OPEN)
                ->first();

            if ($running !== null) {
                throw new PharmacieRuleViolation(
                    "Le lot {$fresh->number} fait déjà l'objet du rappel {$running->number} : suivez celui-là."
                );
            }

            $recall = Recall::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('recall'),
                'batch_id' => $fresh->id,
                'origin' => $origin,
                'reference' => Text::clean($details['reference'] ?? null),
                'level' => $level,
                'reason' => $reason,
                'quantity_blocked' => $fresh->onHand(),
                'status' => Recall::STATUS_OPEN,
                'opened_by_id' => Actor::id($actor),
                'opened_by_name' => Actor::name($actor),
                'opened_at' => now(),
            ]);

            // Le blocage passe par le statut du lot : c'est le seul endroit
            // que le grand livre consulte avant de laisser sortir quoi que
            // ce soit. Un lot déjà détruit le reste.
            if ($fresh->status !== Batch::STATUS_DESTROYED) {
                $fresh->update(['status' => Batch::STATUS_BLOCKED]);
            }

            $patients = $this->servedPatients($fresh);

            foreach ($patients as $row) {
                RecallPatient::create($row + ['recall_id' => $recall->id]);
            }

            $this->auditor->record(
                'batch_recalled',
                $recall,
                sprintf(
                    'Rappel %s du lot %s (%s) : %s — %d unité(s) bloquée(s), %d patient(s) concerné(s)',
                    $recall->number,
                    $fresh->number,
                    $fresh->product?->label() ?? 'produit inconnu',
                    $reason,
                    (int) $recall->quantity_blocked,
                    count($patients),
                ),
                ['batch_status' => $batch->status],
                ['batch_status' => Batch::STATUS_BLOCKED, 'level' => $level, 'patients' => count($patients)],
                $actor,
            );

            return $recall->refresh();
        });
    }

    /**
     * Noter qu'un patient a été joint. Sans note, on ne saurait pas ce qui
     * lui a été dit ni ce qu'il a répondu.
     */
    public function contact(RecallPatient $line, ?string $note, Authenticatable $actor): RecallPatient
    {
        $note = Text::clean($note);

        if ($note === null) {
            throw new PharmacieRuleViolation('Dites ce que le patient a répondu : un appel sans trace ne vaut pas un appel.');
        }

        if ((bool) $line->contacted) {
            throw new PharmacieRuleViolation("{$line->label()} a déjà été joint le ".$line->contacted_at?->format('d/m/Y').'.');
        }

        $line->update([
            'contacted' => true,
            'contacted_at' => now(),
            'contacted_by_name' => Actor::name($actor),
            'contact_note' => $note,
        ]);

        $this->auditor->record(
            'recall_patient_contacted',
            $line->recall,
            sprintf('Rappel %s : %s joint — %s', $line->recall?->number, $line->label(), $note),
            [],
            ['patient_id' => $line->patient_id, 'note' => $note],
            $actor,
        );

        return $line->refresh();
    }

    public function close(Recall $recall, ?string $note, Authenticatable $actor): Recall
    {
        $note = Text::clean($note);

        if ($note === null) {
            throw new PharmacieRuleViolation('Clore un rappel demande de dire ce qu\'il est advenu du lot.');
        }

        return DB::transaction(function () use ($recall, $note, $actor): Recall {
            $fresh = Recall::query()->whereKey($recall->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw new PharmacieRuleViolation("Le rappel {$fresh->number} est déjà clos.");
            }

            // Un rappel qui vise les patients ne se clôt pas en laissant
            // quelqu'un sans nouvelle : c'est tout son objet.
            if ($fresh->reachesPatients() && ($remaining = $fresh->remainingToContact()) > 0) {
                throw new PharmacieRuleViolation(sprintf(
                    '%d patient(s) n\'ont pas encore été joints : un rappel ne se clôt pas avant eux.',
                    $remaining,
                ));
            }

            $fresh->update([
                'status' => Recall::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_name' => Actor::name($actor),
                'closing_note' => $note,
            ]);

            $this->auditor->record(
                'recall_closed',
                $fresh,
                sprintf('Rappel %s clos : %s', $fresh->number, $note),
                ['status' => Recall::STATUS_OPEN],
                ['status' => Recall::STATUS_CLOSED, 'note' => $note],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * Les patients servis de ce lot, lus du détail par lot des
     * dispensations. Les dispensations annulées sont écartées : le produit
     * est revenu, il n'est parti dans aucune main.
     *
     * @return list<array<string, mixed>>
     */
    private function servedPatients(Batch $batch): array
    {
        $rows = DispensationBatch::query()
            ->where('batch_id', $batch->getKey())
            ->with(['item.dispensation'])
            ->get();

        $patients = [];

        foreach ($rows as $row) {
            $dispensation = $row->item?->dispensation;

            if ($dispensation === null || $dispensation->status === Dispensation::STATUS_CANCELLED) {
                continue;
            }

            $patients[] = [
                'dispensation_id' => $dispensation->id,
                'patient_id' => $dispensation->patient_id,
                'patient_name' => $dispensation->patient_name,
                'product_label' => $row->item?->label ?? ($batch->product?->label() ?? ''),
                'quantity' => (int) $row->quantity,
                'dispensed_at' => $dispensation->dispensed_at,
            ];
        }

        return $patients;
    }
}
