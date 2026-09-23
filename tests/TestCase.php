<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\PharmacieServiceProvider;
use Keneya\Pharmacie\Tests\Support\TestUser;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Socle des tests du module.
 *
 * Les tests s'exécutent dans une application Laravel minimale fournie par
 * Orchestra Testbench : le module monté chez un hôte. Le mode autonome est
 * inactif par défaut, et l'accès de haut niveau est accordé comme le ferait
 * un hôte, par une capacité ; les tests de refus la retirent.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PharmacieServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Vues compilées dans un dossier propre à ce lancement : une copie
        // compilée d'une session précédente ne peut jamais masquer une vue
        // modifiée (Laravel ne recompile que si le fichier source est plus
        // récent, ce qui n'est pas garanti après une extraction d'archive).
        $compiled = sys_get_temp_dir().'/pharmacie-views-'.getmypid();

        if (! is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }

        $config->set('view.compiled', $compiled);
        $config->set('database.default', 'testing');
        $config->set('queue.default', 'sync');
        $config->set('app.locale', 'fr');
        $config->set('app.fallback_locale', 'fr');

        // L'hôte désigne le modèle utilisateur.
        $config->set('auth.providers.users.model', TestUser::class);

        // Module monté chez un hôte : pas de mode autonome.
        $config->set('pharmacie.standalone.enabled', false);
    }

    /**
     * Un hôte a sa propre page de connexion : le module s'y repose et n'en
     * fournit aucune.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/connexion-hote', static fn () => 'Connexion de l\'application hôte')
            ->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Pharmacie::flushState();

        $this->createTables();

        // L'hôte accorde l'accès au module. Les tests de la porte d'entrée
        // redéfinissent cette capacité pour vérifier le refus.
        $this->grantHostAccess();
    }

    protected function tearDown(): void
    {
        Pharmacie::flushState();

        parent::tearDown();
    }

    /**
     * Tables de l'hôte : les utilisateurs, et les rôles et permissions de
     * spatie (migration du paquet, jouée telle quelle).
     */
    protected function createTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $root = dirname((string) (new ReflectionClass(PermissionRegistrar::class))->getFileName(), 2);
        $stub = $root.'/database/migrations/create_permission_tables.php.stub';

        if (! is_file($stub)) {
            throw new RuntimeException("Migration spatie introuvable : {$stub}");
        }

        (include $stub)->up();

        // Migrations du module. Le chemin est explicite : sans lui, Testbench
        // rejouerait aussi ses propres migrations et la table `users`
        // ci-dessus serait créée deux fois.
        // Les migrations du module : aucune pour l'instant, le stock et la
        // dispensation viendront. On ne lance `migrate` que lorsqu'il y a
        // quelque chose à migrer, sinon Testbench rejouerait les siennes.
        if (glob(dirname(__DIR__).'/database/migrations/*.php') !== []) {
            $this->artisan('migrate', [
                '--path' => dirname(__DIR__).'/database/migrations',
                '--realpath' => true,
            ])->assertSuccessful();
        }
    }

    /**
     * Simule la décision d'accès de l'application hôte.
     */
    protected function grantHostAccess(bool $granted = true): void
    {
        Gate::define('pharmacie.access', static fn () => $granted);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(array $attributes = []): TestUser
    {
        static $sequence = 0;
        $sequence++;

        return TestUser::create(array_merge([
            'name' => "Utilisateur {$sequence}",
            'email' => "utilisateur{$sequence}@keneya.test",
            'password' => 'secret',
        ], $attributes));
    }

    protected function userWithRole(string $role): TestUser
    {
        $user = $this->makeUser();

        Role::findOrCreate($role, (string) config('auth.defaults.guard', 'web'));
        $user->assignRole($role);

        return $user->fresh();
    }
}
