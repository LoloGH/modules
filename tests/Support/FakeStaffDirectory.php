<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Keneya\Pharmacie\Contracts\StaffDirectory;
use Keneya\Pharmacie\Staff\HostStaff;

/**
 * Un hôte de test qui déclare son personnel de pharmacie.
 *
 * @internal
 */
final class FakeStaffDirectory implements StaffDirectory
{
    /**
     * @param  list<HostStaff>  $staff
     */
    public function __construct(private readonly array $staff = []) {}

    public function staff(): array
    {
        return $this->staff;
    }
}
