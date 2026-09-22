<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Http\Requests\CashierAccessRequest;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Ce qu'un caissier a le droit de faire à la caisse : combien de sessions il
 * peut tenir ouvertes, et sur quelles caisses.
 *
 * Vit sur l'écran « Caisses », sous le même droit
 * (`finance.registers.manage`) : c'est un réglage d'exploitation de la
 * caisse, pas une nouvelle fonction.
 */
final class CashierAccessController extends FinanceController
{
    public function store(CashierAccessRequest $request, Auditor $auditor): RedirectResponse
    {
        $cashierId = (string) $request->validated('cashier_id');

        $limit = $request->validated('max_open_sessions');
        $limit = $limit === null ? null : (int) $limit;

        $registers = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            (array) $request->validated('registers', []),
        )));
        sort($registers);

        $actor = $this->user($request);

        $message = DB::transaction(function () use ($cashierId, $limit, $registers, $actor, $auditor): string {
            $name = $this->knownName($cashierId);

            $changed = [];

            if ($this->saveLimit($cashierId, $name, $limit, $actor, $auditor)) {
                $changed[] = $limit === null
                    ? 'limite ramenée au défaut de l\'établissement'
                    : sprintf('limite à %d session(s)', $limit);
            }

            if ($this->saveRegisters($cashierId, $name, $registers, $actor, $auditor)) {
                $changed[] = $registers === []
                    ? 'accès à toutes les caisses'
                    : sprintf('accès à %d caisse(s)', count($registers));
            }

            if ($changed === []) {
                throw new FinanceRuleViolation("Rien à changer pour {$name} : ces réglages sont déjà ceux-là.");
            }

            return sprintf('%s : %s.', $name, implode(', ', $changed));
        });

        return redirect()->route('finance.registers.index')->with('finance_status', $message);
    }

    /**
     * Le module ne possède pas la table des utilisateurs : un caissier n'est
     * « connu » que parce qu'il a déjà tenu une caisse, ou qu'il a déjà un
     * réglage enregistré.
     */
    private function knownName(string $cashierId): string
    {
        $name = CashSession::query()->where('cashier_id', $cashierId)->value('cashier_name')
            ?? CashierSetting::query()->where('cashier_id', $cashierId)->value('cashier_name');

        if ($name !== null) {
            return (string) $name;
        }

        $known = CashierSetting::query()->where('cashier_id', $cashierId)->exists()
            || CashierRegister::query()->where('cashier_id', $cashierId)->exists();

        if (! $known) {
            throw new FinanceRuleViolation(
                'Ce caissier est inconnu du module : il apparaîtra ici après avoir ouvert une première session.'
            );
        }

        return $cashierId;
    }

    /**
     * @return bool vrai si la limite a réellement changé
     */
    private function saveLimit(string $cashierId, string $name, ?int $limit, $actor, Auditor $auditor): bool
    {
        $setting = CashierSetting::query()->where('cashier_id', $cashierId)->lockForUpdate()->first();
        $before = $setting?->max_open_sessions;

        if ($before === $limit) {
            return false;
        }

        CashierSetting::updateOrCreate(
            ['cashier_id' => $cashierId],
            ['cashier_name' => $name === $cashierId ? null : $name, 'max_open_sessions' => $limit],
        );

        $auditor->record(
            'cashier_limit_set',
            null,
            sprintf(
                'Limite de sessions de %s : %s',
                $name,
                $limit === null ? 'retour au défaut de l\'établissement' : $limit.' session(s) ouverte(s)',
            ),
            ['max_open_sessions' => $before],
            ['cashier_id' => $cashierId, 'max_open_sessions' => $limit],
            $actor,
        );

        return true;
    }

    /**
     * @param  list<int>  $registers  liste vide = aucune restriction
     * @return bool vrai si l'affectation a réellement changé
     */
    private function saveRegisters(string $cashierId, string $name, array $registers, $actor, Auditor $auditor): bool
    {
        $before = CashierRegister::assignedIdsFor($cashierId);
        sort($before);

        if ($before === $registers) {
            return false;
        }

        CashierRegister::query()->where('cashier_id', $cashierId)->delete();

        foreach ($registers as $registerId) {
            CashierRegister::create(['cashier_id' => $cashierId, 'cash_register_id' => $registerId]);
        }

        $label = static fn (array $ids): string => $ids === []
            ? 'toutes les caisses'
            : CashRegister::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');

        $auditor->record(
            'cashier_registers_set',
            null,
            sprintf('Caisses de %s : %s', $name, $label($registers)),
            ['registers' => $label($before)],
            ['cashier_id' => $cashierId, 'registers' => $label($registers)],
            $actor,
        );

        return true;
    }
}
