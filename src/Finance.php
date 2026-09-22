<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\CatalogProvider;

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
     * Remet à zéro l'état statique (tests).
     */
    public static function flushState(): void
    {
        self::$accessResolver = null;
    }
}
