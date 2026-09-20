<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Standalone;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Mode autonome de développement.
 *
 * Il accorde l'accès au module sans hôte. Sur des données financières, un
 * tel accès ne doit jamais exister en production : il est refusé quelle que
 * soit la valeur de la variable d'environnement.
 */
final class StandaloneMode
{
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    /**
     * Le mode est-il demandé par la configuration ?
     */
    public function requested(): bool
    {
        return (bool) $this->config->get('finance.standalone.enabled', false);
    }

    /**
     * Le mode est-il demandé alors que l'application tourne en production ?
     */
    public function refusedInProduction(): bool
    {
        return $this->requested() && $this->app->environment('production');
    }

    /**
     * Le mode est-il réellement actif ?
     */
    public function enabled(): bool
    {
        return $this->requested() && ! $this->app->environment('production');
    }
}
