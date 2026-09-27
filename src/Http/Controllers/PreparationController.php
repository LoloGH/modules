<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Actions\DeliverPreparation;
use Keneya\Pharmacie\Actions\DispenseProducts;
use Keneya\Pharmacie\Actions\PrepareDispensation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Sales\SaleStatus;
use Keneya\Pharmacie\Services\StockPicker;

/**
 * Les préparations : ce qui attend entre le comptoir et la caisse.
 *
 * Le patient règle avant d'être servi. Entre les deux vit une dispensation
 * écrite, chiffrée, facturée, dont rien n'est sorti du stock. Cet écran
 * existe pour qu'aucune ne s'oublie : celles qui attendent un paiement,
 * celles qui sont réglées et n'attendent plus que le geste du comptoir.
 *
 * L'état du paiement n'est jamais conservé ici : il est relu à chaque
 * affichage auprès de l'hôte, donc il ne peut pas se périmer en base pendant
 * qu'un caissier encaisse à l'autre bout du couloir.
 */
final class PreparationController extends PharmacieController
{
    /**
     * Une page courte, et c'est volontaire : chaque ligne interroge la caisse
     * pour savoir où elle en est. Le comptoir a besoin de ce qui attend
     * aujourd'hui, pas d'un historique.
     */
    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $preparations = Dispensation::query()->ofFacility()
            ->where('status', Dispensation::STATUS_DRAFT)
            ->with(['items', 'location', 'reservations'])
            ->orderBy('prepared_at')
            ->paginate(self::PER_PAGE);

        $statuses = [];

        foreach ($preparations as $preparation) {
            $statuses[$preparation->id] = $this->saleStatus($preparation);
        }

        return view('pharmacie::preparations.index', [
            'preparations' => $preparations,
            'statuses' => $statuses,
            'stale' => $this->staleAfterHours(),
            'abandoned' => Dispensation::query()->ofFacility()
                ->where('status', Dispensation::STATUS_CANCELLED)
                ->whereNull('dispensed_at')
                ->with('items')
                ->latest('cancelled_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function show(Request $request, Dispensation $preparation, StockPicker $picker): View
    {
        $preparation->load(['items.product', 'items.location', 'location', 'reservations.batch']);

        // Ce que le comptoir pourra servir aujourd'hui : le stock a pu bouger
        // depuis la préparation, et mieux vaut le voir avant d'appeler le
        // patient.
        $available = [];

        foreach ($preparation->items as $item) {
            // Chaque ligne se regarde la ou elle a ete prise : ce qui vient de
            // la reserve n'est pas absent parce que le comptoir ne l'a pas.
            $from = $item->servingLocation($preparation->location);

            if ($item->product === null || $from === null) {
                continue;
            }

            $rows = $picker->batchesFor($item->product, $from);

            $available[$item->id] = [
                'total' => array_sum(array_column($rows, 'available')),
                'batches' => $rows,
                'location' => $from,
            ];
        }

        return view('pharmacie::preparations.show', [
            'preparation' => $preparation,
            'status' => $this->saleStatus($preparation),
            'available' => $available,
        ]);
    }

    public function deliver(Request $request, Dispensation $preparation, DeliverPreparation $action, DispenseProducts $dispensing): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.batch_id' => ['nullable', 'integer', 'exists:pharmacie_batches,id'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $overrides = [];

        foreach ($data['lines'] ?? [] as $itemId => $line) {
            $overrides[(int) $itemId] = [
                'batch_id' => isset($line['batch_id']) && $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
                'override_reason' => $line['override_reason'] ?? null,
            ];
        }

        $delivered = $action->handle($preparation, $this->user($request), $overrides);

        // Le dossier médical apprend ce qui a été servi une fois le stock
        // sorti, jamais avant.
        $dispensing->reportToPrescriber($delivered);

        return redirect()->route('pharmacie.dispensing.show', $delivered)->with(
            'pharmacie_status',
            sprintf(
                'Préparation %s délivrée.%s',
                $delivered->number,
                (int) $delivered->outstanding > 0
                    ? sprintf(' Reliquat de %d unité(s) : le patient a payé ce qui manque.', $delivered->outstanding)
                    : '',
            ),
        );
    }

    public function abandon(Request $request, Dispensation $preparation, PrepareDispensation $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $abandoned = $action->abandon($preparation, (string) $data['reason'], $this->user($request));

        return redirect()->route('pharmacie.preparations.index')->with(
            'pharmacie_status',
            sprintf('Préparation %s abandonnée.', $abandoned->number),
        );
    }

    /**
     * Renvoyer la facture : la caisse n'avait pas répondu.
     */
    public function resend(Request $request, Dispensation $preparation, PrepareDispensation $action): RedirectResponse
    {
        $reference = $action->sendToCashier($preparation, $this->user($request));

        if ($reference === null) {
            return back()->with('pharmacie_error', 'La caisse n\'a toujours pas rendu de pièce : réessayez, ou vérifiez qu\'elle est branchée.');
        }

        return redirect()->route('pharmacie.preparations.show', $preparation)->with(
            'pharmacie_status',
            sprintf('Facture %s créée en caisse.', $reference),
        );
    }

    private function saleStatus(Dispensation $preparation): ?SaleStatus
    {
        return $preparation->billing_reference === null
            ? null
            : Pharmacie::saleStatus()->status((string) $preparation->billing_reference);
    }

    private function staleAfterHours(): int
    {
        return max(0, (int) config('pharmacie.dispensing.stale_after_hours', 24));
    }
}
