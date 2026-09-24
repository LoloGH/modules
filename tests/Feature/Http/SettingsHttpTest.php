<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Setting;
use Keneya\Pharmacie\Services\PharmacieSettings;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les paramètres de la pharmacie : ce que l'établissement règle lui-même,
 * posé par-dessus le fichier de configuration.
 */
class SettingsHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_a_setting_overrides_the_configuration_file(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->post(route('pharmacie.settings.update'), [
                'settings' => ['stock.expiry_warning_days' => '45'],
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(45, (int) app(PharmacieSettings::class)->value('stock.expiry_warning_days'));
        $this->assertSame('45', (string) Setting::query()->whereKey('stock.expiry_warning_days')->value('value'));
        $this->assertSame(1, AuditLog::where('event', 'settings_updated')->count());
    }

    public function test_a_value_back_to_the_file_default_stops_being_stored(): void
    {
        $settings = app(PharmacieSettings::class);
        $defaut = (int) $settings->value('stock.expiry_warning_days');

        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->post(route('pharmacie.settings.update'), [
                'settings' => ['stock.expiry_warning_days' => '45'],
            ]);

        $this->assertSame(1, Setting::query()->count());

        // Revenir au defaut efface la ligne : l'etablissement suivra le
        // fichier s'il change un jour.
        $this->post(route('pharmacie.settings.update'), [
            'settings' => ['stock.expiry_warning_days' => (string) $defaut],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_an_unchecked_rule_is_a_no_not_a_silence(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->post(route('pharmacie.settings.update'), [
                // La case decochee n'est pas envoyee par le navigateur : le
                // champ cache vaut « non », et le reglage doit le retenir.
                'settings' => ['controlled.require_prescription' => '0'],
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertFalse((bool) app(PharmacieSettings::class)->value('controlled.require_prescription'));
    }

    public function test_an_out_of_range_delay_is_brought_back_inside(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->post(route('pharmacie.settings.update'), [
                'settings' => ['forecast.lead_time_days' => '9999'],
            ]);

        $this->assertSame(365, (int) app(PharmacieSettings::class)->value('forecast.lead_time_days'));
    }

    public function test_a_key_that_is_not_settable_here_is_ignored(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->from(route('pharmacie.settings.index'))
            ->post(route('pharmacie.settings.update'), [
                // Delivrer un lot perime n'est pas une preference : la cle
                // n'est pas reglable, et rien ne doit changer.
                'settings' => ['stock.allow_expired_dispensing' => '1'],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertFalse((bool) config('pharmacie.stock.allow_expired_dispensing'));
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_settings_are_an_administrator_matter(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.settings.index'))
            ->assertForbidden();
    }
}
