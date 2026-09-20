<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\SetTariff;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Tarification : un acte n'a qu'UN tarif actif par contexte, et changer un
 * prix crée une ligne au lieu d'en corriger une.
 */
class SetTariffTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    public function test_setting_a_tariff_creates_an_active_line_and_is_audited(): void
    {
        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        $tariff = $this->setTariff($act, 2_000, Tariff::KIND_STANDARD, null, $this->makeUser());

        $this->assertTrue($tariff->is_active);
        $this->assertSame(2_000, $tariff->amount);
        $this->assertSame(Carbon::today()->toDateString(), $tariff->effective_from->toDateString());
        $this->assertSame(1, AuditLog::where('event', 'tariff_set')->count());
    }

    public function test_changing_a_tariff_creates_a_new_line_and_deactivates_the_previous_one(): void
    {
        $act = $this->makeAct();

        $first = $this->setTariff($act, 2_000);
        $second = $this->setTariff($act, 2_500);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->is_active);

        // L'ancienne ligne garde son montant : c'est l'historique des prix.
        $this->assertSame(2_000, $first->fresh()->amount);
        $this->assertSame(2, $act->tariffs()->count());

        $this->assertSame(1, AuditLog::where('event', 'tariff_changed')->count());
    }

    public function test_an_act_never_has_two_active_tariffs_for_the_same_kind(): void
    {
        $act = $this->makeAct();

        $this->setTariff($act, 2_000);
        $this->setTariff($act, 2_500);
        $this->setTariff($act, 3_000);

        $this->assertSame(1, $act->tariffs()->where('kind', Tariff::KIND_STANDARD)->where('is_active', true)->count());
        $this->assertSame(3, $act->tariffs()->count());
    }

    public function test_two_kinds_live_side_by_side(): void
    {
        $act = $this->makeAct();

        $this->setTariff($act, 2_000);
        $this->setTariff($act, 1_200, Tariff::KIND_AGREEMENT, 'Tarif conventionné');

        $this->assertSame(2_000, $act->activeTariff()->amount);
        $this->assertSame(1_200, $act->activeTariff(Tariff::KIND_AGREEMENT)->amount);
        $this->assertSame('Tarif conventionné', $act->activeTariff(Tariff::KIND_AGREEMENT)->label);
    }

    public function test_the_active_tariff_is_the_latest_one(): void
    {
        $act = $this->makeAct();

        $this->setTariff($act, 2_000);
        $this->setTariff($act, 2_500);

        $this->assertSame(2_500, $act->activeTariff()->amount);
        $this->assertSame(2_500, (int) $act->fresh()->standardTariff->amount);
    }

    public function test_an_act_without_a_tariff_has_none(): void
    {
        $act = $this->makeAct();

        $this->assertNull($act->activeTariff());
        $this->assertNull($act->activeTariff(Tariff::KIND_AGREEMENT));
    }

    public function test_a_free_act_is_allowed_but_not_a_negative_one(): void
    {
        $act = $this->makeAct();

        $this->assertSame(0, $this->setTariff($act, 0)->amount);

        $this->assertViolation('ne peut pas être négatif', fn () => $this->setTariff($act, -1));
    }

    public function test_the_same_amount_twice_is_refused(): void
    {
        $act = $this->makeAct();

        $this->setTariff($act, 2_000);

        $this->assertViolation('est déjà à', fn () => $this->setTariff($act, 2_000));

        $this->assertSame(1, $act->tariffs()->count());
    }

    public function test_a_deactivated_act_cannot_be_priced(): void
    {
        $act = $this->makeAct();
        $act->update(['is_active' => false]);

        $this->assertViolation('est désactivé', fn () => $this->setTariff($act, 2_000));

        $this->assertSame(0, $act->tariffs()->count());
    }

    public function test_an_unreadable_effective_date_is_refused(): void
    {
        $act = $this->makeAct();

        $this->assertViolation(
            'date d\'application',
            fn () => app(SetTariff::class)->handle($act, 2_000, Tariff::KIND_STANDARD, null, 'la semaine prochaine'),
        );

        $this->assertSame(0, $act->tariffs()->count());
    }
}
