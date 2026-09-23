<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Ligne du journal d'audit de la pharmacie.
 *
 * On n'y écrit qu'en ajoutant : toute tentative de modification ou de
 * suppression d'une ligne lève une exception. Ce verrou protège contre une
 * erreur de code ; il ne remplace pas les droits de la base (un accès SQL
 * direct ou `AuditLog::query()->delete()` contourne les événements du modèle).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pharmacie_audit_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Une ligne du journal d\'audit de la pharmacie ne se modifie pas.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Une ligne du journal d\'audit de la pharmacie ne se supprime pas.');
        });
    }
}
