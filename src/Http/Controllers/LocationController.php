<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Les emplacements de stock : savoir combien on en a ne suffit pas, il faut
 * savoir où.
 *
 * Un emplacement se désactive, il ne se supprime pas, et pas tant qu'il
 * porte encore des unités, qui ne se trouveraient plus nulle part.
 */
final class LocationController extends PharmacieController
{
    public function index(): View
    {
        $locations = Location::query()->ofFacility()->orderBy('name')->get();

        return view('pharmacie::stock.locations', [
            'locations' => $locations->map(fn (Location $location): array => [
                'location' => $location,
                'on_hand' => (int) $location->stocks()->sum('quantity'),
            ]),
            'kinds' => Location::kindLabels(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('pharmacie_locations', 'code')->where('facility_id', Facility::current())],
            'name' => ['required', 'string', 'max:191'],
            'kind' => ['required', Rule::in(array_keys(Location::kindLabels()))],
        ]);

        $location = Location::create([
            'facility_id' => Facility::current(),
            'code' => strtoupper((string) Text::clean($data['code'])),
            'name' => (string) Text::clean($data['name']),
            'kind' => $data['kind'],
            'is_active' => true,
        ]);

        $auditor->record(
            'location_created',
            $location,
            sprintf('Emplacement « %s » (%s) créé', $location->name, $location->code),
            [],
            ['code' => $location->code, 'name' => $location->name, 'kind' => $location->kind],
            $this->user($request),
        );

        return redirect()->route('pharmacie.stock.locations.index')
            ->with('pharmacie_status', sprintf('L\'emplacement « %s » a été créé.', $location->name));
    }

    public function toggle(Request $request, Location $location, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($location, $user, $auditor): string {
            $fresh = Location::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->is_active && (int) $fresh->stocks()->sum('quantity') > 0) {
                throw new PharmacieRuleViolation(
                    "L'emplacement « {$fresh->name} » contient encore du stock : transférez-le avant de le désactiver."
                );
            }

            $active = ! $fresh->is_active;
            $fresh->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'location_activated' : 'location_deactivated',
                $fresh,
                sprintf('Emplacement « %s » %s', $fresh->name, $active ? 'activé' : 'désactivé'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('L\'emplacement « %s » est %s.', $fresh->name, $active ? 'actif' : 'désactivé');
        });

        return redirect()->route('pharmacie.stock.locations.index')->with('pharmacie_status', $message);
    }
}
