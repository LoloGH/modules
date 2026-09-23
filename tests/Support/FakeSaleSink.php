<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Sales\DispensedSale;

/**
 * Une caisse d'hôte, pour les tests : elle retient ce qu'on lui envoie et
 * rend une référence de pièce, comme le ferait Finance.
 */
final class FakeSaleSink implements SaleSink
{
    /** @var list<DispensedSale> */
    public array $sales = [];

    public function __construct(private readonly ?string $reference = 'FAC-2026-000001') {}

    public function send(DispensedSale $sale): ?string
    {
        $this->sales[] = $sale;

        return $this->reference;
    }
}
