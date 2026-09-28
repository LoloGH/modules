<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Coverage\HostInsurer;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\ProductCoverage;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;

/**
 * Ce qu'un organisme couvre sur un produit.
 *
 * La liste des assurances et des aides sociales vient de l'hôte : la caisse
 * les connaît, elle suit leurs créances, et elle seule sait lesquelles sont
 * encore actives. Ce que la pharmacie déclare ici, c'est ce qu'elles couvrent
 * parmi **ses** produits, et à quel taux.
 *
 * Rien n'est automatique. Un produit sans ligne n'est couvert par personne,
 * et au comptoir le préparateur choisit l'organisme : un taux appliqué sans
 * qu'on l'ait voulu se paie en créances qu'aucun organisme ne reconnaît.
 */
final class CoverageController extends PharmacieController
{
    public function store(Request $request, Product $product, Auditor $auditor): RedirectResponse
    {
        $insurers = $this->directory();

        $data = $request->validate([
            'insurer_ref' => ['required', 'string', 'max:64'],
            'rate' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['insurer_ref' => 'organisme', 'rate' => 'taux']);

        $insurer = $insurers[$data['insurer_ref']] ?? null;

        if ($insurer === null) {
            return back()->with(
                'pharmacie_error',
                "Cet organisme n'est pas proposé par l'application hôte : il a pu être désactivé.",
            );
        }

        $coverage = ProductCoverage::updateOrCreate(
            ['product_id' => $product->id, 'insurer_ref' => $insurer->ref],
            [
                'facility_id' => Facility::current(),
                // Le nom est recopie : si la caisse renomme l'organisme, la
                // regle reste lisible telle qu'elle a ete posee.
                'insurer_name' => $insurer->name,
                'rate' => (int) $data['rate'],
                'declared_by_id' => Actor::id($this->user($request)),
                'declared_by_name' => Actor::name($this->user($request)),
            ],
        );

        $auditor->record(
            'product_coverage_set',
            $product,
            sprintf(
                '%s prend en charge « %s » à %d %%',
                $insurer->name,
                $product->name,
                $coverage->rate,
            ),
            [],
            ['insurer' => $insurer->ref, 'rate' => $coverage->rate],
            $this->user($request),
        );

        return back()->with(
            'pharmacie_status',
            sprintf('%s prend en charge %d %% de « %s ».', $insurer->name, $coverage->rate, $product->name),
        );
    }

    public function destroy(Request $request, Product $product, ProductCoverage $coverage, Auditor $auditor): RedirectResponse
    {
        abort_if((int) $coverage->product_id !== (int) $product->id, 404);

        $auditor->record(
            'product_coverage_removed',
            $product,
            sprintf('%s ne prend plus en charge « %s »', $coverage->insurer_name, $product->name),
            ['insurer' => $coverage->insurer_ref, 'rate' => $coverage->rate],
            [],
            $this->user($request),
        );

        $coverage->delete();

        return back()->with(
            'pharmacie_status',
            sprintf('%s ne prend plus en charge « %s ».', $coverage->insurer_name, $product->name),
        );
    }

    /**
     * Les organismes de l'hôte, indexés par leur référence.
     *
     * @return array<string, HostInsurer>
     */
    private function directory(): array
    {
        $indexed = [];

        foreach (Pharmacie::insurers()->insurers() as $insurer) {
            $indexed[$insurer->ref] = $insurer;
        }

        return $indexed;
    }
}
