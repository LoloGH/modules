<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une avance versée par un patient : de l'argent reçu d'avance, qui reste le
 * sien tant qu'il n'a rien payé.
 *
 * @property int $id
 * @property string $number
 * @property int $amount
 * @property string $patient_id
 */
class PatientDeposit extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'finance_patient_deposits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'cancelled_at' => 'datetime'];
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
     * @param  Builder<PatientDeposit>  $query
     * @return Builder<PatientDeposit>
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
