<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décaissement (sortie de caisse). Ni modifié ni supprimé : il s'annule.
 */
class Disbursement extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'finance_disbursements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Le centre analytique qui porte la charge, choisi à l'enregistrement.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AnalyticCenter::class, 'analytic_center_id');
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
     * @param  Builder<Disbursement>  $query
     * @return Builder<Disbursement>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALID);
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return array_map('strval', (array) config('finance.expense_categories', []));
    }

    /** « Non classée » sans catégorie ; le code brut si elle a été retirée de la liste. */
    public function categoryLabel(): string
    {
        return $this->category === null ? 'Non classée' : (self::categories()[$this->category] ?? (string) $this->category);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
