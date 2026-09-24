<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Product;

/**
 * Le contrôle renforcé : ce qu'un produit sous surveillance exige de plus.
 *
 * Les stupéfiants et les produits sous contrôle ne se délivrent pas comme une
 * boîte de paracétamol : il faut une ordonnance, un patient nommé, et une
 * personne habilitée. Mais la réglementation n'est pas la même d'un pays à
 * l'autre, et elle change : tout est donc lu dans `pharmacie.controlled`,
 * rien n'est figé dans le code.
 *
 * Le drapeau `is_controlled` du produit décide si ces règles s'appliquent ;
 * cette classe décide ce qu'elles exigent.
 */
final class Controlled
{
    /**
     * Vérifie qu'une ligne portant un produit sous surveillance peut être
     * servie. Ne fait rien pour les autres produits.
     *
     * @param  array{patient_id?: ?string, patient_name?: ?string, prescription_ref?: ?string}  $details
     */
    public static function assertDispensable(Product $product, array $details, ?Authenticatable $actor, int $position): void
    {
        if (! self::applies($product)) {
            return;
        }

        $intro = sprintf('Ligne %d (%s) : produit sous surveillance', $position, $product->name);

        $ability = self::ability();

        if ($ability !== null && ($actor === null || ! Gate::forUser($actor)->allows($ability))) {
            throw new PharmacieRuleViolation(
                $intro.' — sa délivrance demande une habilitation particulière : appelez le pharmacien.'
            );
        }

        if (self::rule('require_prescription') && Text::clean($details['prescription_ref'] ?? null) === null) {
            throw new PharmacieRuleViolation(
                $intro.' — il ne se délivre que sur ordonnance : indiquez la référence de l\'ordonnance.'
            );
        }

        if (self::rule('require_patient')
            && Text::clean($details['patient_id'] ?? null) === null
            && Text::clean($details['patient_name'] ?? null) === null) {
            throw new PharmacieRuleViolation(
                $intro.' — il ne se délivre pas anonymement : nommez le patient.'
            );
        }
    }

    public static function applies(?Product $product): bool
    {
        return $product !== null && (bool) $product->is_controlled;
    }

    /**
     * La capacité exigée pour servir un produit sous surveillance, ou null
     * si l'établissement n'en exige aucune.
     */
    public static function ability(): ?string
    {
        $ability = config('pharmacie.controlled.ability');

        return is_string($ability) && $ability !== '' ? $ability : null;
    }

    private static function rule(string $key): bool
    {
        return (bool) config("pharmacie.controlled.{$key}", true);
    }
}
