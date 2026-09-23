<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Access;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Standalone\StandaloneMode;
use Keneya\Pharmacie\Support\Rbac;

/**
 * Autorisation d'accès de haut niveau au module.
 *
 * Le module ne décide pas qui a le droit d'ouvrir la caisse : cette
 * décision appartient à l'application hôte. Cette classe vérifie que l'hôte
 * l'a accordée, sous l'une des trois formes prévues, dans cet ordre :
 *
 *   1. un résolveur enregistré par l'hôte, via Pharmacie::authorizeAccessUsing() ;
 *   2. une capacité portée par l'utilisateur (Gate ou permission), nommée
 *      par `pharmacie.access.ability` ;
 *   3. un attribut booléen de l'utilisateur, nommé par
 *      `pharmacie.access.attribute`.
 *
 * Sans accord explicite, l'accès est refusé : un module monté sans décision
 * de l'hôte reste fermé.
 *
 * Les permissions internes (qui peut ouvrir une session, rembourser,
 * approuver une remise…) ne sont pas concernées : elles restent portées
 * par {@see Rbac} et par les policies.
 */
final class PharmacieAccessGate
{
    public function __construct(
        private readonly Config $config,
        private readonly Gate $gate,
        private readonly StandaloneMode $standalone,
    ) {}

    /**
     * L'hôte a-t-il accordé l'accès au module à cet utilisateur ?
     */
    public function allows(?Authenticatable $user, ?Request $request = null): bool
    {
        // En développement autonome, aucun hôte n'existe pour accorder
        // quoi que ce soit : l'autorisation est simulée.
        if ($this->standalone->enabled()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $resolver = Pharmacie::accessResolver();

        if ($resolver !== null) {
            return (bool) $resolver($user, $request);
        }

        $ability = $this->config->get('pharmacie.access.ability');

        if (is_string($ability) && $ability !== '' && $this->gate->forUser($user)->allows($ability)) {
            return true;
        }

        return $this->hasAccessAttribute($user);
    }

    /**
     * Raison lisible d'un refus.
     */
    public function denialReason(?Authenticatable $user): string
    {
        if ($user === null) {
            return (string) trans('pharmacie::messages.access.no_session');
        }

        return (string) trans('pharmacie::messages.access.denied');
    }

    private function hasAccessAttribute(Authenticatable $user): bool
    {
        $attribute = $this->config->get('pharmacie.access.attribute');

        if (! is_string($attribute) || $attribute === '') {
            return false;
        }

        if (method_exists($user, $attribute)) {
            return (bool) $user->{$attribute}();
        }

        return (bool) ($user->{$attribute} ?? false);
    }
}
