<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le detail par lot d'une ligne servie.
 *
 * C'est cette table qui rend un rappel de lot possible : partir d'un lot
 * suspect et retrouver les patients qui l'ont recu.
 */
class DispensationBatch extends Model
{
    protected $table = 'pharmacie_dispensation_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'overrode_fefo' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DispensationItem::class, 'dispensation_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
