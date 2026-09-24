<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Actions\MoveStock;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Transfer;

/**
 * Les transferts entre emplacements, et leur cycle.
 *
 * Quatre gestes, quatre responsabilités : demander, valider, envoyer,
 * recevoir. L'écran montre toujours où en est chaque transfert, et ce qui est
 * en transit, c'est-à-dire parti, mais pas encore arrivé.
 */
final class TransferController extends PharmacieController
{
    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('statut'), Transfer::statusLabels())
            ? (string) $request->query('statut')
            : null;

        return view('pharmacie::transfers.index', [
            'transfers' => Transfer::query()->ofFacility()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->with(['from', 'to', 'lines'])
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'statuses' => Transfer::statusLabels(),
            'status' => $status,
            'locations' => Location::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            'inTransit' => Transfer::query()->ofFacility()->where('status', Transfer::STATUS_SENT)->count(),
        ]);
    }

    public function show(Transfer $transfer): View
    {
        return view('pharmacie::transfers.show', [
            'transfer' => $transfer->load(['from', 'to', 'lines.product', 'lines.batch']),
        ]);
    }

    public function store(Request $request, MoveStock $action): RedirectResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:pharmacie_products,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $lines = array_values(array_filter(
            $data['lines'],
            static fn (array $line): bool => ($line['product_id'] ?? null) !== null && (int) ($line['quantity'] ?? 0) > 0,
        ));

        $transfer = $action->request(
            Location::query()->findOrFail((int) $data['from_location_id']),
            Location::query()->findOrFail((int) $data['to_location_id']),
            array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ], $lines),
            $this->user($request),
            $data['reason'] ?? null,
        );

        return redirect()->route('pharmacie.transfers.show', $transfer)
            ->with('pharmacie_status', sprintf('Transfert %s demandé.', $transfer->number));
    }

    public function decide(Request $request, Transfer $transfer, MoveStock $action): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,refuse'],
            'reason' => ['required_if:decision,refuse', 'nullable', 'string', 'max:500'],
        ]);

        $decided = $data['decision'] === 'approve'
            ? $action->approve($transfer, $this->user($request))
            : $action->refuse($transfer, (string) $data['reason'], $this->user($request));

        return redirect()->route('pharmacie.transfers.show', $decided)->with(
            'pharmacie_status',
            sprintf('Transfert %s : %s.', $decided->number, strtolower($decided->statusLabel())),
        );
    }

    public function send(Request $request, Transfer $transfer, MoveStock $action): RedirectResponse
    {
        $sent = $action->send($transfer, $this->user($request));

        return redirect()->route('pharmacie.transfers.show', $sent)->with(
            'pharmacie_status',
            sprintf('Transfert %s parti : les unités sont en transit.', $sent->number),
        );
    }

    public function receive(Request $request, Transfer $transfer, MoveStock $action): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.quantity' => ['required', 'integer', 'min:0'],
            'lines.*.gap_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $received = [];

        foreach ($data['lines'] as $lineId => $row) {
            $received[(int) $lineId] = [
                'quantity' => (int) $row['quantity'],
                'gap_reason' => $row['gap_reason'] ?? null,
            ];
        }

        $done = $action->receive($transfer, $received, $this->user($request));

        return redirect()->route('pharmacie.transfers.show', $done)->with(
            'pharmacie_status',
            sprintf('Transfert %s reçu : le stock est à jour à destination.', $done->number),
        );
    }
}
