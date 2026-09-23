<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Support\Facility;

/**
 * Référencer ou modifier un produit. Le code est unique dans l'établissement :
 * deux produits ne partagent jamais la même référence interne.
 */
final class ProductRequest extends PharmacieRequest
{
    protected array $moneyFields = ['sale_price'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('pharmacie_products', 'code')
                    ->where('facility_id', Facility::current())
                    ->ignore($product?->id),
            ],
            'name' => ['required', 'string', 'max:191'],
            'dci' => ['nullable', 'string', 'max:191'],
            'brand_name' => ['nullable', 'string', 'max:191'],
            'laboratory' => ['nullable', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'kind' => ['required', Rule::in(array_keys(Product::kindLabels()))],
            'category_id' => ['nullable', 'integer', 'exists:pharmacie_categories,id'],
            'therapeutic_class' => ['nullable', 'string', 'max:191'],
            'form' => ['nullable', 'string', 'max:64'],
            'dosage' => ['nullable', 'string', 'max:64'],
            'route' => ['nullable', 'string', 'max:64'],
            'unit' => ['required', 'string', 'max:32'],
            'packaging' => ['nullable', 'string', 'max:191'],
            'is_generic' => ['nullable', 'boolean'],
            'is_controlled' => ['nullable', 'boolean'],
            'min_threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Le prix de vente courant. Le prix d'un lot précis peut différer
            // (voir les lots) : celui-ci est le prix proposé au comptoir.
            'sale_price' => ['nullable', 'integer', 'min:0'],
            'storage_conditions' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
