<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un signalement d'effet indésirable.
 *
 * Il relie ce qui, autrement, resterait séparé : le patient, le médicament,
 * le lot, et la dispensation par laquelle il est parti. C'est ce lien qui
 * permet, quand plusieurs signalements désignent le même lot, de décider un
 * rappel au lieu de le soupçonner.
 *
 * Un signalement ne se supprime pas : il se clôt avec une conclusion, même
 * quand la conclusion est que le médicament n'y était pour rien.
 */
class AdverseEvent extends Model
{
    public const SEVERITY_MILD = 'benin';

    public const SEVERITY_MODERATE = 'modere';

    public const SEVERITY_SEVERE = 'grave';

    public const SEVERITY_LIFE_THREATENING = 'vital';

    public const OUTCOME_RECOVERED = 'retabli';

    public const OUTCOME_RECOVERING = 'en_cours';

    public const OUTCOME_SEQUELAE = 'sequelles';

    public const OUTCOME_DEATH = 'deces';

    public const OUTCOME_UNKNOWN = 'inconnu';

    public const STATUS_NEW = 'nouveau';

    public const STATUS_TRANSMITTED = 'transmis';

    public const STATUS_CLOSED = 'clos';

    protected $table = 'pharmacie_adverse_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'reported_at' => 'datetime',
            'transmitted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function severityLabels(): array
    {
        return [
            self::SEVERITY_MILD => 'Bénin',
            self::SEVERITY_MODERATE => 'Modéré',
            self::SEVERITY_SEVERE => 'Grave',
            self::SEVERITY_LIFE_THREATENING => 'Pronostic vital engagé',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function outcomeLabels(): array
    {
        return [
            self::OUTCOME_RECOVERED => 'Rétabli',
            self::OUTCOME_RECOVERING => 'En cours de rétablissement',
            self::OUTCOME_SEQUELAE => 'Séquelles',
            self::OUTCOME_DEATH => 'Décès',
            self::OUTCOME_UNKNOWN => 'Inconnue',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_NEW => 'À traiter',
            self::STATUS_TRANSMITTED => 'Transmis',
            self::STATUS_CLOSED => 'Clos',
        ];
    }

    public function severityLabel(): string
    {
        return self::severityLabels()[$this->severity] ?? (string) $this->severity;
    }

    public function outcomeLabel(): string
    {
        return self::outcomeLabels()[$this->outcome] ?? (string) $this->outcome;
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    /**
     * Grave ou pronostic vital : ce qui ne peut pas attendre, et ce que le
     * tableau des alertes remonte en rouge.
     */
    public function isSerious(): bool
    {
        return in_array($this->severity, [self::SEVERITY_SEVERE, self::SEVERITY_LIFE_THREATENING], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function severityTone(): string
    {
        return match ($this->severity) {
            self::SEVERITY_LIFE_THREATENING, self::SEVERITY_SEVERE => 'danger',
            self::SEVERITY_MODERATE => 'warn',
            default => 'off',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_CLOSED => 'ok',
            self::STATUS_TRANSMITTED => 'info',
            default => 'warn',
        };
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function dispensation(): BelongsTo
    {
        return $this->belongsTo(Dispensation::class, 'dispensation_id');
    }

    public function patientLabel(): string
    {
        return $this->patient_name ?? $this->patient_id ?? 'Patient non désigné';
    }

    /**
     * @param  Builder<AdverseEvent>  $query
     * @return Builder<AdverseEvent>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
