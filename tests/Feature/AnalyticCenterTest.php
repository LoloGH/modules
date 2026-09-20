<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Database\QueryException;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Centres analytiques : une hiérarchie libre, et des lignes qu'on ne
 * supprime pas.
 */
class AnalyticCenterTest extends TestCase
{
    use CatalogFixtures;

    public function test_a_center_has_a_parent_and_children(): void
    {
        $pole = $this->makeCenter('PLATEAU-TECHNIQUE');
        $lab = $this->makeCenter('LABORATOIRE', $pole);
        $imagerie = $this->makeCenter('IMAGERIE', $pole);

        $this->assertSame($pole->id, $lab->parent->id);
        $this->assertEqualsCanonicalizing(
            [$lab->id, $imagerie->id],
            $pole->children()->pluck('id')->all(),
        );
        $this->assertNull($pole->parent);
    }

    public function test_the_active_scope_keeps_only_active_centers_by_name(): void
    {
        $this->makeCenter('IMAGERIE');
        $this->makeCenter('LABORATOIRE');
        $this->makeCenter('ARCHIVES', null, false);

        $this->assertSame(
            ['Imagerie', 'Laboratoire'],
            AnalyticCenter::query()->active()->pluck('name')->all(),
        );
    }

    public function test_a_center_with_children_cannot_be_deleted(): void
    {
        $pole = $this->makeCenter('PLATEAU-TECHNIQUE');
        $this->makeCenter('LABORATOIRE', $pole);

        $this->expectException(QueryException::class);

        $pole->delete();
    }

    public function test_a_center_with_acts_cannot_be_deleted(): void
    {
        $center = $this->makeCenter('LABORATOIRE');
        $this->makeAct('LAB-GE', $center);

        $this->expectException(QueryException::class);

        $center->delete();
    }

    public function test_an_act_with_a_tariff_cannot_be_deleted(): void
    {
        $act = $this->makeAct('LAB-GE', $this->makeCenter());
        $this->setTariff($act, 1_500);

        $this->expectException(QueryException::class);

        $act->delete();
    }

    public function test_a_center_carries_its_kind_and_its_label(): void
    {
        $center = AnalyticCenter::create([
            'code' => 'PHARMACIE',
            'name' => 'Pharmacie',
            'kind' => AnalyticCenter::KIND_BOTH,
        ]);

        // `is_active` est vrai par défaut : la valeur vient de la base, donc
        // on relit la ligne plutôt que l'objet qui vient d'être créé.
        $this->assertTrue($center->fresh()->is_active);
        $this->assertSame('Produits et charges', $center->kindLabel());
    }

    public function test_an_act_keeps_a_loose_link_to_a_dme_service(): void
    {
        $act = Act::create([
            'code' => 'CONS-GEN',
            'name' => 'Consultation générale',
            // Aucune table `dme_services` ici : le lien est mou, sans clé
            // étrangère, le module ne dépend pas de DME.
            'dme_service_id' => 4_242,
        ]);

        $this->assertSame(4_242, $act->fresh()->dme_service_id);
        $this->assertNull($act->center);
    }
}
