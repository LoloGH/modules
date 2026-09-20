<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyen de paiement. Un moyen se désactive, il ne se supprime pas.
 *
 * `kind` décide du comportement (les espèces sont comptées dans le tiroir de
 * la caisse, pas le Mobile Money) ; `code` et `name` sont libres.
 */
class PaymentMethod extends Model
{
    public const KIND_CASH = 'cash';

    public const KIND_MOBILE_MONEY = 'mobile_money';

    public const KIND_CARD = 'card';

    public const KIND_TRANSFER = 'transfer';

    public const KIND_CHEQUE = 'cheque';

    public const KIND_ELECTRONIC = 'electronic';

    public const KIND_ONLINE = 'online';

    public const KIND_PATIENT_ACCOUNT = 'patient_account';

    public const KIND_INSURANCE = 'insurance';

    protected $table = 'finance_payment_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('name');
    }

    /**
     * Les espèces sont le seul moyen compté physiquement dans le tiroir.
     */
    public function isCash(): bool
    {
        return $this->kind === self::KIND_CASH;
    }
}
