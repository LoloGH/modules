<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un règlement reçu d'un assureur pour une facture (virement, chèque…).
 */
class InsuranceSettlement extends Model
{
    protected $table = 'finance_insurance_settlements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'received_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurer_id');
    }
}
