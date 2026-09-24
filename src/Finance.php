<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Contracts\CashierDirectory;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\CatalogProvider;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;

/**
 * Point d'entrée statique du module : réglages que l'hôte pose depuis son
 * propre fournisseur de services.
 */
final class Finance
{
    /**
     * @var (Closure(Authenticatable, ?Request): bool)|null
     */
    private static ?Closure $accessResolver = null;

    /**
     * @var (Closure(): array<string, ?string>)|null
     */
    private static ?Closure $facilityResolver = null;

    private static ?Closure $returnLinkResolver = null;

    /**
     * L'hôte décide lui-même de l'accès au module. Dès qu'un résolveur est
     * enregistré, sa réponse est définitive : ni la capacité ni l'attribut
     * ne sont consultés.
     *
     * @param  (Closure(Authenticatable, ?Request): bool)|null  $resolver
     */
    public static function authorizeAccessUsing(?Closure $resolver): void
    {
        self::$accessResolver = $resolver;
    }

    /**
     * @return (Closure(Authenticatable, ?Request): bool)|null
     */
    public static function accessResolver(): ?Closure
    {
        return self::$accessResolver;
    }

    /**
     * Modèle utilisateur de l'hôte.
     *
     * @return class-string
     */
    public static function userModel(): string
    {
        $model = config('finance.models.user') ?? config('auth.providers.users.model');

        return is_string($model) && $model !== '' ? $model : FrameworkUser::class;
    }

    /**
     * Le catalogue des actes, en lecture, pour l'hôte : `Finance::catalog()
     * ->actsForService($serviceId)`. Résolu depuis le conteneur, donc
     * remplaçable par l'hôte ou dans un test.
     */
    public static function catalog(): CatalogProvider
    {
        return app(CatalogProvider::class);
    }

    /**
     * La file d'attente des caisses, fournie par l'hôte : il lie sa propre
     * implémentation de `CashQueueProvider` dans le conteneur. Sans hôte, une
     * file vide.
     */
    public static function cashQueue(): CashQueueProvider
    {
        return app(CashQueueProvider::class);
    }

    /**
     * Ce qui fait avancer, chez l'hôte, une visite que Finance vient
     * d'encaisser. Sans hôte, rien.
     */
    public static function visitAdvancer(): VisitAdvancer
    {
        return app(VisitAdvancer::class);
    }

    /**
     * L'annuaire des caissiers tenu par l'hôte. Sans hôte, vide.
     */
    public static function cashiers(): CashierDirectory
    {
        return app(CashierDirectory::class);
    }

    /**
     * L'hôte fournit l'identité de l'établissement imprimée sur les factures
     * et les reçus (nom, adresse, téléphone, e-mail), par exemple depuis son
     * propre réglage. Lue au moment d'imprimer ; ce qu'elle ne donne pas
     * vient de `finance.facility`.
     *
     * @param  (Closure(): array<string, ?string>)|null  $resolver
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
        $facility = array_map('strval', (array) config('finance.facility', []));

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
        self::$returnLinkResolver = null;
    }

    /**
     * Le lien de retour vers l'application hôte.
     *
     * Quelqu'un entre dans le module depuis un écran de WorkFlow, et doit
     * pouvoir en ressortir. Sans ce lien, la seule issue est le bouton
     * « précédent » du navigateur, ou la déconnexion, ce qui est pire.
     *
     * L'hôte le déclare, avec le libellé qu'il veut et l'adresse qui convient
     * à la personne connectée :
     *
     *     Finance::returnLinkUsing(fn ($user) => [
     *         'label' => 'Retour a KEneYa WorkFlow',
     *         'url' => $user->homeUrl(),
     *     ]);
     *
     * Le module tournant seul n'a nulle part où retourner : sans résolveur,
     * rien ne s'affiche.
     */
    public static function returnLinkUsing(?Closure $resolver): void
    {
        self::$returnLinkResolver = $resolver;
    }

    /**
     * Le lien de retour pour cette personne, ou nul s'il n'y en a pas.
     *
     * @return array{label: string, url: string}|null
     */
    public static function returnLinkFor(mixed $user): ?array
    {
        if (self::$returnLinkResolver === null || $user === null) {
            return null;
        }

        $lien = (self::$returnLinkResolver)($user);

        if (! is_array($lien) || ($lien['url'] ?? '') === '') {
            return null;
        }

        return [
            'label' => (string) ($lien['label'] ?? 'Retour'),
            'url' => (string) $lien['url'],
        ];
    }
}
