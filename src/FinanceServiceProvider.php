<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Keneya\FinanceCaisse\Access\FinanceAccessGate;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Console\Commands\SyncCatalog;
use Keneya\FinanceCaisse\Console\Commands\SyncPaymentMethods;
use Keneya\FinanceCaisse\Console\Commands\SyncPermissions;
use Keneya\FinanceCaisse\Http\Middleware\EnsureHostGrantsAccess;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Standalone\StandaloneMode;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Montage du module Finance (caisse, facturation) dans une application
 * Laravel hôte.
 *
 * Le module n'exige de l'hôte qu'une session authentifiée et une décision
 * d'accès : routes préfixées, vues et traductions dans un espace de noms
 * propre (`finance::`), migrations chargées depuis le paquet.
 *
 * Ce qui appartient à l'hôte et n'est jamais imposé ici : la décision
 * d'accès de haut niveau, le modèle utilisateur, la page de connexion.
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/finance.php', 'finance');

        $this->app->singleton(StandaloneMode::class);
        $this->app->singleton(FinanceAccessGate::class);
        $this->app->singleton(Auditor::class);
        $this->app->singleton(NumberGenerator::class);
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
        $this->app['router']->aliasMiddleware('finance.access', EnsureHostGrantsAccess::class);
    }

    private function registerResources(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'finance');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'finance');

        // `$money($montant)` dans les vues : « 5 000 FCFA ».
        View::composer('finance::*', static function ($view): void {
            $view->with('money', static fn (int $amount): string => Money::format($amount));
        });
    }

    /**
     * Les routes vivent sous leur propre préfixe d'URL et de nom (`finance.`)
     * pour ne jamais entrer en collision avec celles de l'hôte.
     */
    private function registerRoutes(): void
    {
        $config = $this->app['config'];

        Route::group([
            'prefix' => $config->get('finance.route.prefix', 'finance'),
            'as' => $config->get('finance.route.name', 'finance.'),
            'middleware' => (array) $config->get('finance.route.middleware', ['web', 'finance.access']),
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
            __DIR__.'/../config/finance.php' => $this->app->configPath('finance.php'),
        ], 'finance-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/finance'),
        ], 'finance-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/finance'),
        ], 'finance-lang');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([SyncPermissions::class, SyncPaymentMethods::class, SyncCatalog::class]);
    }

    /**
     * Le mode autonome ne s'active jamais en production : on le signale
     * dans le journal plutôt que de l'ignorer en silence.
     */
    private function warnAboutStandaloneInProduction(): void
    {
        if ($this->app->make(StandaloneMode::class)->refusedInProduction()) {
            Log::warning(
                'FINANCE_STANDALONE_DEV est demandé mais ignoré : le mode autonome '
                .'de développement ne s\'active jamais en production.'
            );
        }
    }
}
