<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Keneya\Pharmacie\Actions\DispenseProducts;
use Keneya\Pharmacie\Actions\MoveStock;
use Keneya\Pharmacie\Actions\RecordLoss;
use Keneya\Pharmacie\Actions\RecordReception;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Supplier;
use Keneya\Pharmacie\Support\Rbac;
use Workbench\App\Models\DemoUser;

/**
 * Remplit la pharmacie de démonstration : un catalogue, des emplacements, un
 * fournisseur, des réceptions, des dispensations et une perte.
 *
 * Tout passe par les actions réelles (réception, dispensation, perte) et
 * jamais par des écritures directes en base : les données de démonstration
 * empruntent donc exactement les chemins que l'application emprunte, et ce
 * qu'on voit à l'écran est ce que le module sait vraiment produire.
 *
 * Rejouable : si le catalogue existe déjà, la commande ne fait rien.
 */
class DemoData extends Command
{
    protected $signature = 'pharmacie:demo-data {--force : Ajouter même si le catalogue existe déjà}';

    protected $description = 'Remplit la pharmacie de démonstration (catalogue, stock, dispensations)';

    public function handle(
        RecordReception $receptions,
        MoveStock $transfers,
        DispenseProducts $dispensing,
        RecordLoss $losses,
    ): int {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('Réservé aux environnements local et testing.');

            return self::FAILURE;
        }

        if (Product::query()->exists() && ! $this->option('force')) {
            $this->info('Le catalogue contient déjà des produits : rien à faire (--force pour insister).');

            return self::SUCCESS;
        }

        $pharmacist = $this->profile(Rbac::ROLE_PHARMACIST);
        $storekeeper = $this->profile(Rbac::ROLE_STOREKEEPER);

        if ($pharmacist === null || $storekeeper === null) {
            $this->error('Profils de démonstration absents : lancez d\'abord pharmacie:demo-setup.');

            return self::FAILURE;
        }

        $comptoir = Location::firstOrCreate(
            ['facility_id' => 1, 'code' => 'COMPTOIR'],
            ['name' => 'Comptoir', 'kind' => Location::KIND_PHARMACY, 'is_active' => true],
        );

        $reserve = Location::firstOrCreate(
            ['facility_id' => 1, 'code' => 'RESERVE'],
            ['name' => 'Réserve', 'kind' => Location::KIND_STORE, 'is_active' => true],
        );

        $categories = [];

        foreach ([
            'ANTIB' => 'Antibiotiques',
            'ANTAL' => 'Antalgiques',
            'ANTIP' => 'Antipaludiques',
            'STUPE' => 'Produits sous contrôle',
            'CONSO' => 'Consommables',
        ] as $code => $name) {
            $categories[$code] = Category::firstOrCreate(
                ['facility_id' => 1, 'code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }

        $catalogue = [
            ['AMOX500', 'Amoxicilline', 'Amoxicilline', 'ANTIB', 'Gélule', '500 mg', 'Orale', 'gélule', 100, 150],
            ['PARA500', 'Paracétamol', 'Paracétamol', 'ANTAL', 'Comprimé', '500 mg', 'Orale', 'comprimé', 200, 50],
            ['IBUP400', 'Ibuprofène', 'Ibuprofène', 'ANTAL', 'Comprimé', '400 mg', 'Orale', 'comprimé', 100, 75],
            ['ARTE20', 'Artéméther-Luméfantrine', 'Artéméther/Luméfantrine', 'ANTIP', 'Comprimé', '20/120 mg', 'Orale', 'comprimé', 60, 300],
            ['CEFT1G', 'Ceftriaxone', 'Ceftriaxone', 'ANTIB', 'Poudre injectable', '1 g', 'Injectable', 'flacon', 20, 1_500],
            ['MORPH10', 'Morphine', 'Sulfate de morphine', 'STUPE', 'Ampoule', '10 mg/ml', 'Injectable', 'ampoule', 10, 2_000],
            ['GANT-M', 'Gants d\'examen (M)', null, 'CONSO', 'Boîte de 100', null, null, 'boîte', 15, 4_500],
            ['COMP-S', 'Compresses stériles', null, 'CONSO', 'Sachet', null, null, 'sachet', 50, 200],
        ];

        $products = [];

        foreach ($catalogue as [$code, $name, $dci, $category, $form, $dosage, $route, $unit, $threshold, $price]) {
            $products[$code] = Product::firstOrCreate(
                ['facility_id' => 1, 'code' => $code],
                [
                    'name' => $name,
                    'dci' => $dci,
                    'category_id' => $categories[$category]->id,
                    'kind' => $category === 'CONSO' ? Product::KIND_CONSUMABLE : Product::KIND_MEDICINE,
                    'form' => $form,
                    'dosage' => $dosage,
                    'route' => $route,
                    'unit' => $unit,
                    'min_threshold' => $threshold,
                    'sale_price' => $price,
                    'is_controlled' => $code === 'MORPH10',
                    'is_active' => true,
                ],
            );
        }

        $supplier = Supplier::firstOrCreate(
            ['facility_id' => 1, 'code' => 'UBIPHARM'],
            [
                'name' => 'Ubipharm Mali',
                'contact_name' => 'Service commandes',
                'phone' => '+223 20 22 33 44',
                'lead_time_days' => 21,
                'is_active' => true,
            ],
        );

        // Deux réceptions : l'une ancienne avec un lot qui périme bientôt,
        // l'autre récente. De quoi voir le FEFO fonctionner à l'écran.
        $receptions->handle($supplier, $reserve, [
            ['product_id' => $products['AMOX500']->id, 'batch_number' => 'AMX-2401', 'quantity' => 400, 'expires_on' => now()->addDays(40)->toDateString(), 'unit_price' => 90],
            ['product_id' => $products['PARA500']->id, 'batch_number' => 'PAR-2405', 'quantity' => 1_000, 'expires_on' => now()->addMonths(18)->toDateString(), 'unit_price' => 25],
            ['product_id' => $products['ARTE20']->id, 'batch_number' => 'ART-2312', 'quantity' => 150, 'expires_on' => now()->addDays(20)->toDateString(), 'unit_price' => 180],
            ['product_id' => $products['COMP-S']->id, 'batch_number' => 'CMP-2402', 'quantity' => 300, 'expires_on' => now()->addYears(3)->toDateString(), 'unit_price' => 120],
        ], $storekeeper, ['delivery_note' => 'BL-2026-0141']);

        $receptions->handle($supplier, $comptoir, [
            ['product_id' => $products['AMOX500']->id, 'batch_number' => 'AMX-2512', 'quantity' => 600, 'expires_on' => now()->addMonths(20)->toDateString(), 'unit_price' => 95],
            ['product_id' => $products['IBUP400']->id, 'batch_number' => 'IBU-2506', 'quantity' => 500, 'expires_on' => now()->addMonths(24)->toDateString(), 'unit_price' => 40],
            ['product_id' => $products['CEFT1G']->id, 'batch_number' => 'CEF-2503', 'quantity' => 80, 'expires_on' => now()->addMonths(14)->toDateString(), 'unit_price' => 900],
            ['product_id' => $products['MORPH10']->id, 'batch_number' => 'MOR-2501', 'quantity' => 40, 'expires_on' => now()->addMonths(16)->toDateString(), 'unit_price' => 1_200],
            ['product_id' => $products['GANT-M']->id, 'batch_number' => 'GNT-2504', 'quantity' => 25, 'expires_on' => now()->addYears(4)->toDateString(), 'unit_price' => 3_000],
        ], $storekeeper, ['delivery_note' => 'BL-2026-0158']);

        // La réserve réapprovisionne le comptoir : le magasinier demande,
        // le pharmacien approuve, jamais la même personne.
        $transfer = $transfers->request($reserve, $comptoir, [
            ['product_id' => $products['ARTE20']->id, 'quantity' => 100],
            ['product_id' => $products['PARA500']->id, 'quantity' => 400],
        ], $storekeeper, 'Réassort du comptoir');

        $transfers->approve($transfer, $pharmacist);
        $transfer = $transfers->send($transfer, $storekeeper);

        $transfers->receive(
            $transfer,
            $transfer->lines()->get()->mapWithKeys(
                static fn ($line): array => [$line->id => ['quantity' => (int) $line->quantity]],
            )->all(),
            $pharmacist,
        );

        // Quelques dispensations, dont une partielle et une sur ordonnance.
        $dispensing->handle($comptoir, [
            ['product_id' => $products['AMOX500']->id, 'quantity' => 21, 'prescribed_quantity' => 21, 'posology' => '1 gélule matin, midi et soir pendant 7 jours'],
            ['product_id' => $products['PARA500']->id, 'quantity' => 20, 'prescribed_quantity' => 20, 'posology' => '1 comprimé si douleur'],
        ], $pharmacist, [
            'patient_id' => 'PAT-004512',
            'patient_name' => 'Aminata Traoré',
            'prescription_ref' => 'ORD-2026-0311',
        ]);

        $dispensing->handle($comptoir, [
            ['product_id' => $products['ARTE20']->id, 'quantity' => 24, 'prescribed_quantity' => 24, 'posology' => '4 comprimés matin et soir pendant 3 jours'],
        ], $pharmacist, ['patient_id' => 'PAT-004610', 'patient_name' => 'Moussa Keïta']);

        $dispensing->handle($comptoir, [
            // Plus que ce que le comptoir peut servir : le reliquat reste
            // visible, comme il le sera en vrai.
            ['product_id' => $products['CEFT1G']->id, 'quantity' => 70, 'prescribed_quantity' => 120, 'posology' => '1 flacon par jour'],
        ], $pharmacist, ['patient_id' => 'PAT-004702', 'patient_name' => 'Fatoumata Diallo']);

        $dispensing->handle($comptoir, [
            ['product_id' => $products['MORPH10']->id, 'quantity' => 4, 'prescribed_quantity' => 4, 'posology' => '1 ampoule toutes les 6 heures'],
        ], $pharmacist, [
            'patient_id' => 'PAT-004755',
            'patient_name' => 'Sékou Sangaré',
            'prescription_ref' => 'ORD-2026-0327',
        ]);

        // Une casse, pour que l'écran des pertes ne soit pas vide.
        $losses->handle(
            Batch::query()->where('product_id', $products['CEFT1G']->id)->firstOrFail(),
            $comptoir,
            2,
            Loss::KIND_BROKEN,
            'Flacons tombés lors du rangement',
            $storekeeper,
            ['witness' => 'Aïssata Cissé'],
        );

        $this->info('Pharmacie de démonstration remplie : catalogue, stock, dispensations et une perte.');
        $this->line('Ouvrez /dev, choisissez un profil, puis /pharmacie.');

        return self::SUCCESS;
    }

    private function profile(string $role): ?DemoUser
    {
        return DemoUser::query()->get()->first(
            static fn (DemoUser $user): bool => $user->hasRole($role),
        );
    }
}
