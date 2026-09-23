<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

final class Text
{
    /**
     * Texte nettoyé, ou null s'il est vide.
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
