<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Console\Commands;

use Illuminate\Console\Command;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Support\AnalyticCenterDefaults;

/**
 * Crée les centres analytiques de départ, sans seeder.
 *
 * Idempotente : un centre existant (repéré par son code) n'est JAMAIS
 * modifié, l'établissement a pu le renommer, le rattacher à un pôle ou le
 * désactiver.
 *
 * Aucun acte ni tarif n'est créé : les prestations et leurs prix sont des
 * décisions de l'établissement, pas du module.
 */
final class SyncCatalog extends Command
{
    protected $signature = 'finance:sync-catalog';

    protected $description = 'Crée les centres analytiques de départ du module Finance (idempotent, sans seeder)';

    public function handle(): int
    {
        $created = 0;

        foreach (AnalyticCenterDefaults::all() as $default) {
            $center = AnalyticCenter::firstOrCreate(
                ['code' => $default['code']],
                [
                    'name' => $default['name'],
                    'kind' => $default['kind'],
                    'parent_id' => null,
                    'is_active' => true,
                ],
            );

            if ($center->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("{$created} centre(s) analytique(s) créé(s).");

        return self::SUCCESS;
    }
}
