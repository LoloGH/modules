<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Illuminate\Support\Collection;
use Keneya\FinanceCaisse\Models\AnalyticCenter;

/**
 * L'arborescence des centres analytiques (Pôle vers Service vers Activité), lue une
 * fois et exploitée partout : affichage à plat avec sa profondeur, et la
 * descendance d'un centre.
 *
 * La descendance est ce qui rend le rattachement « fin » utilisable : filtrer
 * ou totaliser sur un pôle prend tout ce qu'il porte, sans qu'on ait à cocher
 * ses enfants un par un.
 *
 * Un centre dont le parent a disparu est rattaché à la racine plutôt
 * qu'escamoté, et une boucle de parenté (impossible par les écrans, mais pas
 * par la base) ne fait pas tourner la lecture en rond.
 */
final class AnalyticTree
{
    /** @var array<int, list<int>> enfants directs, par identifiant de parent (0 = racine) */
    private array $children = [];

    /** @var array<int, AnalyticCenter> */
    private array $centers = [];

    /**
     * @param  Collection<int, AnalyticCenter>|iterable<AnalyticCenter>  $centers
     */
    public function __construct(iterable $centers)
    {
        foreach ($centers as $center) {
            $this->centers[(int) $center->id] = $center;
        }

        foreach ($this->centers as $id => $center) {
            $parent = (int) ($center->parent_id ?? 0);

            if ($parent !== 0 && ! isset($this->centers[$parent])) {
                $parent = 0;
            }

            $this->children[$parent][] = $id;
        }
    }

    public static function load(): self
    {
        return new self(AnalyticCenter::query()->orderBy('name')->get());
    }

    /**
     * Les centres à plat, dans l'ordre de l'arborescence, chacun avec sa
     * profondeur : la vue n'a plus qu'à décaler le libellé.
     *
     * @return list<array{center: AnalyticCenter, depth: int}>
     */
    public function flat(): array
    {
        $flat = [];
        $seen = [];

        $walk = function (int $parent, int $depth) use (&$walk, &$flat, &$seen): void {
            foreach ($this->children[$parent] ?? [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $flat[] = ['center' => $this->centers[$id], 'depth' => $depth];

                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        return $flat;
    }

    /**
     * Le centre et tout ce qu'il porte, à toutes les profondeurs.
     *
     * @return list<int>
     */
    public function withDescendants(int $id): array
    {
        if (! isset($this->centers[$id])) {
            return [$id];
        }

        $ids = [];
        $stack = [$id];

        while ($stack !== []) {
            $current = (int) array_pop($stack);

            if (isset($ids[$current])) {
                continue;
            }

            $ids[$current] = true;

            foreach ($this->children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Le libellé décalé selon la profondeur, pour un tableau ou un export.
     */
    public function label(AnalyticCenter $center, int $depth): string
    {
        // Espaces insécables : un tableau HTML replierait des espaces
        // ordinaires, et le décalage qui montre la hiérarchie disparaîtrait.
        return $depth === 0 ? $center->name : str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth).'└ '.$center->name;
    }
}
