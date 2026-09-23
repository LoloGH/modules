<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Http\Requests\CategoryRequest;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Les catégories du catalogue : elles rangent et elles filtrent.
 *
 * Une catégorie se désactive, elle ne se supprime pas — et pas tant qu'elle
 * porte encore des produits actifs, qui ne se rangeraient plus nulle part.
 */
final class CategoryController extends PharmacieController
{
    public function index(): View
    {
        return view('pharmacie::catalog.categories', [
            'categories' => Category::query()->ofFacility()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(CategoryRequest $request, Auditor $auditor): RedirectResponse
    {
        $category = Category::create([
            'facility_id' => Facility::current(),
            'code' => strtoupper((string) Text::clean($request->validated('code'))),
            'name' => (string) Text::clean($request->validated('name')),
            'is_active' => true,
        ]);

        $auditor->record(
            'category_created',
            $category,
            sprintf('Catégorie « %s » (%s) créée', $category->name, $category->code),
            [],
            ['code' => $category->code, 'name' => $category->name],
            $this->user($request),
        );

        return redirect()->route('pharmacie.catalog.categories.index')
            ->with('pharmacie_status', sprintf('La catégorie « %s » a été créée.', $category->name));
    }

    public function toggle(Request $request, Category $category, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($category, $user, $auditor): string {
            $fresh = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->is_active && $fresh->products()->where('is_active', true)->exists()) {
                throw new PharmacieRuleViolation(
                    "La catégorie « {$fresh->name} » porte encore des produits actifs : désactivez-les d'abord."
                );
            }

            $active = ! $fresh->is_active;
            $fresh->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'category_activated' : 'category_deactivated',
                $fresh,
                sprintf('Catégorie « %s » %s', $fresh->name, $active ? 'activée' : 'désactivée'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('La catégorie « %s » est %s.', $fresh->name, $active ? 'active' : 'désactivée');
        });

        return redirect()->route('pharmacie.catalog.categories.index')->with('pharmacie_status', $message);
    }
}
