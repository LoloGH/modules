<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Services\FinanceSettings;
use Keneya\FinanceCaisse\Support\Actor;

/**
 * Les paramètres financiers de l'établissement : ce que l'administrateur
 * règle depuis l'application plutôt que dans le fichier de configuration.
 *
 * Seules les clés déclarées par {@see FinanceSettings} sont réglables, et
 * chaque changement est tracé : un délai de créance ou un préfixe de facture
 * change la lecture de tout le module.
 */
final class SettingsController extends FinanceController
{
    public function index(FinanceSettings $settings): View
    {
        $settings->apply();

        return view('finance::settings.index', [
            'groups' => FinanceSettings::groups(),
            'values' => $this->currentValues($settings),
            // Le nom imprimé : l'hôte peut l'imposer, et il gagne alors.
            'facilityFromHost' => Finance::facility()['name'] ?? null,
        ]);
    }

    public function update(Request $request, FinanceSettings $settings, Auditor $auditor): RedirectResponse
    {
        $actor = $this->user($request);

        $values = (array) $request->input('settings', []);
        $changed = $settings->save($values, Actor::id($actor), Actor::name($actor));

        if ($changed === []) {
            throw new FinanceRuleViolation('Rien à changer : ces paramètres sont déjà ceux-là.');
        }

        $auditor->record(
            'settings_updated',
            null,
            sprintf('Paramètres financiers modifiés : %s', implode(', ', $changed)),
            [],
            ['keys' => $changed],
            $actor,
        );

        return redirect()->route('finance.settings.index')->with(
            'finance_status',
            sprintf('%d paramètre(s) enregistré(s).', count($changed)),
        );
    }

    /**
     * Les valeurs telles qu'elles s'affichent dans le formulaire.
     *
     * @return array<string, string>
     */
    private function currentValues(FinanceSettings $settings): array
    {
        $values = [];

        foreach (FinanceSettings::editable() as $key => $meta) {
            $value = $settings->value($key);

            $values[$key] = $meta['type'] === FinanceSettings::TYPE_CATEGORIES
                ? $this->categoriesToText((array) $value)
                : (string) $value;
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $categories
     */
    private function categoriesToText(array $categories): string
    {
        $lines = [];

        foreach ($categories as $code => $label) {
            $lines[] = $code.' : '.$label;
        }

        return implode("\n", $lines);
    }
}
