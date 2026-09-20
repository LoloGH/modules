<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Console\Commands;

use Illuminate\Console\Command;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Support\PaymentMethodDefaults;

/**
 * Crée les moyens de paiement de départ, sans seeder.
 *
 * Idempotente : un moyen existant (repéré par son code) n'est JAMAIS
 * modifié, l'établissement a pu le renommer ou le désactiver.
 */
final class SyncPaymentMethods extends Command
{
    protected $signature = 'finance:sync-payment-methods';

    protected $description = 'Crée les moyens de paiement de départ du module Finance (idempotent, sans seeder)';

    public function handle(): int
    {
        $created = 0;

        foreach (PaymentMethodDefaults::all() as $index => $default) {
            $method = PaymentMethod::firstOrCreate(
                ['code' => $default['code']],
                [
                    'name' => $default['name'],
                    'kind' => $default['kind'],
                    'requires_reference' => $default['requires_reference'],
                    'is_active' => true,
                    'position' => ($index + 1) * 10,
                ],
            );

            if ($method->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("{$created} moyen(s) de paiement créé(s).");

        return self::SUCCESS;
    }
}
