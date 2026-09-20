<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

final class Money
{
    /**
     * 5000 -> « 5 000 FCFA » (devise et décimales viennent de la configuration).
     */
    public static function format(int $amount): string
    {
        $symbol = (string) config('finance.currency.symbol', 'FCFA');
        $decimals = (int) config('finance.currency.decimals', 0);

        return number_format($amount, $decimals, ',', ' ').' '.$symbol;
    }
}
