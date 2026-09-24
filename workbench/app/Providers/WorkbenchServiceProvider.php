<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Pharmacie;
use Workbench\App\Console\Commands\DemoData;
use Workbench\App\Console\Commands\DemoSetup;
use Workbench\App\Models\DemoUser;
use Workbench\App\Pharmacy\DemoQueue;

/**
 * L'application hôte de démonstration : son modèle utilisateur, sa commande
 * de préparation, ses pages de connexion par profil — et sa file d'attente.
 *
 * Elle se comporte comme se comportera Keneya Workflow : c'est elle qui
 * décide qui entre, et c'est elle qui range les patients dans la file. Le
 * module, lui, ne fait que lire et servir.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('auth.providers.users.model', DemoUser::class);

        // La base de demonstration, au meme endroit pour la console et pour
        // le serveur web : le squelette de Testbench se purge a chaque
        // installation, et la commande et le serveur ne lisaient alors pas
        // le meme fichier.
        $database = dirname(__DIR__, 3).'/database/demo.sqlite';

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0777, true);
        }

        if (! is_file($database)) {
            touch($database);
        }

        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', $database);

        // La file d'attente de l'hôte de test : quelques patients en dur,
        // pour que le comptoir soit navigable avant l'intégration.
        $this->app->singleton(PharmacyQueueProvider::class, DemoQueue::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoSetup::class, DemoData::class]);
        }

        // L'hôte décide qui entre : ici, tout profil connecté qui porte une
        // permission du module.
        Pharmacie::authorizeAccessUsing(
            fn ($user) => $user !== null && $user->can('pharmacie.access'),
        );

        Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    }
}
