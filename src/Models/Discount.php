<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une remise demandée sur une facture : ce que l'établissement renonce à
 * réclamer au patient.
 *
 * Demandée par qui encaisse, approuvée par qui contrôle, jamais la même
 * personne. Tant qu'elle n'est pas approuvée, elle ne change rien.
 *
 * @property int $amount
 */
class Discount extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    protected $table = 'finance_discounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_REQUESTED => 'À approuver',
            self::STATUS_APPROVED => 'Approuvée',
            self::STATUS_REFUSED => 'Refusée',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'ok',
            self::STATUS_REFUSED => 'danger',
            default => 'warn',
        };
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    /**
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
