<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un patient servi du lot rappelé, et où en est son rappel.
 *
 * La ligne garde le nom et la quantité tels qu'ils étaient au moment de la
 * dispensation : le rappel doit rester lisible même si la fiche du patient
 * change chez l'hôte.
 */
class RecallPatient extends Model
{
    protected $table = 'pharmacie_recall_patients';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'contacted' => 'boolean',
            'contacted_at' => 'datetime',
            'dispensed_at' => 'datetime',
        ];
    }

    public function recall(): BelongsTo
    {
        return $this->belongsTo(Recall::class, 'recall_id');
    }

    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class, 'dispensation_id');
    }

    public function label(): string
    {
        return $this->patient_name ?? $this->patient_id ?? 'Patient non désigné';
    }
}
