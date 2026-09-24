<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Services\Analytics;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les rapports : ce que la pharmacie a consommé, ce qu'elle immobilise, ce
 * qu'elle perd, et ce qu'il lui faudra.
 *
 * Une seule barre de filtres (période, catégorie, emplacement) partagée
 * par les deux écrans et par les exports : le chiffre exporté est celui qui
 * était à l'écran, jamais un autre.
 */
final class ReportController extends PharmacieController
{
    public function index(Request $request, Analytics $analytics): View
    {
        [$from, $to, $filters] = $this->window($request);

        return view('pharmacie::reports.index', $this->filterData($from, $to, $filters) + [
            'summary' => $analytics->summary($from, $to, $filters),
            'byProduct' => $analytics->consumptionByProduct($from, $to, $filters, 25),
            'byCategory' => $analytics->consumptionByCategory($from, $to, $filters),
            'losses' => $analytics->lossesByKind($from, $to),
        ]);
    }

    public function forecast(Request $request, Analytics $analytics): View
    {
        [$from, $to, $filters] = $this->window($request);

        return view('pharmacie::reports.forecast', $this->filterData($from, $to, $filters) + [
            'rows' => $analytics->forecast($from, $to, $filters),
            'horizon' => (int) config('pharmacie.forecast.horizon_days', 60),
            'lead' => (int) config('pharmacie.forecast.lead_time_days', 30),
            'safety' => (int) config('pharmacie.forecast.safety_days', 15),
        ]);
    }

    /**
     * L'export : le même tableau, en CSV, pour être repris dans un tableur.
     * Aucune dépendance, un CSV s'écrit à la main.
     */
    public function export(Request $request, Analytics $analytics): StreamedResponse
    {
        [$from, $to, $filters] = $this->window($request);

        $what = $request->query('quoi') === 'prevision' ? 'prevision' : 'consommation';

        if ($what === 'prevision') {
            $header = ['Produit', 'Code', 'Consommation par jour', 'En stock', 'En commande', 'Couverture (jours)', 'Besoin estime', 'Risque'];
            $lines = $analytics->forecast($from, $to, $filters)->map(static fn (array $row): array => [
                $row['product']->label(),
                $row['product']->code,
                number_format($row['daily'], 2, ',', ' '),
                $row['on_hand'],
                $row['on_order'],
                $row['coverage'] === null ? '' : number_format($row['coverage'], 0, ',', ' '),
                $row['needed'],
                $row['risk'],
            ]);
        } else {
            $header = ['Produit', 'Code', 'Categorie', 'Quantite consommee', 'Valeur (achat)'];
            $lines = $analytics->consumptionByProduct($from, $to, $filters)->map(static fn (array $row): array => [
                $row['product']->label(),
                $row['product']->code,
                $row['product']->category?->name ?? '',
                $row['quantity'],
                $row['value'],
            ]);
        }

        $name = sprintf('pharmacie-%s-%s-%s.csv', $what, $from->format('Y-m-d'), $to->format('Y-m-d'));

        return response()->streamDownload(function () use ($header, $lines): void {
            $handle = fopen('php://output', 'wb');

            // Le BOM : sans lui, Excel affiche « pÃ©rimÃ© ».
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ';');

            foreach ($lines as $line) {
                fputcsv($handle, $line, ';');
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * La fenêtre demandée : par défaut le mois en cours, car c'est la
     * période sur laquelle une pharmacie se juge.
     *
     * @return array{0: Carbon, 1: Carbon, 2: array{category_id: ?int, location_id: ?int}}
     */
    private function window(Request $request): array
    {
        $from = $this->date($request->query('du')) ?? Carbon::today()->startOfMonth();
        $to = $this->date($request->query('au')) ?? Carbon::today();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, [
            'category_id' => ($id = (int) $request->query('categorie', '0')) > 0 ? $id : null,
            'location_id' => ($lid = (int) $request->query('emplacement', '0')) > 0 ? $lid : null,
        ]];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            // Une date illisible dans l'URL ne casse pas l'écran : on
            // retombe sur la période par défaut.
            return null;
        }
    }

    /**
     * @param  array{category_id: ?int, location_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function filterData(Carbon $from, Carbon $to, array $filters): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'filters' => $filters,
            'categories' => Category::query()->ofFacility()->active()->orderBy('name')->get(),
            'locations' => Location::query()->ofFacility()->active()->orderBy('name')->get(),
        ];
    }
}
