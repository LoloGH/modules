<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Access\UserPermissions;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Http\Requests\UserPermissionRequest;
use Keneya\Pharmacie\Models\UserPermission;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Staff\HostStaff;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Rbac;

/**
 * Écran « Utilisateurs » : qui a droit à quoi dans la pharmacie.
 *
 * Les personnes listées sont celles que l'application hôte fait entrer dans
 * le module (`Pharmacie::staff()`). Leurs capacités se règlent ici, sans rien
 * changer chez l'hôte : ni rôle, ni type de personnel (voir UserPermissions).
 */
final class UserPermissionController extends PharmacieController
{
    public function index(Request $request, UserPermissions $permissions): View
    {
        $actorId = Actor::id($this->user($request));

        return view('pharmacie::users.index', [
            'users' => $this->knownUsers($permissions, $actorId),
            'groups' => UserPermissions::grantableGroups(),
            'templates' => $this->templates(),
            // L'hôte déclare-t-il son personnel ? Sans lui, l'écran ne peut
            // régler que ceux qui ont déjà un réglage, et il le dit.
            'directoryConnected' => Pharmacie::staff()->staff() !== [],
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
                throw new PharmacieRuleViolation("Rien à changer pour {$name} : ces capacités sont déjà les siennes.");
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
                sprintf('Capacités de %s dans la pharmacie : %d capacité(s)', $name, count($granted)),
                ['permissions' => $before ?? 'rôles de l\'application hôte'],
                ['user_id' => $userId, 'permissions' => $granted],
                $actor,
            );

            return sprintf('%s : %d capacité(s) réglée(s) dans la pharmacie.', $name, count($granted));
        });

        return redirect()->route('pharmacie.users.index')->with('pharmacie_status', $message);
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
                throw new PharmacieRuleViolation('Ces capacités viennent déjà des rôles de l\'application hôte.');
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

        return redirect()->route('pharmacie.users.index')->with('pharmacie_status', $message);
    }

    /**
     * On ne règle pas ses propres capacités : l'administrateur pourrait se
     * retirer l'accès à l'écran qui permet de les rendre.
     */
    private function editable(string $userId, Authenticatable $actor): string
    {
        if ($userId === Actor::id($actor)) {
            throw new PharmacieRuleViolation('Vous ne pouvez pas régler vos propres capacités.');
        }

        return $userId;
    }

    /**
     * Quelqu'un est connu parce que l'hôte le fait entrer dans la pharmacie,
     * ou parce qu'il a déjà un réglage ici.
     */
    private function knownName(string $userId): string
    {
        $declared = collect(Pharmacie::staff()->staff())
            ->first(static fn (HostStaff $user): bool => $user->id === $userId);

        if ($declared !== null) {
            return $declared->name;
        }

        $row = UserPermission::query()->where('user_id', $userId)->first();

        if ($row === null) {
            throw new PharmacieRuleViolation('Cette personne est inconnue : l\'application hôte ne lui donne pas accès au module.');
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

        foreach (Pharmacie::staff()->staff() as $declared) {
            $users[$declared->id] = ['id' => $declared->id, 'name' => $declared->name, 'function' => $declared->function, 'declared' => true];
        }

        // Quelqu'un qui n'entre plus garde son réglage : on l'affiche pour
        // pouvoir le ramener aux rôles de l'hôte, au lieu de le laisser
        // traîner invisible dans la base.
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
     * Ce que les rôles de l'hôte accordent aujourd'hui à cette personne : le
     * point de départ proposé tant que rien n'est réglé ici.
     *
     * @return list<string>
     */
    private function inherited(string $userId): array
    {
        $model = Pharmacie::userModel();
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
     * Modèles pour cocher d'un coup : les capacités de départ des rôles du
     * module.
     *
     * @return array<string, list<string>>
     */
    private function templates(): array
    {
        $labels = Rbac::roleLabels();
        $templates = [];

        foreach ([Rbac::ROLE_PHARMACIST, Rbac::ROLE_DISPENSER, Rbac::ROLE_STOREKEEPER] as $role) {
            $templates[$labels[$role]] = array_values(array_intersect(
                UserPermissions::grantable(),
                Rbac::rolePermissions()[$role],
            ));
        }

        return $templates;
    }
}
