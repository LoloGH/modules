<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Keneya\Pharmacie\Models\AuditLog;

/**
 * Écrit dans le journal d'audit de la pharmacie.
 *
 * Événements attendus : created, updated, validated, cancelled, refunded,
 * discounted, closed, tariff_changed, status_changed, access_denied…
 *
 * Pour une opération financière, un échec d'écriture doit faire échouer
 * l'opération : l'appelant ne l'attrape pas. Seul le refus d'accès, qui
 * n'est qu'une trace, peut se permettre de passer outre.
 */
final class Auditor
{
    /**
     * @param  array<string, mixed>  $old  valeurs avant
     * @param  array<string, mixed>  $new  valeurs après
     */
    public function record(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
        ?Authenticatable $user = null,
    ): AuditLog {
        $user ??= auth()->user();

        $label = $user === null ? null : (data_get($user, 'name') ?? data_get($user, 'email'));

        return AuditLog::create([
            'event' => $event,
            'user_id' => $user === null ? null : (string) $user->getAuthIdentifier(),
            'user_name' => is_string($label) ? $label : null,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'description' => $description,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => request()->ip(),
        ]);
    }
}
