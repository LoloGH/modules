<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Http\Requests\AnalyticCenterRequest;
use Keneya\FinanceCaisse\Http\Requests\AnalyticCenterUpdateRequest;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Support\AnalyticTree;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Centres analytiques. Un centre se désactive, il ne se supprime pas : les
 * actes et les rapports passés s'y réfèrent.
 */
final class AnalyticCenterController extends FinanceController
{
    public function index(): View
    {
        $centers = AnalyticCenter::query()
            ->withCount(['acts', 'children'])
            ->orderBy('name')
            ->get();

        return view('finance::catalog.centers', [
            'centers' => $centers,
            'tree' => (new AnalyticTree($centers))->flat(),
            'parents' => $centers->where('is_active', true)->values(),
            'kinds' => AnalyticCenter::kindLabels(),
        ]);
    }

    public function store(AnalyticCenterRequest $request, Auditor $auditor): RedirectResponse
    {
        $parentId = $request->validated('parent_id');

        $center = AnalyticCenter::create([
            'code' => strtoupper((string) Text::clean($request->validated('code'))),
            'name' => (string) Text::clean($request->validated('name')),
            'parent_id' => $parentId === null ? null : (int) $parentId,
            'kind' => (string) $request->validated('kind'),
            'is_active' => true,
        ]);

        $auditor->record(
            'analytic_center_created',
            $center,
            "Centre analytique {$center->name} ({$center->code}) créé",
            [],
            ['code' => $center->code, 'name' => $center->name, 'kind' => $center->kind, 'parent_id' => $center->parent_id],
            $this->user($request),
        );

        return redirect()->route('finance.catalog.centers.index')
            ->with('finance_status', "Le centre « {$center->name} » a été créé.");
    }

    /**
     * Modifier un centre : son nom, son rattachement et sa nature. Le code ne
     * change pas — les rapports et les exports passés s'y réfèrent.
     */
    public function update(AnalyticCenterUpdateRequest $request, AnalyticCenter $center, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);
        $parentId = $request->validated('parent_id');
        $parentId = $parentId === null ? null : (int) $parentId;
        $kind = (string) $request->validated('kind');

        $message = DB::transaction(function () use ($center, $parentId, $kind, $request, $user, $auditor): string {
            $center = AnalyticCenter::query()->whereKey($center->getKey())->lockForUpdate()->firstOrFail();

            $this->assertParentAllowed($center, $parentId);
            $this->assertKindAllowed($center, $kind);

            $before = ['name' => $center->name, 'parent_id' => $center->parent_id, 'kind' => $center->kind];

            $center->update([
                'name' => (string) Text::clean($request->validated('name')),
                'parent_id' => $parentId,
                'kind' => $kind,
            ]);

            $auditor->record(
                'analytic_center_updated',
                $center,
                sprintf('Centre analytique « %s » modifié (%s)', $center->name, $center->kindLabel()),
                $before,
                ['name' => $center->name, 'parent_id' => $center->parent_id, 'kind' => $center->kind],
                $user,
            );

            return sprintf('Le centre « %s » a été modifié.', $center->name);
        });

        return redirect()->route('finance.catalog.centers.index')->with('finance_status', $message);
    }

    /**
     * Un centre ne se rattache ni à lui-même ni à l'un des siens : la
     * hiérarchie tournerait en rond et aucun total ne se calculerait.
     */
    private function assertParentAllowed(AnalyticCenter $center, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $descendants = AnalyticTree::load()->withDescendants((int) $center->id);

        if (in_array($parentId, $descendants, true)) {
            throw new FinanceRuleViolation(
                "Le centre « {$center->name} » ne peut pas être rattaché à lui-même ni à l'un de ses sous-centres."
            );
        }

        $parent = AnalyticCenter::query()->findOrFail($parentId);

        if (! $parent->is_active) {
            throw new FinanceRuleViolation("Le centre « {$parent->name} » est désactivé : on ne s'y rattache pas.");
        }
    }

    /**
     * Un centre qui porte des actes garde une nature qui accepte les
     * produits : sinon ses recettes se rangeraient dans un centre de charges.
     */
    private function assertKindAllowed(AnalyticCenter $center, string $kind): void
    {
        if ($kind !== AnalyticCenter::KIND_COST || ! $center->acts()->exists()) {
            return;
        }

        throw new FinanceRuleViolation(
            "Le centre « {$center->name} » porte des actes : il ne peut pas devenir un centre de charges seules."
        );
    }

    public function toggle(Request $request, AnalyticCenter $center, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($center, $user, $auditor): string {
            $center = AnalyticCenter::query()->whereKey($center->getKey())->lockForUpdate()->firstOrFail();

            if ($center->is_active) {
                $this->assertNothingActiveBelow($center);
            }

            $active = ! $center->is_active;
            $center->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'analytic_center_activated' : 'analytic_center_deactivated',
                $center,
                sprintf('Centre analytique « %s » %s', $center->name, $active ? 'activé' : 'désactivé'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('Le centre « %s » est %s.', $center->name, $active ? 'activé' : 'désactivé');
        });

        return redirect()->route('finance.catalog.centers.index')->with('finance_status', $message);
    }

    /**
     * On ne désactive pas un centre qui porte encore quelque chose d'actif :
     * un acte actif rattaché à un centre désactivé ne se range nulle part.
     */
    private function assertNothingActiveBelow(AnalyticCenter $center): void
    {
        if ($center->children()->where('is_active', true)->exists()) {
            throw new FinanceRuleViolation(
                "Le centre « {$center->name} » a des centres enfants actifs : désactivez-les d'abord."
            );
        }

        if ($center->acts()->where('is_active', true)->exists()) {
            throw new FinanceRuleViolation(
                "Le centre « {$center->name} » porte encore des actes actifs : désactivez-les d'abord."
            );
        }
    }

    /**
     * Les centres à plat, dans l'ordre de l'arborescence, chacun avec sa
     * profondeur : la vue n'a plus qu'à décaler le libellé.
     *
     * Un centre dont le parent a disparu de la liste est rattaché à la
     * racine plutôt qu'escamoté.
     *
     * @param  Collection<int, AnalyticCenter>  $centers
     * @return list<array{center: AnalyticCenter, depth: int}>
     */
    private function tree(Collection $centers): array
    {
        $byParent = $centers->groupBy(fn (AnalyticCenter $center): string => (string) $center->parent_id);
        $known = $centers->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $roots = $centers
            ->filter(fn (AnalyticCenter $center): bool => $center->parent_id === null || ! in_array((string) $center->parent_id, $known, true))
            ->values();

        $flat = [];

        $walk = function (Collection $level, int $depth) use (&$walk, &$flat, $byParent): void {
            foreach ($level as $center) {
                $flat[] = ['center' => $center, 'depth' => $depth];

                $walk($byParent->get((string) $center->id, collect()), $depth + 1);
            }
        };

        $walk($roots, 0);

        return $flat;
    }
}
