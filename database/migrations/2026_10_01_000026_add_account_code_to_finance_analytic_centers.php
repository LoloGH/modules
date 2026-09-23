<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le compte de produits d'un centre analytique, pour l'export comptable.
 *
 * Sans compte propre, le centre suit le compte de produits par défaut réglé
 * dans les paramètres : l'établissement n'a à renseigner que ce qu'il veut
 * distinguer dans sa comptabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_analytic_centers', function (Blueprint $table): void {
            $table->string('account_code', 16)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('finance_analytic_centers', function (Blueprint $table): void {
            $table->dropColumn('account_code');
        });
    }
};
