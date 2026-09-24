<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de facture : l'acte, son libellé et son prix figés au jour de la
 * facture, le catalogue évoluera, la facture reste lisible.
 */
class InvoiceLine extends Model
{
    protected $table = 'finance_invoice_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'insurer_rate' => 'integer',
            'insurer_share' => 'integer',
            'patient_share' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * Le centre analytique gravé sur l'écriture : celui de l'acte au moment
     * où elle a été écrite, que le catalogue ne réécrit plus après coup.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AnalyticCenter::class, 'analytic_center_id');
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class, 'act_id');
    }
}
