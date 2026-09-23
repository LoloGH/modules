<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Support\Facility;

final class CategoryRequest extends PharmacieRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('pharmacie_categories', 'code')->where('facility_id', Facility::current()),
            ],
            'name' => ['required', 'string', 'max:191'],
        ];
    }
}
