<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * La période que regarde le tableau de bord, et celle à laquelle on la
 * compare.
 *
 * Quatre choix seulement : le jour, sept jours, trente jours, le mois en
 * cours. Un chiffre du jour ne se compare qu'à la veille, un mois au mois
 * précédent : la comparaison est portée par la période elle-même, pour que
 * l'écran n'ait jamais à la deviner.
 */
final class DashboardPeriod
{
    public const DEFAULT = 'jour';

    /** @var array<string, string> */
    public const CHOICES = [
        'jour' => "Aujourd'hui",
        '7j' => '7 jours',
        '30j' => '30 jours',
        'mois' => 'Ce mois',
    ];

    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Carbon $previousFrom,
        public readonly Carbon $previousTo,
        public readonly string $label,
        public readonly string $comparison,
        public readonly int $seriesDays,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $key = $request->query('periode');

        return self::make(is_string($key) ? $key : self::DEFAULT);
    }

    public static function make(string $key): self
    {
        $today = Carbon::today();

        return match ($key) {
            '7j' => new self(
                '7j',
                $today->copy()->subDays(6)->startOfDay(),
                $today->copy()->endOfDay(),
                $today->copy()->subDays(13)->startOfDay(),
                $today->copy()->subDays(7)->endOfDay(),
                'des 7 derniers jours',
                'les 7 jours précédents',
                7,
            ),
            '30j' => new self(
                '30j',
                $today->copy()->subDays(29)->startOfDay(),
                $today->copy()->endOfDay(),
                $today->copy()->subDays(59)->startOfDay(),
                $today->copy()->subDays(30)->endOfDay(),
                'des 30 derniers jours',
                'les 30 jours précédents',
                30,
            ),
            'mois' => new self(
                'mois',
                $today->copy()->startOfMonth(),
                $today->copy()->endOfDay(),
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfDay(),
                'du mois',
                'le mois dernier',
                (int) $today->copy()->startOfMonth()->diffInDays($today) + 1,
            ),
            // Le jour : la comparaison est la veille, mais le graphique garde
            // une semaine de recul, une seule barre ne dit rien.
            default => new self(
                'jour',
                $today->copy()->startOfDay(),
                $today->copy()->endOfDay(),
                $today->copy()->subDay()->startOfDay(),
                $today->copy()->subDay()->endOfDay(),
                'du jour',
                'hier',
                7,
            ),
        };
    }

    /**
     * Les bornes de la période, pour un `whereBetween`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        return [$this->from, $this->to];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousRange(): array
    {
        return [$this->previousFrom, $this->previousTo];
    }

    /**
     * Le premier jour du graphique : la période, ou la semaine de recul du
     * mode « aujourd'hui ».
     */
    public function seriesFrom(): Carbon
    {
        return $this->to->copy()->startOfDay()->subDays($this->seriesDays - 1);
    }
}
