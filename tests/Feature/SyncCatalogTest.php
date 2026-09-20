<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Support\AnalyticCenterDefaults;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * `finance:sync-catalog` remplace le seeder : elle doit être sans danger
 * sur une base qui contient déjà le catalogue de l'établissement.
 */
class SyncCatalogTest extends TestCase
{
    public function test_it_creates_the_starter_centers_once(): void
    {
        $this->artisan('finance:sync-catalog')->assertSuccessful();

        $this->assertSame(count(AnalyticCenterDefaults::all()), AnalyticCenter::count());
        $this->assertNotNull(AnalyticCenter::where('code', 'LABORATOIRE')->first());

        $this->artisan('finance:sync-catalog')->assertSuccessful();

        $this->assertSame(count(AnalyticCenterDefaults::all()), AnalyticCenter::count());
    }

    public function test_it_never_touches_a_center_the_facility_has_adjusted(): void
    {
        $this->artisan('finance:sync-catalog')->assertSuccessful();

        AnalyticCenter::where('code', 'IMAGERIE')->update([
            'name' => 'Imagerie médicale',
            'is_active' => false,
        ]);

        $this->artisan('finance:sync-catalog')->assertSuccessful();

        $imagerie = AnalyticCenter::where('code', 'IMAGERIE')->sole();

        $this->assertSame('Imagerie médicale', $imagerie->name);
        $this->assertFalse($imagerie->is_active);
    }

    public function test_it_creates_no_act_and_no_tariff(): void
    {
        $this->artisan('finance:sync-catalog')->assertSuccessful();

        // Les prestations et leurs prix sont des décisions de
        // l'établissement : le module n'en invente aucune.
        $this->assertSame(0, Act::count());
        $this->assertSame(0, Tariff::count());
    }
}
