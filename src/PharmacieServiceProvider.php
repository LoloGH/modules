<?php

declare(strict_types=1);

namespace Keneya\Pharmacie;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Keneya\Pharmacie\Access\PharmacieAccessGate;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Console\Commands\SyncPermissions;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Http\Middleware\EnsureHostGrantsAccess;
use Keneya\Pharmacie\Queue\NoPharmacyQueue;
use Keneya\Pharmacie\Sales\NoSaleSink;
use Keneya\Pharmacie\Standalone\StandaloneMode;
use Keneya\Pharmacie\Support\Money;

/**
 * Montage du module Pharmacie (stock, dispensation) dans une application
 * Laravel hôte.
 *
 * Le module n'exige de l'hôte qu'une session authentifiée et une décision
 * d'accès : routes préfixées, vues et traductions dans un espace de noms
 * propre (`pharmacie::`), migrations chargées depuis le paquet.
 *
 * Ce qui appartient à l'hôte et n'est jamais imposé ici : la décision
 * d'accès, le modèle utilisateur, la page de connexion, la file d'attente et
 * la destination de ce qui doit être payé.
 */
class PharmacieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pharmacie.php', 'pharmacie');

        $this->app->singleton(StandaloneMode::class);
        $this->app->singleton(PharmacieAccessGate::class);
        $this->app->singleton(Auditor::class);

        // La file d'attente et la destination des ventes : l'hôte les
        // fournit en liant ses propres implémentations. `singletonIf` pour ne
        // jamais écraser celle qu'un hôte aurait enregistrée avant le module.
        $this->app->singletonIf(PharmacyQueueProvider::class, NoPharmacyQueue::class);
        $this->app->singletonIf(SaleSink::class, NoSaleSink::class);
    }

    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->registerResources();
        $this->registerRoutes();
        $this->registerPublishing();
        $this->registerCommands();
        $this->warnAboutStandaloneInProduction();
    }

    private function registerMiddlewareAliases(): void
    {
        $this->app['router']->aliasMiddleware('pharmacie.access', EnsureHostGrantsAccess::class);
    }

    private function registerResources(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pharmacie');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'pharmacie');

        // Composants anonymes du module : `<x-pharmacie::card>`, `<x-pharmacie::icon>`…
        // Mécanisme natif de Blade, préfixé comme les vues pour ne jamais
        // entrer en collision avec ceux de l'hôte ou des autres modules.
        Blade::anonymousComponentNamespace('pharmacie::components', 'pharmacie');

        // `$money($montant)` dans les vues : « 5 000 FCFA ».
        View::composer('pharmacie::*', static function ($view): void {
            $view->with('money', static fn (int $amount): string => Money::format($amount));
        });
    }

    /**
     * Les routes vivent sous leur propre préfixe d'URL et de nom
     * (`pharmacie.`) pour ne jamais entrer en collision avec celles de
     * l'hôte ou des autres modules.
     */
    private function registerRoutes(): void
    {
        $config = $this->app['config'];

        Route::group([
            'prefix' => $config->get('pharmacie.route.prefix', 'pharmacie'),
            'as' => $config->get('pharmacie.route.name', 'pharmacie.'),
            'middleware' => (array) $config->get('pharmacie.route.middleware', ['web', 'pharmacie.access']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/pharmacie.php' => $this->app->configPath('pharmacie.php'),
        ], 'pharmacie-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/pharmacie'),
        ], 'pharmacie-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/pharmacie'),
        ], 'pharmacie-lang');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([SyncPermissions::class]);
    }

    /**
     * Le mode autonome ne s'active jamais en production : on le signale dans
     * le journal plutôt que de l'ignorer en silence.
     */
    private function warnAboutStandaloneInProduction(): void
    {
        if ($this->app->make(StandaloneMode::class)->refusedInProduction()) {
            Log::warning(
                'PHARMACIE_STANDALONE_DEV est demandé mais ignoré : le mode autonome '
                .'de développement ne s\'active jamais en production.'
            );
        }
    }
}
