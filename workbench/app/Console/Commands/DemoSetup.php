<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Support\Rbac;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Workbench\App\Models\DemoUser;

/**
 * Prépare la base de démonstration : tables de l'hôte (utilisateurs, rôles),
 * tables du module, rôles et permissions, moyens de paiement, deux caisses et
 * un utilisateur par profil. Rejouable sans rien dupliquer.
 *
 * Réservé aux environnements local et testing.
 */
class DemoSetup extends Command
{
    protected $signature = 'finance:demo-setup';

    protected $description = 'Prépare la base de démonstration du module (SQLite, profils, caisses, moyens de paiement)';

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Réservé aux environnements local et testing.');

            return self::FAILURE;
        }

        // Une vue modifiée doit toujours être recompilée.
        $this->callSilently('view:clear');

        $database = (string) config('database.connections.sqlite.database');

        if ($database !== '' && $database !== ':memory:' && ! is_file($database)) {
            touch($database);
        }

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('roles')) {
            $root = dirname((string) (new ReflectionClass(PermissionRegistrar::class))->getFileName(), 2);
            (include $root.'/database/migrations/create_permission_tables.php.stub')->up();
        }

        $this->call('migrate', [
            '--path' => dirname(__DIR__, 4).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->call('finance:sync-permissions');
        $this->call('finance:sync-payment-methods');

        foreach ([['CAISSE-TICKET', 'Caisse Ticket'], ['CAISSE-SERVICES', 'Caisse Services']] as [$code, $name]) {
            CashRegister::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        $profiles = [
            ['Salif Konaté', 'caissier@keneya.test', Rbac::ROLE_CASHIER],
            ['Awa Traoré', 'caissier2@keneya.test', Rbac::ROLE_CASHIER],
            ['Moussa Diarra', 'comptable@keneya.test', Rbac::ROLE_ACCOUNTANT],
            ['Direction', 'direction@keneya.test', Rbac::ROLE_DIRECTOR],
            ['Administrateur', 'admin@keneya.test', Rbac::ROLE_ADMIN],
        ];

        foreach ($profiles as [$name, $email, $role]) {
            $user = DemoUser::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make(Str::random(40))],
            );

            $user->syncRoles([$role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Démonstration prête : ouvrez /dev pour choisir un profil.');

        return self::SUCCESS;
    }
}
