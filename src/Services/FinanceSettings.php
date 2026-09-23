<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Setting;
use Keneya\FinanceCaisse\Support\Text;
use Throwable;

/**
 * Les paramètres financiers de l'établissement.
 *
 * Le module lit toujours sa configuration par `config('finance.…')` : ce
 * service ne fait que poser par-dessus ce que l'établissement a réglé dans
 * l'écran « Paramètres ». Rien d'autre ne change dans le code, et un module
 * dont la table n'est pas encore migrée fonctionne comme avant.
 *
 * Seules les clés déclarées ici sont réglables : un écran ne doit pas pouvoir
 * réécrire n'importe quelle configuration de l'application hôte.
 */
final class FinanceSettings
{
    public const TYPE_TEXT = 'text';

    public const TYPE_INT = 'int';

    public const TYPE_CATEGORIES = 'categories';

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
                'facility.name' => ['label' => 'Nom', 'type' => self::TYPE_TEXT, 'help' => "Imprimé sur les factures et les reçus. L'application hôte peut l'imposer."],
                'facility.address' => ['label' => 'Adresse', 'type' => self::TYPE_TEXT],
                'facility.phone' => ['label' => 'Téléphone', 'type' => self::TYPE_TEXT],
                'facility.email' => ['label' => 'Adresse e-mail', 'type' => self::TYPE_TEXT],
            ],
            'Caisse' => [
                'cash.max_open_sessions_per_cashier' => [
                    'label' => 'Sessions ouvertes par caissier',
                    'type' => self::TYPE_INT,
                    'min' => 1,
                    'max' => 10,
                    'help' => 'Le défaut de l\'établissement ; il se surcharge caissier par caissier dans l\'écran « Caisses ».',
                ],
                'currency.symbol' => ['label' => 'Symbole monétaire', 'type' => self::TYPE_TEXT, 'help' => 'Affiché après chaque montant.'],
            ],
            'Alertes' => [
                'alerts.cash_ceiling' => [
                    'label' => 'Plafond d\'espèces dans un tiroir',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 100_000_000,
                    'help' => 'Au-delà, le caissier est invité à faire un dépôt. 0 : aucun plafond.',
                ],
                'alerts.session_max_hours' => [
                    'label' => 'Session ouverte signalée après (heures)',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 168,
                    'help' => 'Une caisse oubliée ouverte empêche le contrôle de la valider. 0 : jamais signalée.',
                ],
            ],
            'Créances' => [
                'receivables.patient_due_days' => [
                    'label' => 'Délai de paiement des patients (jours)',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 365,
                    'help' => 'Au-delà, la créance est échue. 0 : payable dès l\'émission.',
                ],
                'receivables.insurer_due_days' => [
                    'label' => 'Délai de règlement des assureurs (jours)',
                    'type' => self::TYPE_INT,
                    'min' => 0,
                    'max' => 365,
                ],
            ],
            'Numérotation des pièces' => [
                'identifiers.prefixes.invoice' => ['label' => 'Factures', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.payment' => ['label' => 'Encaissements', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.disbursement' => ['label' => 'Décaissements', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.cash_session' => ['label' => 'Sessions de caisse', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.deposit' => ['label' => 'Avances', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.credit_note' => ['label' => 'Remises', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.refund' => ['label' => 'Remboursements', 'type' => self::TYPE_TEXT],
                'identifiers.prefixes.insurance_settlement' => ['label' => 'Règlements d\'assureurs', 'type' => self::TYPE_TEXT],
            ],
            'Catégories de dépenses' => [
                'expense_categories' => [
                    'label' => 'Catégories proposées au décaissement',
                    'type' => self::TYPE_CATEGORIES,
                    'help' => 'Une par ligne, sous la forme « code : libellé ». Une catégorie déjà utilisée ne se retire pas.',
                ],
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
            config()->set('finance.'.$key, $value);
        }
    }

    /**
     * La valeur effective d'un paramètre : celle réglée, sinon le fichier.
     */
    public function value(string $key): mixed
    {
        $this->apply();

        return config('finance.'.$key);
    }

    /**
     * Enregistre les paramètres modifiés, et renvoie les clés changées.
     *
     * Une valeur identique au fichier de configuration efface la ligne :
     * l'établissement revient au défaut sans qu'on ait à le deviner.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function save(array $values, ?string $actorId = null, ?string $actorName = null): array
    {
        $editable = self::editable();
        $changed = [];

        foreach ($values as $key => $raw) {
            if (! isset($editable[$key])) {
                continue;
            }

            $value = $this->cast($key, $editable[$key], $raw);
            $current = $this->value($key);

            if ($value === $current) {
                continue;
            }

            $default = $this->fileDefault($key);

            if ($value === $default) {
                Setting::query()->whereKey($key)->delete();
            } else {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by_id' => $actorId, 'updated_by_name' => $actorName],
                );
            }

            config()->set('finance.'.$key, $value);
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
        $file = require dirname(__DIR__, 2).'/config/finance.php';

        return data_get($file, $key);
    }

    /**
     * @param  array{label: string, type: string, help?: string, min?: int, max?: int}  $meta
     */
    private function cast(string $key, array $meta, mixed $raw): mixed
    {
        return match ($meta['type']) {
            self::TYPE_INT => $this->integer($meta, $raw),
            self::TYPE_CATEGORIES => $this->categories($raw),
            default => $this->text($meta, $raw),
        };
    }

    /**
     * @param  array{label: string, min?: int, max?: int}  $meta
     */
    private function integer(array $meta, mixed $raw): int
    {
        // « 75 000 » se saisit comme un montant : les espaces, y compris
        // insécables, ne doivent pas le tronquer.
        $value = (int) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $raw);
        $min = $meta['min'] ?? 0;
        $max = $meta['max'] ?? PHP_INT_MAX;

        if ($value < $min || $value > $max) {
            throw new FinanceRuleViolation(sprintf('« %s » doit être compris entre %d et %d.', $meta['label'], $min, $max));
        }

        return $value;
    }

    /**
     * @param  array{label: string}  $meta
     */
    private function text(array $meta, mixed $raw): string
    {
        $value = Text::clean(is_string($raw) ? $raw : null);

        if ($value === null) {
            throw new FinanceRuleViolation(sprintf('« %s » ne peut pas être vide.', $meta['label']));
        }

        return $value;
    }

    /**
     * Les catégories de dépenses, saisies « code : libellé », une par ligne.
     *
     * Une catégorie déjà portée par un décaissement ne se retire pas : ses
     * écritures passées deviendraient illisibles.
     *
     * @return array<string, string>
     */
    private function categories(mixed $raw): array
    {
        $categories = [];

        foreach (preg_split('/\r?\n/', is_string($raw) ? $raw : '') ?: [] as $line) {
            $line = Text::clean($line);

            if ($line === null) {
                continue;
            }

            [$code, $label] = array_pad(array_map('trim', explode(':', $line, 2)), 2, null);
            $code = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $code));

            if ($code === '') {
                continue;
            }

            $categories[$code] = Text::clean($label) ?? ucfirst($code);
        }

        if ($categories === []) {
            throw new FinanceRuleViolation('Gardez au moins une catégorie de dépense.');
        }

        $used = Disbursement::query()->whereNotNull('category')->distinct()->pluck('category')->all();
        $missing = array_diff($used, array_keys($categories));

        if ($missing !== []) {
            throw new FinanceRuleViolation(sprintf(
                'Ces catégories portent déjà des décaissements et ne peuvent pas être retirées : %s.',
                implode(', ', $missing),
            ));
        }

        return $categories;
    }
}
