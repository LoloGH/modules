<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation des caissiers aux caisses : qui a le droit d'ouvrir quel
 * tiroir.
 *
 * Convention importante : **aucune ligne pour un caissier signifie « toutes
 * les caisses »**. L'affectation est une restriction volontaire, pas un
 * passage obligé, un établissement qui ne s'en sert pas continue de
 * fonctionner comme avant, et personne ne se retrouve enfermé dehors parce
 * qu'un administrateur a oublié de cocher une case.
 *
 * `cashier_id` est la même chaîne que dans les sessions : le module ne
 * possède pas la table des utilisateurs, donc pas de clé étrangère de ce
 * côté. Côté caisse en revanche, la clé étrangère existe et ne cascade pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cashier_registers', function (Blueprint $table): void {
            $table->id();
            $table->string('cashier_id', 64);
            $table->foreignId('cash_register_id')->constrained('finance_cash_registers')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cashier_id', 'cash_register_id']);
            $table->index('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cashier_registers');
    }
};
