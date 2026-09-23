<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

/**
 * Une alerte : quelque chose qui attend un geste, dit en une phrase, avec
 * l'écran où le faire.
 *
 * Le module ne notifie personne par courriel ni par SMS — il n'ajoute aucune
 * dépendance et ne suppose aucun service extérieur. Une alerte est un état
 * de la base, relu à chaque affichage : elle disparaît d'elle-même quand la
 * situation est réglée, et ne peut donc jamais mentir.
 */
final readonly class Alert
{
    public const LEVEL_DANGER = 'danger';

    public const LEVEL_WARN = 'warn';

    public const LEVEL_INFO = 'info';

    public function __construct(
        public string $key,
        public string $level,
        public string $title,
        public string $detail,
        public ?string $url = null,
        public ?string $action = null,
    ) {}

    /**
     * L'ordre d'affichage : ce qui bloque avant ce qui attend.
     */
    public function weight(): int
    {
        return match ($this->level) {
            self::LEVEL_DANGER => 0,
            self::LEVEL_WARN => 1,
            default => 2,
        };
    }
}
