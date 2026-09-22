<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encaissement. Ni modifié ni supprimé : une erreur s'annule (avec motif).
 */
class Payment extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'finance_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'invoice_id' => 'integer',
            'act_id' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * L'acte encaissé, quand l'encaissement en désigne un. Null pour ce qui
     * ne figure pas au catalogue (une avance, un reliquat).
     */
    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class, 'act_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALID);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
