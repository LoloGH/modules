<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attribue les identifiants métier : <PREFIXE>-<ANNEE>-<SEQUENCE>.
 *
 * Le préfixe vient de `finance.identifiers.prefixes`. La séquence repart à 1
 * chaque année et est attribuée sous verrou de ligne : deux appels
 * simultanés ne peuvent pas obtenir le même numéro.
 *
 * Un numéro attribué n'est jamais réutilisé, même si l'opération qui
 * l'utilisait est annulée : une facture annulée garde son numéro.
 */
final class NumberGenerator
{
    public function next(string $key): string
    {
        $prefix = config("finance.identifiers.prefixes.{$key}");

        if (! is_string($prefix) || $prefix === '') {
            throw new InvalidArgumentException("Aucun préfixe d'identifiant déclaré pour « {$key} ».");
        }

        $padding = max(1, (int) config('finance.identifiers.padding', 6));
        $year = (int) now()->year;

        $number = DB::transaction(function () use ($key, $year): int {
            $now = now();

            // Crée la ligne si elle n'existe pas encore, sans échouer si un
            // autre appel vient de la créer.
            DB::table('finance_sequences')->insertOrIgnore([
                'sequence_key' => $key,
                'year' => $year,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::table('finance_sequences')
                ->where('sequence_key', $key)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $next = ((int) $row->last_number) + 1;

            DB::table('finance_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            return $next;
        });

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $number, $padding, '0', STR_PAD_LEFT));
    }
}
