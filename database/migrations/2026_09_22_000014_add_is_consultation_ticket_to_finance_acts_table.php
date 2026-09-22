<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'acte qui se paie au guichet d'accueil : le « ticket de consultation ».
 *
 * L'hôte le demande au catalogue (`CatalogProvider::ticketAct()`) au lieu de
 * le connaître en dur. Un seul acte le porte à la fois : l'unicité est
 * garantie par l'action `SetConsultationTicket`, qui retire la marque de
 * l'ancien ticket sous verrou. Pas d'index unique partiel : MySQL ne sait pas
 * en faire, et le module tourne sur SQLite comme sur MySQL.
 *
 * Additive : les actes existants ne sont pas des tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_acts', function (Blueprint $table): void {
            $table->boolean('is_consultation_ticket')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('finance_acts', function (Blueprint $table): void {
            $table->dropColumn('is_consultation_ticket');
        });
    }
};
