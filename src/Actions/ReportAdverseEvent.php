<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\AdverseEvent;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Signaler un effet indésirable, et suivre ce signalement jusqu'au bout.
 *
 * Le signalement se fait avec ce qu'on a : parfois le lot exact, parfois
 * seulement le produit. On ne bloque donc pas sur le lot, un signalement
 * imparfait vaut mieux qu'un signalement jamais fait. En revanche, ce qui a
 * été observé ne peut pas rester vide.
 *
 * Quand une dispensation est désignée, le patient et le lot en sont déduits :
 * c'est le chemin le plus sûr, et celui qui relie ensuite le signalement à un
 * rappel éventuel.
 */
final class ReportAdverseEvent
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{dispensation_id?: ?int, product_id?: ?int, batch_id?: ?int, patient_id?: ?string, patient_name?: ?string, description?: ?string, severity?: string, started_on?: ?string, outcome?: string, action_taken?: ?string}  $details
     */
    public function report(array $details, Authenticatable $actor): AdverseEvent
    {
        $description = Text::clean($details['description'] ?? null);

        if ($description === null) {
            throw new PharmacieRuleViolation('Décrivez ce qui a été observé : c\'est la seule chose qu\'un signalement ne peut pas omettre.');
        }

        $severity = $details['severity'] ?? AdverseEvent::SEVERITY_MODERATE;

        if (! array_key_exists($severity, AdverseEvent::severityLabels())) {
            throw new PharmacieRuleViolation('Gravité inconnue.');
        }

        $outcome = $details['outcome'] ?? AdverseEvent::OUTCOME_UNKNOWN;

        if (! array_key_exists($outcome, AdverseEvent::outcomeLabels())) {
            throw new PharmacieRuleViolation('Évolution inconnue.');
        }

        $dispensation = isset($details['dispensation_id']) && $details['dispensation_id'] !== null
            ? Dispensation::query()->find((int) $details['dispensation_id'])
            : null;

        $batch = isset($details['batch_id']) && $details['batch_id'] !== null
            ? Batch::query()->find((int) $details['batch_id'])
            : null;

        $product = isset($details['product_id']) && $details['product_id'] !== null
            ? Product::query()->find((int) $details['product_id'])
            : $batch?->product;

        if ($product === null && $batch === null) {
            throw new PharmacieRuleViolation('Désignez au moins le médicament soupçonné : sans lui, le signalement ne mène nulle part.');
        }

        $event = AdverseEvent::create([
            'facility_id' => Facility::current(),
            'number' => $this->numbers->next('adverse_event'),
            'patient_id' => Text::clean($details['patient_id'] ?? null) ?? $dispensation?->patient_id,
            'patient_name' => Text::clean($details['patient_name'] ?? null) ?? $dispensation?->patient_name,
            'product_id' => $product?->id ?? $batch?->product_id,
            'batch_id' => $batch?->id,
            'dispensation_id' => $dispensation?->id,
            'description' => $description,
            'severity' => $severity,
            'started_on' => Text::clean($details['started_on'] ?? null),
            'outcome' => $outcome,
            'action_taken' => Text::clean($details['action_taken'] ?? null),
            'status' => AdverseEvent::STATUS_NEW,
            'reported_by_id' => Actor::id($actor),
            'reported_by_name' => Actor::name($actor),
            'reported_at' => now(),
        ]);

        $this->auditor->record(
            'adverse_event_reported',
            $event,
            sprintf(
                'Signalement %s (%s) : %s pour %s%s',
                $event->number,
                $event->severityLabel(),
                $event->product?->label() ?? 'médicament non désigné',
                $event->patientLabel(),
                $batch !== null ? ', lot '.$batch->number : '',
            ),
            [],
            [
                'severity' => $severity,
                'product_id' => $event->product_id,
                'batch_id' => $event->batch_id,
                'dispensation' => $dispensation?->number,
            ],
            $actor,
        );

        return $event->refresh();
    }

    /**
     * Transmettre à qui de droit (centre de pharmacovigilance, autorité) :
     * le module n'envoie rien lui-même, il enregistre à qui et quand.
     */
    public function transmit(AdverseEvent $event, ?string $to, Authenticatable $actor): AdverseEvent
    {
        $to = Text::clean($to);

        if ($to === null) {
            throw new PharmacieRuleViolation('Indiquez à qui le signalement a été transmis.');
        }

        if ($event->isClosed()) {
            throw new PharmacieRuleViolation("Le signalement {$event->number} est clos.");
        }

        $event->update([
            'status' => AdverseEvent::STATUS_TRANSMITTED,
            'transmitted_at' => now(),
            'transmitted_to' => $to,
        ]);

        $this->auditor->record(
            'adverse_event_transmitted',
            $event,
            sprintf('Signalement %s transmis à %s', $event->number, $to),
            ['status' => AdverseEvent::STATUS_NEW],
            ['status' => AdverseEvent::STATUS_TRANSMITTED, 'to' => $to],
            $actor,
        );

        return $event->refresh();
    }

    /**
     * Clore : avec une conclusion, même quand elle est que le médicament
     * n'y était pour rien. Un signalement sans suite écrite ne s'oublie pas,
     * il traîne.
     */
    public function close(AdverseEvent $event, ?string $conclusion, Authenticatable $actor): AdverseEvent
    {
        $conclusion = Text::clean($conclusion);

        if ($conclusion === null) {
            throw new PharmacieRuleViolation('Clore un signalement demande une conclusion, même négative.');
        }

        if ($event->isClosed()) {
            throw new PharmacieRuleViolation("Le signalement {$event->number} est déjà clos.");
        }

        $event->update([
            'status' => AdverseEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'conclusion' => $conclusion,
        ]);

        $this->auditor->record(
            'adverse_event_closed',
            $event,
            sprintf('Signalement %s clos : %s', $event->number, $conclusion),
            ['status' => $event->getOriginal('status')],
            ['status' => AdverseEvent::STATUS_CLOSED, 'conclusion' => $conclusion],
            $actor,
        );

        return $event->refresh();
    }
}
