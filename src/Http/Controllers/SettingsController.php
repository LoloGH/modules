<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Services\PharmacieSettings;
use Keneya\Pharmacie\Support\Actor;

/**
 * Les paramètres de la pharmacie : ce que l'établissement règle depuis
 * l'application plutôt que dans le fichier de configuration.
 *
 * Seules les clés déclarées par {@see PharmacieSettings} sont réglables, et
 * chaque changement est tracé : un délai de péremption ou une règle de
 * contrôle renforcé change la façon dont le module refuse ou accepte.
 */
final class SettingsController extends PharmacieController
{
    public function index(PharmacieSettings $settings): View
    {
        $settings->apply();

        return view('pharmacie::settings.index', [
            'groups' => PharmacieSettings::groups(),
            'values' => $this->currentValues($settings),
            // Le nom imprimé : l'hôte peut l'imposer, et il gagne alors.
            'facilityFromHost' => Pharmacie::facility()['name'] ?? null,
        ]);
    }

    public function update(Request $request, PharmacieSettings $settings, Auditor $auditor): RedirectResponse
    {
        $actor = $this->user($request);

        $values = (array) $request->input('settings', []);
        $changed = $settings->save($values, Actor::id($actor), Actor::name($actor));

        if ($changed === []) {
            throw new PharmacieRuleViolation('Rien à changer : ces paramètres sont déjà ceux-là.');
        }

        $auditor->record(
            'settings_updated',
            null,
            sprintf('Paramètres de la pharmacie modifiés : %s', implode(', ', $changed)),
            [],
            ['keys' => $changed],
            $actor,
        );

        return redirect()->route('pharmacie.settings.index')->with(
            'pharmacie_status',
            sprintf('%d paramètre(s) enregistré(s).', count($changed)),
        );
    }

    /**
     * Les valeurs telles qu'elles s'affichent dans le formulaire.
     *
     * @return array<string, string|bool>
     */
    private function currentValues(PharmacieSettings $settings): array
    {
        $values = [];

        foreach (PharmacieSettings::editable() as $key => $meta) {
            $value = $settings->value($key);

            $values[$key] = $meta['type'] === PharmacieSettings::TYPE_BOOL
                ? (bool) $value
                : (string) $value;
        }

        return $values;
    }
}
