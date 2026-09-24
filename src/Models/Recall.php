<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un rappel de lot : le lot est retiré, et, selon le niveau, les patients
 * déjà servis sont rappelés un par un.
 *
 * Un rappel ne se clôt pas parce qu'on en a assez : il se clôt quand le lot
 * ne peut plus sortir et que, s'il fallait joindre des patients, ils l'ont
 * été.
 */
class Recall extends Model
{
    public const ORIGIN_INTERNAL = 'interne';

    public const ORIGIN_MANUFACTURER = 'fabricant';

    public const ORIGIN_AUTHORITY = 'autorite';

    /** Retirer le lot du stock : il ne sort plus. */
    public const LEVEL_STOCK = 'retrait_stock';

    /** Retirer le lot ET rappeler les patients déjà servis. */
    public const LEVEL_PATIENTS = 'rappel_patients';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'pharmacie_recalls';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_blocked' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function originLabels(): array
    {
        return [
            self::ORIGIN_INTERNAL => 'Constat interne',
            self::ORIGIN_MANUFACTURER => 'Fabricant',
            self::ORIGIN_AUTHORITY => 'Autorité sanitaire',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function levelLabels(): array
    {
        return [
            self::LEVEL_STOCK => 'Retrait du stock',
            self::LEVEL_PATIENTS => 'Retrait et rappel des patients',
        ];
    }

    public function originLabel(): string
    {
        return self::originLabels()[$this->origin] ?? (string) $this->origin;
    }

    public function levelLabel(): string
    {
        return self::levelLabels()[$this->level] ?? (string) $this->level;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(RecallPatient::class, 'recall_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Vrai quand le niveau du rappel impose de joindre les patients. */
    public function reachesPatients(): bool
    {
        return $this->level === self::LEVEL_PATIENTS;
    }

    public function remainingToContact(): int
    {
        return (int) $this->patients()->where('contacted', false)->count();
    }

    public function statusLabel(): string
    {
        return $this->isOpen() ? 'En cours' : 'Clos';
    }

    public function statusTone(): string
    {
        return $this->isOpen() ? 'danger' : 'ok';
    }

    /**
     * @param  Builder<Recall>  $query
     * @return Builder<Recall>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
