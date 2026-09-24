<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Keneya\FinanceCaisse\Finance;
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

        // La base de demonstration, au meme endroit pour la console et pour
        // le serveur web : le squelette de Testbench se purge a chaque
        // installation, et la commande et le serveur ne lisaient alors pas
        // le meme fichier. Ce qu'un `finance:demo-setup` venait d'ecrire
        // disparaissait donc avant la premiere requete.
        $database = dirname(__DIR__, 3).'/database/demo.sqlite';

        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0777, true);
        }

        if (! is_file($database)) {
            touch($database);
        }

        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', $database);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoSetup::class]);
        }

        // Le retour vers l'« hote » : ici, la page qui choisit un profil.
        // Dans WorkFlow, ce sera l'espace de travail de la personne.
        Finance::returnLinkUsing(fn ($user) => $user === null ? null : [
            'label' => "Retour à l'hôte de démonstration",
            'url' => url('/dev'),
        ]);

        Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    }
}
