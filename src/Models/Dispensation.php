<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Une dispensation : ce qui a été délivré, à qui, et sur quelle base.
 *
 * Elle ne se modifie pas après coup : on l'annule, avec un motif, et le stock
 * revient par des écritures inverses qui restent lisibles.
 *
 * @property int $outstanding ce qu'il reste à délivrer sur l'ordonnance
 */
class Dispensation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_CANCELLED = 'cancelled';

    // Ce qu'il advient de l'argent : la pharmacie dit ce qui est du, la
    // caisse encaisse.
    public const PAYMENT_DUE = 'a_payer';

    public const PAYMENT_SENT = 'envoye';

    public const PAYMENT_SETTLED = 'regle';

    public const PAYMENT_FREE = 'gratuit';

    public const SOURCE_COUNTER = 'counter';

    public const SOURCE_QUEUE = 'queue';

    public const SOURCE_PRESCRIPTION = 'prescription';

    protected $table = 'pharmacie_dispensations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'outstanding' => 'integer',
            'dispensed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            self::SOURCE_COUNTER => 'Comptoir',
            self::SOURCE_QUEUE => 'File de la pharmacie',
            self::SOURCE_PRESCRIPTION => 'Ordonnance',
        ];
    }

    public function sourceLabel(): string
    {
        return self::sourceLabels()[$this->source] ?? (string) $this->source;
    }

    /**
     * Complète, partielle ou annulée : le statut se lit du reliquat, jamais
     * d'une case cochée.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_CANCELLED => 'Annulée',
            $this->status === self::STATUS_DRAFT => 'En préparation',
            (int) $this->outstanding > 0 => 'Partielle',
            default => 'Complète',
        };
    }

    public function statusTone(): string
    {
        return match ($this->statusLabel()) {
            'Annulée' => 'danger',
            'Partielle' => 'warn',
            'Complète' => 'ok',
            default => 'off',
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * @return array<string, string>
     */
    public static function paymentLabels(): array
    {
        return [
            self::PAYMENT_DUE => 'À envoyer à la caisse',
            self::PAYMENT_SENT => 'Envoyé à la caisse',
            self::PAYMENT_SETTLED => 'Réglé',
            self::PAYMENT_FREE => 'Gratuit',
        ];
    }

    public function paymentLabel(): string
    {
        return self::paymentLabels()[$this->payment_status] ?? (string) $this->payment_status;
    }

    public function paymentTone(): string
    {
        return match ($this->payment_status) {
            self::PAYMENT_SETTLED => 'ok',
            self::PAYMENT_SENT => 'info',
            self::PAYMENT_FREE => 'muted',
            default => 'warn',
        };
    }

    /** Ce qui attend encore d'etre envoye a la caisse. */
    public function awaitsBilling(): bool
    {
        return ! $this->isCancelled()
            && $this->payment_status === self::PAYMENT_DUE
            && (int) $this->total > 0;
    }

    public function isPartial(): bool
    {
        return ! $this->isCancelled() && (int) $this->outstanding > 0;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DispensationItem::class, 'dispensation_id');
    }

    /**
     * @param  Builder<Dispensation>  $query
     * @return Builder<Dispensation>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * @param  Builder<Dispensation>  $query
     * @return Builder<Dispensation>
     */
    public function scopeDispensed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISPENSED);
    }
}
