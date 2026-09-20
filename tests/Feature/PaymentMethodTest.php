<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Database\QueryException;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Support\PaymentMethodDefaults;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Les moyens de paiement sont des données, pas du code : on peut en ajouter
 * sans toucher au module, et la commande de départ ne reprend rien à
 * l'établissement.
 */
class PaymentMethodTest extends TestCase
{
    public function test_the_command_creates_the_starting_methods_once(): void
    {
        $this->artisan('finance:sync-payment-methods')->assertSuccessful();

        $this->assertSame(count(PaymentMethodDefaults::all()), PaymentMethod::count());

        $this->artisan('finance:sync-payment-methods')->assertSuccessful();

        $this->assertSame(count(PaymentMethodDefaults::all()), PaymentMethod::count());
    }

    public function test_an_existing_method_is_never_modified(): void
    {
        PaymentMethod::create([
            'code' => 'especes',
            'name' => 'Cash de la caisse',
            'kind' => PaymentMethod::KIND_CASH,
            'is_active' => false,
        ]);

        $this->artisan('finance:sync-payment-methods')->assertSuccessful();

        $especes = PaymentMethod::where('code', 'especes')->sole();

        $this->assertSame('Cash de la caisse', $especes->name);
        $this->assertFalse($especes->is_active);
        $this->assertSame(count(PaymentMethodDefaults::all()), PaymentMethod::count());
    }

    public function test_only_cash_is_counted_in_the_drawer(): void
    {
        $this->artisan('finance:sync-payment-methods')->assertSuccessful();

        $cash = PaymentMethod::all()->filter->isCash();

        $this->assertCount(1, $cash);
        $this->assertSame('especes', $cash->first()->code);
    }

    public function test_a_reference_is_required_only_where_it_makes_sense(): void
    {
        $this->artisan('finance:sync-payment-methods')->assertSuccessful();

        $this->assertFalse(PaymentMethod::where('code', 'especes')->sole()->requires_reference);
        $this->assertFalse(PaymentMethod::where('code', 'compte_patient')->sole()->requires_reference);
        $this->assertTrue(PaymentMethod::where('code', 'mobile_money')->sole()->requires_reference);
        $this->assertTrue(PaymentMethod::where('code', 'cheque')->sole()->requires_reference);
    }

    public function test_only_active_methods_are_offered_in_order(): void
    {
        PaymentMethod::create(['code' => 'b', 'name' => 'Deuxième', 'kind' => PaymentMethod::KIND_CARD, 'position' => 20]);
        PaymentMethod::create(['code' => 'a', 'name' => 'Première', 'kind' => PaymentMethod::KIND_CASH, 'position' => 10]);
        PaymentMethod::create(['code' => 'c', 'name' => 'Désactivée', 'kind' => PaymentMethod::KIND_CHEQUE, 'position' => 5, 'is_active' => false]);

        $this->assertSame(['a', 'b'], PaymentMethod::active()->pluck('code')->all());
    }

    public function test_a_new_method_can_be_added_without_touching_the_module(): void
    {
        PaymentMethod::create(['code' => 'orange_money', 'name' => 'Orange Money', 'kind' => PaymentMethod::KIND_MOBILE_MONEY, 'requires_reference' => true]);

        $this->assertTrue(PaymentMethod::active()->where('code', 'orange_money')->exists());
    }

    public function test_two_methods_cannot_share_a_code(): void
    {
        PaymentMethod::create(['code' => 'especes', 'name' => 'Espèces', 'kind' => PaymentMethod::KIND_CASH]);

        $this->expectException(QueryException::class);

        PaymentMethod::create(['code' => 'especes', 'name' => 'Doublon', 'kind' => PaymentMethod::KIND_CASH]);
    }
}
