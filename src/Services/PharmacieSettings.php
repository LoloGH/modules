<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Keneya\Pharmacie\Models\Setting;
use Keneya\Pharmacie\Support\Text;
use Throwable;

/**
 * Les paramètres de la pharmacie, réglés depuis l'application.
 *
 * Le module lit toujours sa configuration par `config('pharmacie.…')` : ce
 * service ne fait que poser par-dessus ce que l'établissement a réglé à
 * l'écran. Rien d'autre ne change dans le code, et un module dont la table
 * n'est pas encore migrée fonctionne exactement comme avant.
 *
 * Seules les clés déclarées ici sont réglables. Deux choses n'y figurent
 * volontairement pas :
 *
 *   - **délivrer un lot périmé** : ce n'est pas une préférence
 *     d'établissement, et un écran ne doit pas pouvoir l'autoriser ;
 *   - **la porte du module** (`access.*`) : elle appartient à l'hôte.
 */
final class PharmacieSettings
{
    public const TYPE_TEXT = 'text';

    public const TYPE_INT = 'int';

    public const TYPE_BOOL = 'bool';

    private bool $applied = false;

    /**
     * Les paramètres réglables, groupés comme l'écran les présente.
     *
     * @return array<string, array<string, array{label: string, type: string, help?: string, min?: int, max?: int}>>
     */
    public static function groups(): array
    {
        return [
            'Établissement' => [
                'facility.name' => ['label' => 'Nom', 'type' => self::TYPE_TEXT, 'help' => "Imprimé sur les bons de dispensation. L'application hôte peut l'imposer."],
                'facility.address' => ['label' => 'Adresse', 'type' => self::TYPE_TEXT],
                'facility.phone' => ['label' => 'Téléphone', 'type' => self::TYPE_TEXT],
                'facility.email' => ['label' => 'Adresse e-mail', 'type' => self::TYPE_TEXT],
                'currency.symbol' => ['label' => 'Symbole monétaire', 'type' => self::TYPE_TEXT, 'help' => 'Affiché après chaque montant.'],
            ],
            'Péremptions' => [
                'stock.expiry_warning_days' => [
                    'label' => 'Signaler une péremption (jours avant)',
                    'type' => self::TYPE_INT,
                    'min' => 1,
                    'max' => 730,
                    'help' => "Au-delà de ce délai, un lot n'est pas encore signalé. En deçà, il remonte dans les alertes et l'écran « Péremptions ».",
                ],
            ],
            'Contrôle renforcé' => [
                'controlled.require_prescription' => [
                    'label' => 'Ordonnance obligatoire',
                    'type' => self::TYPE_BOOL,
                    'help' => 'Pour les produits marqués « sous surveillance » : ils ne se délivrent que sur ordonnance référencée.',
                ],
                'controlled.require_patient' => [
                    'label' => 'Patient nommé obligatoire',
                    'type' => self::TYPE_BOOL,
                    'help' => 'Un stupéfiant ne se délivre pas anonymement : le registre doit pouvoir être relu.',
                ],
                'controlled.ability' => [
                    'label' => 'Capacité exigée pour délivrer',
                    'type' => self::TYPE_TEXT,
                    'optional' => true,
                    'help' => 'Laissez vide pour n\'en exiger aucune. Par défaut : pharmacie.controlled.dispense.',
                ],
            ],
            'Prévision de réapprovisionnement' => [
                'forecast.horizon_days' => [
                    'label' => 'Horizon à couvrir (jours)',
                    'type' => self::TYPE_INT,
                    'min' => 1,
                    'max' => 365,
                    'help' => 'La durée que la commande doit couvrir, au rythme de consommation observé.',
                ],
                'forecast.lead_time_days' => [
                    'label' => 'Délai de livraison (jours)',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 365,
                    'help' => "Entre la commande et l'arrivée. Un fournisseur local et un importateur n'ont pas le même délai.",
                ],
                'forecast.safety_days' => [
                    'label' => 'Marge de sécurité (jours)',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 365,
                    'help' => 'Ce qu\'on garde en plus pour absorber un retard ou une épidémie.',
                ],
            ],
            'Numérotation des pièces' => [
                'identifiers.prefixes.dispensation' => ['label' => 'Dispensations', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.order' => ['label' => 'Commandes', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.reception' => ['label' => 'Réceptions', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.transfer' => ['label' => 'Transferts', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.inventory' => ['label' => 'Inventaires', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.loss' => ['label' => 'Pertes et destructions', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.recall' => ['label' => 'Rappels de lots', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.adverse_event' => ['label' => 'Signalements', 'type' => self::TYPE_TEXT],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, type: string, help?: string, min?: int, max?: int}>
     */
    public static function editable(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * Pose les réglages de l'établissement par-dessus le fichier de
     * configuration. Appelé une fois par requête.
     */
    public function apply(): void
    {
        if ($this->applied) {
            return;
        }

        $this->applied = true;

        foreach ($this->stored() as $key => $value) {
            config()->set('pharmacie.'.$key, $value);
        }
    }

    /**
     * La valeur effective d'un paramètre : celle réglée, sinon le fichier.
     */
    public function value(string $key): mixed
    {
        $this->apply();

        return config('pharmacie.'.$key);
    }

    /**
     * Enregistre les paramètres modifiés, et renvoie les clés changées.
     *
     * Une valeur ramenée à celle du fichier de configuration efface la ligne :
     * l'établissement revient au défaut sans qu'on ait à le deviner, et sans
     * garder une trace qui dirait le contraire.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function save(array $values, ?string $actorId = null, ?string $actorName = null): array
    {
        $editable = self::editable();
        $changed = [];

        // On ne touche qu'aux clés envoyées. Une case décochée n'est pas
        // envoyée par le navigateur : c'est le champ caché du formulaire qui
        // la fait valoir « non ». Déduire « faux » d'une absence éteindrait
        // une règle de contrôle renforcé au premier envoi partiel, un écran
        // qui ne montre qu'un groupe, un appel qui n'en règle qu'un.
        foreach ($values as $key => $raw) {
            if (! isset($editable[$key]) || $raw === null) {
                continue;
            }

            $meta = $editable[$key];
            $value = $this->cast($meta, $raw);
            $current = $this->value($key);

            if ($value === $current) {
                continue;
            }

            if ($value === $this->fileDefault($key)) {
                Setting::query()->whereKey($key)->delete();
            } else {
                Setting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                        'updated_by_id' => $actorId,
                        'updated_by_name' => $actorName,
                    ],
                );
            }

            config()->set('pharmacie.'.$key, $value);
            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Les réglages enregistrés, décodés.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        try {
            $rows = Setting::query()->get(['key', 'value']);
        } catch (Throwable) {
            // Migration pas encore jouée, ou base injoignable au démarrage :
            // le fichier fait foi, comme avant.
            return [];
        }

        $settings = [];
        $editable = self::editable();

        foreach ($rows as $row) {
            if (! isset($editable[$row->key])) {
                continue;
            }

            $settings[$row->key] = json_decode((string) $row->value, true);
        }

        return $settings;
    }

    /**
     * La valeur du fichier de configuration, sans les réglages posés
     * par-dessus.
     */
    private function fileDefault(string $key): mixed
    {
        $file = require dirname(__DIR__, 2).'/config/pharmacie.php';

        return data_get($file, $key);
    }

    /**
     * @param  array{label: string, type: string, help?: string, min?: int, max?: int}  $meta
     */
    private function cast(array $meta, mixed $raw): mixed
    {
        return match ($meta['type']) {
            self::TYPE_INT => $this->integer($meta, $raw),
            self::TYPE_BOOL => (bool) filter_var($raw, FILTER_VALIDATE_BOOL),
            default => Text::clean((string) $raw) ?? '',
        };
    }

    /**
     * @param  array{label: string, min?: int, max?: int}  $meta
     */
    private function integer(array $meta, mixed $raw): int
    {
        // « 1 000 » se saisit avec une espace : elle ne doit pas tronquer le
        // nombre à 1.
        $value = (int) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $raw);

        return max($meta['min'] ?? 0, min($meta['max'] ?? PHP_INT_MAX, $value));
    }
}
