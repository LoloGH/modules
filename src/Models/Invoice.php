<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une facture : des lignes du catalogue, un total, ce qui en a été encaissé.
 *
 * Statuts :
 *  - `unpaid` Impayée, `partial` Partielle, `paid` Payée : suivent les
 *    encaissements rattachés, recalculés par `recalculate()` ;
 *  - `cancelled` Annulée : geste du contrôle, sans encaissement valide ;
 *  - `refunded` Remboursée : réservé au remboursement (tranche à venir).
 *
 * Avec un assureur, le statut ci-dessus ne concerne que ce que doit le
 * patient (part patient + rejets de l'assureur). Ce que doit l'assureur suit
 * son propre statut (`claim_status`) : En attente, Partiellement réglée,
 * Réglée, Rejetée.
 */
class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const CLAIM_PENDING = 'pending';

    public const CLAIM_PARTIAL = 'partial';

    public const CLAIM_SETTLED = 'settled';

    public const CLAIM_REJECTED = 'rejected';

    protected $table = 'finance_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'paid' => 'integer',
            'insurer_share' => 'integer',
            'patient_share' => 'integer',
            'insurer_paid' => 'integer',
            'insurer_rejected' => 'integer',
            'coverage_rate' => 'integer',
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

    /**
     * @return array<string, string>
     */
    public static function claimLabels(): array
    {
        return [
            self::CLAIM_PENDING => 'En attente',
            self::CLAIM_PARTIAL => 'Partiellement réglée',
            self::CLAIM_SETTLED => 'Réglée',
            self::CLAIM_REJECTED => 'Rejetée',
        ];
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'insurer_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(InsuranceSettlement::class, 'invoice_id');
    }

    public function rejections(): HasMany
    {
        return $this->hasMany(InsuranceRejection::class, 'invoice_id');
    }

    public function isInsured(): bool
    {
        return $this->insurer_id !== null;
    }

    /** Ce que doit le patient : sa part, plus ce que l'assureur a rejeté. */
    public function patientDue(): int
    {
        return (int) $this->patient_share + (int) $this->insurer_rejected;
    }

    /** Ce que l'assureur doit encore : sa part, moins réglé et rejeté. */
    public function insurerOutstanding(): int
    {
        return $this->isClosed() || ! $this->isInsured()
            ? 0
            : max(0, (int) $this->insurer_share - (int) $this->insurer_paid - (int) $this->insurer_rejected);
    }

    public function claimLabel(): string
    {
        return self::claimLabels()[$this->claim_status] ?? '—';
    }

    public function claimTone(): string
    {
        return match ($this->claim_status) {
            self::CLAIM_SETTLED => 'ok',
            self::CLAIM_PARTIAL => 'warn',
            self::CLAIM_REJECTED => 'danger',
            self::CLAIM_PENDING => 'info',
            default => 'off',
        };
    }

    /**
     * Relit règlements et rejets de l'assureur et en déduit sa part réglée,
     * rejetée et le statut de la créance. Sous verrou, comme `recalculate()`.
     */
    public function recalculateClaim(): void
    {
        if (! $this->isInsured()) {
            return;
        }

        $paid = (int) $this->settlements()->sum('amount');
        $rejected = (int) $this->rejections()->sum('amount');
        $share = (int) $this->insurer_share;

        $status = match (true) {
            $paid + $rejected <= 0 => self::CLAIM_PENDING,
            $paid + $rejected < $share => self::CLAIM_PARTIAL,
            $paid <= 0 => self::CLAIM_REJECTED,
            default => self::CLAIM_SETTLED,
        };

        $this->update(['insurer_paid' => $paid, 'insurer_rejected' => $rejected, 'claim_status' => $status]);
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
        return $this->isClosed() ? 0 : max(0, $this->patientDue() - (int) $this->paid);
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
            $due = $this->patientDue();

            // Ce que doit le patient : sa part, et ce que l'assureur a rejeté.
            // Pris en charge à 100 %, il ne doit rien : sa part est réglée.
            $status = match (true) {
                $due <= 0, $paid >= $due => self::STATUS_PAID,
                $paid <= 0 => self::STATUS_UNPAID,
                default => self::STATUS_PARTIAL,
            };
        }

        $this->update(['paid' => $paid, 'status' => $status]);
    }
}
