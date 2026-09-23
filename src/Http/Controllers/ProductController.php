<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Http\Requests\ProductRequest;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Le catalogue pharmaceutique : ce que la pharmacie sait référencer.
 *
 * Un produit se désactive, il ne se supprime pas : ses lots, ses mouvements et
 * ses dispensations doivent rester lisibles. Le prix de vente est séparé du
 * reste (`pharmacie.prices.manage`) — fixer un prix n'est pas décrire un
 * produit.
 */
final class ProductController extends PharmacieController
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $kind = array_key_exists((string) $request->query('nature'), Product::kindLabels()) ? (string) $request->query('nature') : null;
        $categoryId = ctype_digit((string) $request->query('categorie')) ? (int) $request->query('categorie') : null;
        $status = in_array($request->query('statut'), ['actifs', 'inactifs'], true) ? (string) $request->query('statut') : 'actifs';

        $products = Product::query()->ofFacility()
            ->search($search)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($status === 'actifs', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactifs', fn ($query) => $query->where('is_active', false))
            ->with('category')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pharmacie::catalog.products', [
            'products' => $products,
            'categories' => Category::query()->ofFacility()->active()->get(),
            'kinds' => Product::kindLabels(),
            'search' => $search,
            'kind' => $kind,
            'categoryId' => $categoryId,
            'status' => $status,
            'stats' => [
                'total' => Product::query()->ofFacility()->where('is_active', true)->count(),
                'controlled' => Product::query()->ofFacility()->where('is_active', true)->where('is_controlled', true)->count(),
                'priceless' => Product::query()->ofFacility()->where('is_active', true)->whereNull('sale_price')->count(),
            ],
        ]);
    }

    public function show(Product $product): View
    {
        return view('pharmacie::catalog.product', [
            'product' => $product->load('category'),
            'categories' => Category::query()->ofFacility()->active()->get(),
            'kinds' => Product::kindLabels(),
        ]);
    }

    public function store(ProductRequest $request, Auditor $auditor): RedirectResponse
    {
        $product = Product::create($this->attributes($request) + [
            'facility_id' => Facility::current(),
            'is_active' => true,
        ]);

        $auditor->record(
            'product_created',
            $product,
            sprintf('Produit « %s » (%s) référencé', $product->name, $product->code),
            [],
            ['code' => $product->code, 'name' => $product->name, 'kind' => $product->kind],
            $this->user($request),
        );

        return redirect()->route('pharmacie.catalog.products.show', $product)
            ->with('pharmacie_status', sprintf('Le produit « %s » est référencé.', $product->name));
    }

    public function update(ProductRequest $request, Product $product, Auditor $auditor): RedirectResponse
    {
        $before = $product->only(['code', 'name', 'dci', 'kind', 'category_id', 'sale_price', 'min_threshold', 'is_controlled']);
        $attributes = $this->attributes($request);

        // Fixer un prix n'est pas décrire un produit : sans le droit, le prix
        // qu'on renvoie est ignoré plutôt que refusé en bloc.
        if (! Gate::forUser($this->user($request))->allows('pharmacie.prices.manage')) {
            unset($attributes['sale_price']);
        }

        $product->update($attributes);

        $auditor->record(
            'product_updated',
            $product,
            sprintf('Produit « %s » (%s) modifié', $product->name, $product->code),
            $before,
            $product->only(array_keys($before)),
            $this->user($request),
        );

        return redirect()->route('pharmacie.catalog.products.show', $product)
            ->with('pharmacie_status', sprintf('Le produit « %s » a été modifié.', $product->name));
    }

    /**
     * Activer ou désactiver. Un produit désactivé ne se propose plus, mais
     * tout ce qu'il a porté reste lisible.
     */
    public function toggle(Request $request, Product $product, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($product, $user, $auditor): string {
            $fresh = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $active = ! $fresh->is_active;
            $fresh->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'product_activated' : 'product_deactivated',
                $fresh,
                sprintf('Produit « %s » %s', $fresh->name, $active ? 'activé' : 'désactivé'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('Le produit « %s » est %s.', $fresh->name, $active ? 'actif' : 'désactivé');
        });

        return redirect()->route('pharmacie.catalog.products.index')->with('pharmacie_status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductRequest $request): array
    {
        $data = $request->validated();

        return [
            'code' => strtoupper((string) Text::clean($data['code'])),
            'name' => (string) Text::clean($data['name']),
            'dci' => Text::clean($data['dci'] ?? null),
            'brand_name' => Text::clean($data['brand_name'] ?? null),
            'laboratory' => Text::clean($data['laboratory'] ?? null),
            'barcode' => Text::clean($data['barcode'] ?? null),
            'kind' => (string) $data['kind'],
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'therapeutic_class' => Text::clean($data['therapeutic_class'] ?? null),
            'form' => Text::clean($data['form'] ?? null),
            'dosage' => Text::clean($data['dosage'] ?? null),
            'route' => Text::clean($data['route'] ?? null),
            'unit' => (string) Text::clean($data['unit']),
            'packaging' => Text::clean($data['packaging'] ?? null),
            'is_generic' => (bool) ($data['is_generic'] ?? false),
            'is_controlled' => (bool) ($data['is_controlled'] ?? false),
            'min_threshold' => (int) $data['min_threshold'],
            'max_threshold' => isset($data['max_threshold']) ? (int) $data['max_threshold'] : null,
            'sale_price' => isset($data['sale_price']) && $data['sale_price'] !== null ? (int) $data['sale_price'] : null,
            'storage_conditions' => Text::clean($data['storage_conditions'] ?? null),
            'description' => Text::clean($data['description'] ?? null),
        ];
    }
}
