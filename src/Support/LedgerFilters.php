<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;

/**
 * Les filtres communs aux écrans Paiements, Recettes, Dépenses et Rapports :
 * période, moyen de paiement, statut, recherche, centre analytique (service)
 * et acte (activité).
 *
 * Lecture seule, sur les écritures réelles de caisse. Un caissier ne voit que
 * les mouvements de ses propres sessions (`cashierScope`) ; le contrôle voit
 * tout.
 */
final class LedgerFilters
{
    public const STATUS_ALL = 'tous';

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $methodId = null,
        public readonly string $status = self::STATUS_ALL,
        public readonly ?string $search = null,
        public readonly ?int $centerId = null,
        public readonly ?int $actId = null,
        public readonly ?string $cashierScope = null,
    ) {}

    /**
     * Par défaut : du premier jour du mois à aujourd'hui, tous statuts. Une
     * date illisible retombe sur le défaut plutôt que de casser la page.
     */
    public static function fromRequest(Request $request, ?string $cashierScope = null): self
    {
        $from = self::date($request->query('du')) ?? Carbon::today()->startOfMonth();
        $to = self::date($request->query('au')) ?? Carbon::today();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $status = $request->query('statut');

        return new self(
            from: $from->copy()->startOfDay(),
            to: $to->copy()->endOfDay(),
            methodId: self::id($request->query('moyen')),
            status: in_array($status, [Payment::STATUS_VALID, Payment::STATUS_CANCELLED], true) ? $status : self::STATUS_ALL,
            search: Text::clean(is_string($request->query('q')) ? $request->query('q') : null),
            centerId: self::id($request->query('centre')),
            actId: self::id($request->query('acte')),
            cashierScope: $cashierScope,
        );
    }

    /**
     * @return Builder<Payment>
     */
    public function payments(): Builder
    {
        $query = Payment::query()
            ->whereBetween('created_at', [$this->from, $this->to])
            ->when($this->methodId, fn (Builder $q) => $q->where('payment_method_id', $this->methodId))
            ->when($this->status !== self::STATUS_ALL, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->actId, fn (Builder $q) => $q->where('act_id', $this->actId))
            ->when($this->centerId, fn (Builder $q) => $q->whereHas('act', fn (Builder $act) => $act->where('analytic_center_id', $this->centerId)))
            ->when($this->search, function (Builder $q): void {
                $like = '%'.$this->search.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('number', 'like', $like)
                    ->orWhere('patient_name', 'like', $like)
                    ->orWhere('patient_id', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('invoice', fn (Builder $invoice) => $invoice->where('number', 'like', $like)));
            });

        return $this->scoped($query);
    }

    /**
     * @return Builder<Disbursement>
     */
    public function disbursements(): Builder
    {
        $query = Disbursement::query()
            ->whereBetween('created_at', [$this->from, $this->to])
            ->when($this->methodId, fn (Builder $q) => $q->where('payment_method_id', $this->methodId))
            ->when($this->status !== self::STATUS_ALL, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search, function (Builder $q): void {
                $like = '%'.$this->search.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('number', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhere('beneficiary', 'like', $like)
                    ->orWhere('reference', 'like', $like));
            });

        return $this->scoped($query);
    }

    /**
     * Les paramètres d'URL, pour garder les filtres d'une page à l'autre.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'du' => $this->from->toDateString(),
            'au' => $this->to->toDateString(),
            'moyen' => $this->methodId === null ? null : (string) $this->methodId,
            'statut' => $this->status === self::STATUS_ALL ? null : $this->status,
            'q' => $this->search,
            'centre' => $this->centerId === null ? null : (string) $this->centerId,
            'acte' => $this->actId === null ? null : (string) $this->actId,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    public function periodLabel(): string
    {
        return $this->from->isSameDay($this->to)
            ? 'le '.$this->from->format('d/m/Y')
            : 'du '.$this->from->format('d/m/Y').' au '.$this->to->format('d/m/Y');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query): Builder
    {
        return $this->cashierScope === null
            ? $query
            : $query->whereHas('session', fn (Builder $session) => $session->where('cashier_id', $this->cashierScope));
    }

    private static function date(mixed $value): ?Carbon
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

    private static function id(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
