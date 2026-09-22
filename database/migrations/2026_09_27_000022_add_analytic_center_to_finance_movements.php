<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le centre analytique inscrit sur l'écriture elle-même.
 *
 * Jusqu'ici, « combien rapporte le laboratoire » se lisait en remontant de
 * l'encaissement à l'acte puis au centre. Rattacher un acte à un autre centre
 * réécrivait alors le passé, et une dépense ne se rattachait à rien.
 *
 * Chaque écriture porte désormais son centre, gravé au moment où elle est
 * écrite : les mouvements déjà enregistrés reprennent celui de leur acte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->foreignId('analytic_center_id')->nullable()->after('act_id')
                ->constrained('finance_analytic_centers')->restrictOnDelete();
        });

        Schema::table('finance_invoice_lines', function (Blueprint $table): void {
            $table->foreignId('analytic_center_id')->nullable()->after('act_id')
                ->constrained('finance_analytic_centers')->restrictOnDelete();
        });

        Schema::table('finance_disbursements', function (Blueprint $table): void {
            $table->foreignId('analytic_center_id')->nullable()->after('category')
                ->constrained('finance_analytic_centers')->restrictOnDelete();
        });

        // Le passé garde le rattachement qu'il avait : celui de son acte.
        foreach (['finance_payments', 'finance_invoice_lines'] as $table) {
            DB::table($table)->whereNotNull('act_id')->update([
                'analytic_center_id' => DB::raw(
                    "(select analytic_center_id from finance_acts where finance_acts.id = {$table}.act_id)"
                ),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['finance_payments', 'finance_invoice_lines', 'finance_disbursements'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('analytic_center_id');
            });
        }
    }
};
