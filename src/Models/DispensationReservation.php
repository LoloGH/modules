<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Des unités mises de côté pour une préparation en attente de paiement.
 *
 * Elles sont toujours physiquement là : aucune écriture au grand livre, rien
 * n'est sorti. Elles ne sont simplement plus servables à quelqu'un d'autre,
 * et cette ligne dit à qui elles sont promises, pour pouvoir les rendre.
 */
class DispensationReservation extends Model
{
    protected $table = 'pharmacie_dispensation_reservations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class, 'dispensation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
