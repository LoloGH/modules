<?php

declare(strict_types=1);

namespace Workbench\App\Pharmacy;

use Keneya\Pharmacie\Contracts\StaffDirectory;
use Keneya\Pharmacie\Staff\HostStaff;
use Keneya\Pharmacie\Support\Rbac;
use Workbench\App\Models\DemoUser;

/**
 * L'annuaire du personnel de l'hôte de démonstration.
 *
 * Dans WorkFlow, cette liste vient des types de personnel qui portent la
 * capacité « MPH ». Ici, elle vient des profils de démonstration : de quoi
 * voir l'écran « Utilisateurs » fonctionner sans l'application hôte.
 */
final class DemoStaffDirectory implements StaffDirectory
{
    public function staff(): array
    {
        $labels = Rbac::roleLabels();

        return DemoUser::query()
            ->orderBy('name')
            ->get()
            ->map(fn (DemoUser $user): HostStaff => new HostStaff(
                (string) $user->getKey(),
                (string) $user->name,
                $labels[$user->getRoleNames()->first() ?? ''] ?? $user->getRoleNames()->first(),
            ))
            ->values()
            ->all();
    }
}
