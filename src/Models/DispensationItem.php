<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ligne de dispensation : prescrit, delivre, et ce qui manque.
 *
 * Le libelle est recopie : si le catalogue change, ce qui a ete servi reste
 * lisible tel qu'il a ete servi.
 */
class DispensationItem extends Model
{
    protected $table = 'pharmacie_dispensation_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'prescribed_quantity' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class, 'dispensation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function substitutedFor(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substituted_for_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(DispensationBatch::class, 'dispensation_item_id');
    }

    /** Ce qui reste a delivrer sur cette ligne. */
    public function outstanding(): int
    {
        return max(0, (int) $this->prescribed_quantity - (int) $this->quantity);
    }

    public function isSubstituted(): bool
    {
        return $this->substituted_for_id !== null;
    }
}
