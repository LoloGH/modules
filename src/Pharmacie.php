<?php

declare(strict_types=1);

namespace Keneya\Pharmacie;

use Closure;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\App;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Contracts\PrescriptionProvider;
use Keneya\Pharmacie\Contracts\PrescriptionSink;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Contracts\StaffDirectory;

/**
 * Le point d'entrée du module pour l'application hôte.
 *
 * L'hôte n'a que trois choses à déclarer, et il peut les déclarer une par
 * une :
 *
 *   1. qui entre — `Pharmacie::authorizeAccessUsing()` ;
 *   2. qui attend — une implémentation de {@see PharmacyQueueProvider} liée
 *      dans le conteneur ;
 *   3. où part ce qui doit être payé — une implémentation de
 *      {@see SaleSink} (Finance, ou autre chose).
 *
 * Rien d'autre n'est imposé : ni modèle utilisateur, ni page de connexion,
 * ni table de patients.
 */
final class Pharmacie
{
    private static ?Closure $accessResolver = null;

    private static ?Closure $facilityResolver = null;

    /**
     * L'hôte décide qui entre dans le module.
     */
    public static function authorizeAccessUsing(?Closure $resolver): void
    {
        self::$accessResolver = $resolver;
    }

    public static function accessResolver(): ?Closure
    {
        return self::$accessResolver;
    }

    /**
     * Le modèle utilisateur de l'hôte.
     *
     * @return class-string
     */
    public static function userModel(): string
    {
        $model = config('pharmacie.models.user') ?? config('auth.providers.users.model');

        return is_string($model) && $model !== ''
            ? $model
            : User::class;
    }

    /**
     * La file d'attente fournie par l'hôte (vide par défaut).
     */
    public static function queue(): PharmacyQueueProvider
    {
        return App::make(PharmacyQueueProvider::class);
    }

    /**
     * Les ordonnances à servir, fournies par l'hôte (aucune par défaut).
     */
    public static function prescriptions(): PrescriptionProvider
    {
        return App::make(PrescriptionProvider::class);
    }

    /**
     * Ce que la pharmacie rend au dossier médical après avoir servi.
     */
    public static function prescriptionSink(): PrescriptionSink
    {
        return App::make(PrescriptionSink::class);
    }

    /**
     * Le personnel que l'hôte fait entrer dans la pharmacie (aucun par
     * défaut) : c'est la liste de l'écran « Utilisateurs ».
     */
    public static function staff(): StaffDirectory
    {
        return App::make(StaffDirectory::class);
    }

    /**
     * Le point de sortie de ce qui doit être payé (aucun par défaut).
     */
    public static function sales(): SaleSink
    {
        return App::make(SaleSink::class);
    }

    /**
     * L'hôte peut imposer l'identité imprimée sur les documents.
     */
    public static function facilityUsing(?Closure $resolver): void
    {
        self::$facilityResolver = $resolver;
    }

    /**
     * L'établissement tel qu'il s'imprime : la configuration, complétée par
     * l'hôte. Une valeur vide n'est pas imprimée.
     *
     * @return array<string, string>
     */
    public static function facility(): array
    {
        $facility = array_map('strval', (array) config('pharmacie.facility', []));

        if (self::$facilityResolver !== null) {
            foreach ((array) (self::$facilityResolver)() as $key => $value) {
                if ($value !== null) {
                    $facility[$key] = (string) $value;
                }
            }
        }

        return $facility;
    }

    /**
     * Remet à zéro l'état statique (tests).
     */
    public static function flushState(): void
    {
        self::$accessResolver = null;
        self::$facilityResolver = null;
    }
}
