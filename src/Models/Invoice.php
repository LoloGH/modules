<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une facture : des lignes du catalogue, un total, ce qui en a été encaissé.
 *
 * Statuts :
 *  - `unpaid` Impayée, `partial` Partielle, `paid` Payée : suivent les
 *    encaissements rattachés, recalculés par `recalculate()` ;
 *  - `cancelled` Annulée : geste du contrôle, sans encaissement valide ;
 *  - `refunded` Remboursée : réservé au remboursement (tranche à venir).
 */
class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'finance_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'paid' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PAID => 'Payée',
            self::STATUS_PARTIAL => 'Partielle',
            self::STATUS_UNPAID => 'Impayée',
            self::STATUS_CANCELLED => 'Annulée',
            self::STATUS_REFUNDED => 'Remboursée',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function balance(): int
    {
        return $this->isClosed() ? 0 : max(0, (int) $this->total - (int) $this->paid);
    }

    /** Annulée ou remboursée : plus rien ne s'y encaisse. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);
    }

    public function canBePaid(): bool
    {
        return ! $this->isClosed() && $this->balance() > 0;
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    /** Classe du badge : vert payée, ambre partielle, rouge impayée… */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'ok',
            self::STATUS_PARTIAL => 'warn',
            self::STATUS_UNPAID => 'danger',
            self::STATUS_REFUNDED => 'info',
            default => 'off',
        };
    }

    /**
     * Relit les encaissements valides rattachés et en déduit payé et statut.
     * À appeler sous verrou, dans la transaction qui a changé un encaissement.
     */
    public function recalculate(): void
    {
        $paid = (int) $this->payments()->where('status', Payment::STATUS_VALID)->sum('amount');

        $status = $this->status;

        if (! $this->isClosed()) {
            $status = match (true) {
                $paid <= 0 => self::STATUS_UNPAID,
                $paid < (int) $this->total => self::STATUS_PARTIAL,
                default => self::STATUS_PAID,
            };
        }

        $this->update(['paid' => $paid, 'status' => $status]);
    }
}
