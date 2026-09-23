<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Support\Facility;
use LogicException;

/**
 * Une écriture du grand livre du stock.
 *
 * On n'y écrit qu'en ajoutant : toute tentative de modification ou de
 * suppression lève une exception. Une erreur se corrige par une écriture
 * inverse, motivée — jamais en effaçant, sinon le stock ne se recalcule plus
 * et ne vaut plus rien.
 *
 * @property int $quantity signée : positive en entrée, négative en sortie
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const KIND_RECEPTION = 'reception';

    public const KIND_DISPENSING = 'dispensation';

    public const KIND_TRANSFER_OUT = 'transfert_sortie';

    public const KIND_TRANSFER_IN = 'transfert_entree';

    public const KIND_ADJUSTMENT = 'ajustement';

    public const KIND_LOSS = 'perte';

    public const KIND_DESTRUCTION = 'destruction';

    public const KIND_RETURN = 'retour';

    public const KIND_CANCELLATION = 'annulation';

    protected $table = 'pharmacie_stock_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'quantity_after' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Un mouvement de stock ne se modifie pas : corrigez par une écriture inverse.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Un mouvement de stock ne se supprime pas : corrigez par une écriture inverse.');
        });
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_RECEPTION => 'Réception',
            self::KIND_DISPENSING => 'Dispensation',
            self::KIND_TRANSFER_OUT => 'Transfert (sortie)',
            self::KIND_TRANSFER_IN => 'Transfert (entrée)',
            self::KIND_ADJUSTMENT => 'Ajustement',
            self::KIND_LOSS => 'Perte',
            self::KIND_DESTRUCTION => 'Destruction',
            self::KIND_RETURN => 'Retour',
            self::KIND_CANCELLATION => 'Annulation',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function isIncoming(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * @param  Builder<StockMovement>  $query
     * @return Builder<StockMovement>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
