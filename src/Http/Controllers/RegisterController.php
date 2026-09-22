<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Http\Requests\RegisterRequest;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Gestion des caisses de l'établissement. Une caisse se désactive, elle ne
 * se supprime pas.
 */
final class RegisterController extends FinanceController
{
    public function index(): View
    {
        $registers = CashRegister::query()
            ->withCount(['sessions as open_sessions_count' => fn ($query) => $query->where('status', CashSession::STATUS_OPEN)])
            ->orderBy('name')
            ->get();

        return view('finance::registers.index', [
            'registers' => $registers,
            // On ne propose d'affecter que les caisses actives : cocher une
            // caisse désactivée n'aurait aucun effet.
            'assignable' => $registers->where('is_active', true)->values(),
            'cashiers' => $this->knownCashiers(),
            'defaultLimit' => CashierSetting::defaultLimit(),
        ]);
    }

    /**
     * Les caissiers que le module connaît, avec leur limite effective.
     *
     * D'abord le personnel que l'hôte déclare habilité à encaisser
     * (`Finance::cashiers()`) : on règle ses caisses AVANT sa première
     * session. S'y ajoutent ceux qui ont déjà tenu une caisse ou qui ont un
     * réglage enregistré (un ancien caissier garde ainsi son historique).
     *
     * @return list<array{id: string, name: string, function: ?string, open: int, limit: int, override: ?int, registers: list<int>}>
     */
    private function knownCashiers(): array
    {
        $fromSessions = CashSession::query()
            ->selectRaw('cashier_id, MAX(cashier_name) as cashier_name')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count', [CashSession::STATUS_OPEN])
            ->groupBy('cashier_id')
            ->get()
            ->keyBy('cashier_id');

        $overrides = CashierSetting::query()->get()->keyBy('cashier_id');

        $assignments = CashierRegister::query()->get()
            ->groupBy('cashier_id')
            ->map(fn ($rows): array => $rows->pluck('cash_register_id')->map(static fn ($id): int => (int) $id)->all());

        $directory = collect(Finance::cashiers()->cashiers())->keyBy('id');

        $ids = $directory->keys()
            ->merge($fromSessions->keys())
            ->merge($overrides->keys())
            ->merge($assignments->keys())
            ->map(static fn ($id): string => (string) $id)
            ->unique();

        $default = CashierSetting::defaultLimit();

        return $ids
            ->map(function (string $id) use ($directory, $fromSessions, $overrides, $assignments, $default): array {
                $override = $overrides->get($id)?->max_open_sessions;

                return [
                    'id' => $id,
                    'name' => $directory->get($id)?->name
                        ?? $fromSessions->get($id)?->cashier_name
                        ?? $overrides->get($id)?->cashier_name
                        ?? $id,
                    'function' => $directory->get($id)?->function,
                    'open' => (int) ($fromSessions->get($id)?->open_count ?? 0),
                    'limit' => $override === null ? $default : max(1, $override),
                    'override' => $override,
                    // Liste vide = aucune restriction, donc toutes les caisses.
                    'registers' => $assignments->get($id, []),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function store(RegisterRequest $request, Auditor $auditor): RedirectResponse
    {
        $register = CashRegister::create([
            'code' => strtoupper((string) Text::clean($request->validated('code'))),
            'name' => (string) Text::clean($request->validated('name')),
            'is_active' => true,
        ]);

        $auditor->record('register_created', $register, "Caisse {$register->name} ({$register->code}) créée", [], ['code' => $register->code, 'name' => $register->name], $this->user($request));

        return redirect()->route('finance.registers.index')->with('finance_status', "La caisse « {$register->name} » a été créée.");
    }

    public function toggle(Request $request, CashRegister $register, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($register, $user, $auditor): string {
            $register = CashRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if ($register->is_active && CashSession::query()->open()->where('cash_register_id', $register->id)->exists()) {
                throw new FinanceRuleViolation('Cette caisse a une session ouverte : clôturez-la avant de la désactiver.');
            }

            $active = ! $register->is_active;
            $register->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'register_activated' : 'register_deactivated',
                $register,
                sprintf('Caisse « %s » %s', $register->name, $active ? 'activée' : 'désactivée'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('La caisse « %s » est %s.', $register->name, $active ? 'activée' : 'désactivée');
        });

        return redirect()->route('finance.registers.index')->with('finance_status', $message);
    }
}
