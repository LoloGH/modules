<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Keneya\FinanceCaisse\Actions\SetConsultationTicket;
use Keneya\FinanceCaisse\Catalog\CatalogAct;
use Keneya\FinanceCaisse\Catalog\EloquentCatalogProvider;
use Keneya\FinanceCaisse\Contracts\CatalogProvider;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Le catalogue exposé à l'hôte : ce qu'il voit, et surtout ce qu'il ne voit
 * pas (actes désactivés, actes d'un autre service, modèles Eloquent).
 */
class CatalogProviderTest extends TestCase
{
    use CatalogFixtures;

    private const SERVICE = 7;

    private const OTHER_SERVICE = 8;

    public function test_finance_catalog_resolves_the_contract_from_the_container(): void
    {
        $this->assertInstanceOf(CatalogProvider::class, Finance::catalog());
        $this->assertInstanceOf(EloquentCatalogProvider::class, Finance::catalog());

        // Singleton : l'hôte peut le remplacer, et tout le monde reçoit le même.
        $this->assertSame(Finance::catalog(), app(CatalogProvider::class));
    }

    public function test_the_host_can_swap_the_implementation(): void
    {
        $fake = \Mockery::mock(CatalogProvider::class);
        $this->app->instance(CatalogProvider::class, $fake);

        $this->assertSame($fake, Finance::catalog());
    }

    public function test_acts_for_a_service_include_its_own_acts_and_the_generic_ones(): void
    {
        $this->makeAct('ECHO', null, true, self::SERVICE);
        $this->makeAct('CONS-GEN');

        $codes = $this->codes(Finance::catalog()->actsForService(self::SERVICE));

        $this->assertSame(['ECHO', 'CONS-GEN'], $codes);
    }

    public function test_acts_for_a_service_exclude_inactive_acts_and_other_services(): void
    {
        $this->makeAct('ECHO', null, true, self::SERVICE);
        $this->makeAct('RADIO', null, false, self::SERVICE);
        $this->makeAct('NFS', null, true, self::OTHER_SERVICE);
        $this->makeAct('VIEUX', null, false);

        $this->assertSame(['ECHO'], $this->codes(Finance::catalog()->actsForService(self::SERVICE)));
    }

    public function test_without_a_service_only_generic_acts_are_proposed(): void
    {
        $this->makeAct('ECHO', null, true, self::SERVICE);
        $this->makeAct('CONS-GEN');

        $this->assertSame(['CONS-GEN'], $this->codes(Finance::catalog()->actsForService(null)));
    }

    public function test_acts_are_sorted_service_first_then_by_name(): void
    {
        foreach (['Z-GEN' => null, 'B-SRV' => self::SERVICE, 'A-GEN' => null, 'A-SRV' => self::SERVICE] as $code => $service) {
            $act = $this->makeAct($code, null, true, $service);
            $act->update(['name' => $code]);
        }

        $this->assertSame(
            ['A-SRV', 'B-SRV', 'A-GEN', 'Z-GEN'],
            $this->codes(Finance::catalog()->actsForService(self::SERVICE)),
        );
    }

    public function test_the_dto_carries_the_act_and_its_standard_tariff(): void
    {
        $center = $this->makeCenter('IMAGERIE');
        $act = $this->makeAct('ECHO', $center, true, self::SERVICE);
        $this->setTariff($act, 15_000);
        $this->setTariff($act, 9_000, Tariff::KIND_AGREEMENT);

        $dto = Finance::catalog()->findAct($act->id);

        $this->assertInstanceOf(CatalogAct::class, $dto);
        $this->assertSame($act->id, $dto->id);
        $this->assertSame('ECHO', $dto->code);
        $this->assertSame('Acte ECHO', $dto->name);
        $this->assertSame(self::SERVICE, $dto->hostServiceId);
        $this->assertSame($center->id, $dto->analyticCenterId);
        $this->assertSame(15_000, $dto->activeAmount);
        $this->assertFalse($dto->isGeneric());
    }

    public function test_an_act_without_tariff_has_no_amount(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->assertNull(Finance::catalog()->findAct($act->id)->activeAmount);
        $this->assertTrue(Finance::catalog()->findAct($act->id)->isGeneric());
    }

    public function test_find_act_ignores_unknown_and_inactive_acts(): void
    {
        $inactive = $this->makeAct('VIEUX', null, false);

        $this->assertNull(Finance::catalog()->findAct(999));
        $this->assertNull(Finance::catalog()->findAct($inactive->id));
    }

    public function test_the_dto_never_leaks_the_eloquent_model(): void
    {
        $act = $this->makeAct('ECHO', $this->makeCenter('IMAGERIE'), true, self::SERVICE);
        $this->setTariff($act, 15_000);

        $dto = Finance::catalog()->findAct($act->id);

        $this->assertNotInstanceOf(Model::class, $dto);

        // Rien que des scalaires : aucune propriété ne peut transporter un modèle.
        $class = new ReflectionClass($dto);
        $this->assertTrue($class->isReadOnly());
        $this->assertTrue($class->isFinal());

        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertTrue($type->isBuiltin(), "{$property->getName()} n'est pas un scalaire");
        }

        $this->assertSame(
            ['id', 'code', 'name', 'hostServiceId', 'analyticCenterId', 'activeAmount'],
            array_map(static fn ($p) => $p->getName(), $class->getProperties()),
        );

        foreach (Finance::catalog()->actsForService(self::SERVICE) as $item) {
            $this->assertInstanceOf(CatalogAct::class, $item);
        }
    }

    public function test_active_tariff_for_returns_the_active_amount_per_kind(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $this->setTariff($act, 2_000);
        $this->setTariff($act, 1_200, Tariff::KIND_AGREEMENT);

        $this->assertSame(2_000, Finance::catalog()->activeTariffFor($act->id));
        $this->assertSame(1_200, Finance::catalog()->activeTariffFor($act->id, Tariff::KIND_AGREEMENT));
    }

    public function test_active_tariff_for_is_null_without_tariff_for_unknown_or_inactive_acts(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->assertNull(Finance::catalog()->activeTariffFor($act->id));

        $this->setTariff($act, 2_000);
        $this->assertNull(Finance::catalog()->activeTariffFor($act->id, 'assureur-inconnu'));
        $this->assertNull(Finance::catalog()->activeTariffFor(999));

        $act->update(['is_active' => false]);
        $this->assertNull(Finance::catalog()->activeTariffFor($act->id));
    }

    public function test_active_tariff_for_follows_set_tariff(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->setTariff($act, 2_000);
        $this->assertSame(2_000, Finance::catalog()->activeTariffFor($act->id));

        $this->setTariff($act, 2_500);
        $this->assertSame(2_500, Finance::catalog()->activeTariffFor($act->id));
        $this->assertSame(2_500, Finance::catalog()->findAct($act->id)->activeAmount);

        // Gratuit n'est pas « non fixé ».
        $this->setTariff($act, 0);
        $this->assertSame(0, Finance::catalog()->activeTariffFor($act->id));
    }

    public function test_ticket_act_is_null_until_one_is_marked(): void
    {
        $this->makeAct('CONS-GEN');

        $this->assertNull(Finance::catalog()->ticketAct());
    }

    public function test_ticket_act_returns_the_marked_act_with_its_price(): void
    {
        $ticket = $this->makeAct('TICKET');
        $this->setTariff($ticket, 1_000);
        app(SetConsultationTicket::class)->handle($ticket, true);

        $dto = Finance::catalog()->ticketAct();

        $this->assertSame('TICKET', $dto->code);
        $this->assertSame(1_000, $dto->activeAmount);
    }

    public function test_ticket_act_follows_the_latest_marking(): void
    {
        $first = $this->makeAct('TICKET-A');
        $second = $this->makeAct('TICKET-B');

        app(SetConsultationTicket::class)->handle($first, true);
        app(SetConsultationTicket::class)->handle($second, true);

        $this->assertSame('TICKET-B', Finance::catalog()->ticketAct()->code);
    }

    public function test_a_deactivated_ticket_is_not_exposed(): void
    {
        $ticket = $this->makeAct('TICKET');
        app(SetConsultationTicket::class)->handle($ticket, true);

        $ticket->update(['is_active' => false]);

        $this->assertNull(Finance::catalog()->ticketAct());
    }

    /**
     * @param  iterable<CatalogAct>  $acts
     * @return list<string>
     */
    private function codes(iterable $acts): array
    {
        $codes = [];

        foreach ($acts as $act) {
            $codes[] = $act->code;
        }

        return $codes;
    }
}
