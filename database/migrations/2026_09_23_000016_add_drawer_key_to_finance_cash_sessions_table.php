<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le tiroir d'une session : des caisses ouvertes ensemble avec un seul fonds
 * partagent le même tiroir (`drawer_key` commune).
 *
 * La limite d'un caissier se compte en tiroirs, pas en sessions : ouvrir
 * Ticket, Services et Pharmacie avec un seul fonds tient dans « un caissier,
 * un tiroir ». Chaque caisse garde sa session et sa clôture.
 *
 * Nulle : session ouverte seule, c'est son propre tiroir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_cash_sessions', function (Blueprint $table): void {
            $table->string('drawer_key', 36)->nullable()->after('cashier_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('finance_cash_sessions', function (Blueprint $table): void {
            $table->dropIndex(['drawer_key']);
            $table->dropColumn('drawer_key');
        });
    }
};
