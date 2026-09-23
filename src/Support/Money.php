<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

final class Money
{
    /**
     * 5000 -> « 5 000 FCFA » (devise et décimales viennent de la configuration).
     */
    public static function format(int $amount): string
    {
        $symbol = (string) config('pharmacie.currency.symbol', 'FCFA');
        $decimals = (int) config('pharmacie.currency.decimals', 0);

        return number_format($amount, $decimals, ',', ' ').' '.$symbol;
    }
}
