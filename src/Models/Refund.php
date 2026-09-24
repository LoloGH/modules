<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un remboursement : de l'argent rendu au patient.
 *
 * Trois origines : un encaissement précis, une facture, ou le solde du compte
 * du patient. Demandé par qui encaisse, approuvé par qui contrôle, puis payé
 * à la caisse, le décaissement qui le paie lui reste attaché.
 *
 * @property int $amount
 */
class Refund extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_PAID = 'paid';

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_ACCOUNT = 'account';

    protected $table = 'finance_refunds';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_REQUESTED => 'À approuver',
            self::STATUS_APPROVED => 'À payer',
            self::STATUS_REFUSED => 'Refusé',
            self::STATUS_PAID => 'Payé',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_PAYMENT => 'Encaissement',
            self::SOURCE_INVOICE => 'Facture',
            self::SOURCE_ACCOUNT => 'Solde du compte patient',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function sourceLabel(): string
    {
        return self::sourceLabels()[$this->source] ?? (string) $this->source;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'ok',
            self::STATUS_APPROVED => 'info',
            self::STATUS_REFUSED => 'danger',
            default => 'warn',
        };
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Disbursement::class, 'disbursement_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    public function scopeToPay(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
