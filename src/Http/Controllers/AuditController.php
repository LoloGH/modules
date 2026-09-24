<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Support\AuditEvents;
use Keneya\FinanceCaisse\Support\Text;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le journal d'audit financier : qui a fait quoi, quand, et sur quoi.
 *
 * Lecture seule, et rien d'autre : une ligne d'audit ne se modifie ni ne se
 * supprime (voir Models\AuditLog). L'écran sert au contrôle — retrouver une
 * clôture validée, une annulation, une remise approuvée — et s'exporte pour
 * être joint à un rapport.
 */
final class AuditController extends FinanceController
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        return view('finance::audit.index', [
            'filters' => $filters,
            'entries' => (clone $query)->latest('id')->paginate(self::PER_PAGE)->withQueryString(),
            'groups' => AuditEvents::groupsWith(AuditLog::query()->distinct()->pluck('event')),
            'stats' => [
                'count' => (clone $query)->count(),
                'authors' => (clone $query)->distinct()->count('user_id'),
                'events' => (clone $query)->distinct()->count('event'),
            ],
        ]);
    }

    /**
     * Le même journal, filtré de la même façon, en CSV : ce qu'on joint à un
     * rapport ou qu'on remet à un contrôleur.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        $name = 'journal-audit-'.$filters['from']->toDateString().'-'.$filters['to']->toDateString().'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            // BOM : Excel lit l'UTF-8 sans le demander.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Événement', 'Description', 'Auteur', 'Objet', 'Adresse IP'], ';');

            $query->latest('id')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $entry) {
                    fputcsv($out, [
                        $entry->created_at?->format('d/m/Y H:i:s'),
                        AuditEvents::label((string) $entry->event),
                        $entry->description,
                        $entry->user_name ?? $entry->user_id,
                        $this->subject($entry),
                        $entry->ip_address,
                    ], ';');
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{from: Carbon, to: Carbon, event: ?string, author: ?string, search: ?string}
     */
    private function filters(Request $request): array
    {
        $from = $this->date($request->query('du')) ?? Carbon::today()->startOfMonth();
        $to = $this->date($request->query('au')) ?? Carbon::today();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $event = $request->query('evenement');

        return [
            'from' => $from->startOfDay(),
            'to' => $to->endOfDay(),
            'event' => is_string($event) && $event !== '' ? $event : null,
            'author' => Text::clean(is_string($request->query('auteur')) ? $request->query('auteur') : null),
            'search' => Text::clean(is_string($request->query('q')) ? $request->query('q') : null),
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon, event: ?string, author: ?string, search: ?string}  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['event'], fn (Builder $q) => $q->where('event', $filters['event']))
            ->when($filters['author'], function (Builder $q) use ($filters): void {
                $like = '%'.$filters['author'].'%';
                $q->where(fn (Builder $w) => $w->where('user_name', 'like', $like)->orWhere('user_id', 'like', $like));
            })
            ->when($filters['search'], function (Builder $q) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $q->where(fn (Builder $w) => $w
                    ->where('description', 'like', $like)
                    ->orWhere('subject_id', 'like', $like)
                    ->orWhere('subject_type', 'like', $like));
            });
    }

    /**
     * L'objet de la ligne, lisible : « CashSession n° 12 ».
     */
    private function subject(AuditLog $entry): string
    {
        if ($entry->subject_type === null) {
            return '-';
        }

        return class_basename((string) $entry->subject_type).' n° '.$entry->subject_id;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
