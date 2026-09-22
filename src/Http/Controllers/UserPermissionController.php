<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Access\UserPermissions;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Cashiers\HostCashier;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Http\Requests\UserPermissionRequest;
use Keneya\FinanceCaisse\Models\UserPermission;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Rbac;

/**
 * Écran « Utilisateurs » : ce que chacun peut faire dans le module.
 *
 * Les utilisateurs listés sont ceux que l'hôte fait entrer dans Finance
 * (`Finance::cashiers()`). Leurs capacités se règlent ici, sans rien changer
 * chez l'hôte : ni rôle, ni type de personnel (voir UserPermissions).
 */
final class UserPermissionController extends FinanceController
{
    public function index(Request $request, UserPermissions $permissions): View
    {
        $actorId = Actor::id($this->user($request));

        return view('finance::users.index', [
            'users' => $this->knownUsers($permissions, $actorId),
            'groups' => UserPermissions::grantableGroups(),
            'templates' => $this->templates(),
        ]);
    }

    public function store(UserPermissionRequest $request, UserPermissions $permissions, Auditor $auditor): RedirectResponse
    {
        $actor = $this->user($request);
        $userId = $this->editable((string) $request->validated('user_id'), $actor);

        $granted = array_values(array_intersect(
            UserPermissions::grantable(),
            (array) $request->validated('permissions', []),
        ));

        $message = DB::transaction(function () use ($userId, $granted, $actor, $auditor, $permissions): string {
            $name = $this->knownName($userId);
            $row = UserPermission::query()->where('user_id', $userId)->lockForUpdate()->first();
            $before = $row === null ? null : array_values(array_intersect(UserPermissions::grantable(), (array) $row->permissions));

            if ($before === $granted) {
                throw new FinanceRuleViolation("Rien à changer pour {$name} : ces capacités sont déjà les siennes.");
            }

            UserPermission::updateOrCreate(['user_id' => $userId], [
                'user_name' => $name === $userId ? null : $name,
                'permissions' => $granted,
                'updated_by_id' => Actor::id($actor),
                'updated_by_name' => Actor::name($actor),
            ]);

            $permissions->forget($userId);

            $auditor->record(
                'user_permissions_set',
                null,
                sprintf('Capacités de %s dans Finance : %d capacité(s)', $name, count($granted)),
                ['permissions' => $before ?? 'rôles de l\'application hôte'],
                ['user_id' => $userId, 'permissions' => $granted],
                $actor,
            );

            return sprintf('%s : %d capacité(s) réglée(s) dans Finance.', $name, count($granted));
        });

        return redirect()->route('finance.users.index')->with('finance_status', $message);
    }

    /**
     * Retour aux droits que l'hôte donne : le réglage fait ici disparaît.
     */
    public function reset(Request $request, UserPermissions $permissions, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate(['user_id' => ['required', 'string', 'max:64']]);

        $actor = $this->user($request);
        $userId = $this->editable((string) $validated['user_id'], $actor);

        $message = DB::transaction(function () use ($userId, $actor, $auditor, $permissions): string {
            $row = UserPermission::query()->where('user_id', $userId)->lockForUpdate()->first();

            if ($row === null) {
                throw new FinanceRuleViolation('Ces capacités viennent déjà des rôles de l\'application hôte.');
            }

            $name = $this->knownName($userId);
            $before = (array) $row->permissions;
            $row->delete();
            $permissions->forget($userId);

            $auditor->record(
                'user_permissions_reset',
                null,
                sprintf('Capacités de %s : retour aux rôles de l\'application hôte', $name),
                ['permissions' => $before],
                ['user_id' => $userId, 'permissions' => 'rôles de l\'application hôte'],
                $actor,
            );

            return sprintf('%s : capacités ramenées aux rôles de l\'application hôte.', $name);
        });

        return redirect()->route('finance.users.index')->with('finance_status', $message);
    }

    /**
     * On ne règle pas ses propres capacités : l'administrateur pourrait se
     * retirer l'accès à l'écran qui permet de les rendre.
     */
    private function editable(string $userId, Authenticatable $actor): string
    {
        if ($userId === Actor::id($actor)) {
            throw new FinanceRuleViolation('Vous ne pouvez pas régler vos propres capacités.');
        }

        return $userId;
    }

    /**
     * Un utilisateur est connu parce que l'hôte le fait entrer dans Finance,
     * ou parce qu'il a déjà un réglage ici.
     */
    private function knownName(string $userId): string
    {
        $declared = collect(Finance::cashiers()->cashiers())
            ->first(static fn (HostCashier $user): bool => $user->id === $userId);

        if ($declared !== null) {
            return $declared->name;
        }

        $row = UserPermission::query()->where('user_id', $userId)->first();

        if ($row === null) {
            throw new FinanceRuleViolation('Cet utilisateur est inconnu : l\'application hôte ne lui donne pas accès au module.');
        }

        return $row->user_name ?? $userId;
    }

    /**
     * @return list<array{id: string, name: string, function: ?string, declared: bool, self: bool, configured: bool, permissions: list<string>}>
     */
    private function knownUsers(UserPermissions $permissions, string $actorId): array
    {
        $rows = UserPermission::query()->get()->keyBy('user_id');

        $users = [];

        foreach (Finance::cashiers()->cashiers() as $declared) {
            $users[$declared->id] = ['id' => $declared->id, 'name' => $declared->name, 'function' => $declared->function, 'declared' => true];
        }

        // Un ancien utilisateur garde son réglage : on l'affiche pour pouvoir
        // le ramener aux rôles de l'hôte.
        foreach ($rows as $userId => $row) {
            $users[(string) $userId] ??= ['id' => (string) $userId, 'name' => $row->user_name ?? (string) $userId, 'function' => null, 'declared' => false];
        }

        $list = [];

        foreach ($users as $userId => $user) {
            $own = $permissions->for((string) $userId);

            $list[] = $user + [
                'self' => (string) $userId === $actorId,
                'configured' => $own !== null,
                'permissions' => $own ?? $this->inherited((string) $userId),
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * Ce que les rôles de l'hôte accordent aujourd'hui à cet utilisateur :
     * le point de départ proposé tant que rien n'est réglé ici.
     *
     * @return list<string>
     */
    private function inherited(string $userId): array
    {
        $model = Finance::userModel();
        $user = $model::query()->find($userId);

        if (! $user instanceof Authenticatable) {
            return [];
        }

        $gate = Gate::forUser($user);

        return array_values(array_filter(
            UserPermissions::grantable(),
            static fn (string $permission): bool => $gate->allows($permission),
        ));
    }

    /**
     * Modèles pour cocher d'un coup : les permissions de départ des rôles du
     * module.
     *
     * @return array<string, list<string>>
     */
    private function templates(): array
    {
        $labels = Rbac::roleLabels();
        $templates = [];

        foreach ([Rbac::ROLE_CASHIER, Rbac::ROLE_ACCOUNTANT, Rbac::ROLE_DIRECTOR] as $role) {
            $templates[$labels[$role]] = array_values(array_intersect(
                UserPermissions::grantable(),
                Rbac::rolePermissions()[$role],
            ));
        }

        return $templates;
    }
}
