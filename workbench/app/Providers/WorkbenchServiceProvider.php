<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\DemoSetup;
use Workbench\App\Models\DemoUser;

/**
 * L'application hôte de démonstration : son modèle utilisateur, sa commande
 * de préparation et ses pages de connexion par profil.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('auth.providers.users.model', DemoUser::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoSetup::class]);
        }

        Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    }
}
