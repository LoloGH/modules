<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Http\Requests\RegisterRequest;
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
        return view('finance::registers.index', [
            'registers' => CashRegister::query()
                ->withCount(['sessions as open_sessions_count' => fn ($query) => $query->where('status', CashSession::STATUS_OPEN)])
                ->orderBy('name')
                ->get(),
        ]);
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
