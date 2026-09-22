<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prise en charge acte par acte, par des assurances ou des aides sociales.
 *
 *  - `finance_insurers.kind` : `insurance` (Assurance) ou `social_aid` (Aide
 *    sociale) ;
 *  - `finance_insurers.coverage_scope` : `all` — tous les actes au taux par
 *    défaut, sauf les règles ci-dessous — ou `selected` — seuls les actes
 *    listés ;
 *  - `finance_insurer_acts` : une règle par acte, `rate` nul = taux par
 *    défaut, 0 = acte exclu ;
 *  - chaque ligne de facture porte son taux, sa part prise en charge et sa
 *    part patient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_insurers', function (Blueprint $table): void {
            $table->string('kind', 16)->default('insurance')->after('name')->index();
            $table->string('coverage_scope', 16)->default('all')->after('default_rate');
        });

        Schema::create('finance_insurer_acts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurer_id')->constrained('finance_insurers')->restrictOnDelete();
            $table->foreignId('act_id')->constrained('finance_acts')->restrictOnDelete();
            $table->unsignedTinyInteger('rate')->nullable();
            $table->timestamps();

            $table->unique(['insurer_id', 'act_id']);
        });

        Schema::table('finance_invoice_lines', function (Blueprint $table): void {
            $table->unsignedTinyInteger('insurer_rate')->default(0)->after('amount');
            $table->unsignedBigInteger('insurer_share')->default(0)->after('insurer_rate');
            $table->unsignedBigInteger('patient_share')->default(0)->after('insurer_share');
        });

        // Lignes existantes : la prise en charge de leur facture, répartie au
        // même taux sur chaque ligne ; le dernier franc d'arrondi reste au
        // patient pour que les totaux de la facture ne bougent pas.
        foreach (DB::table('finance_invoices')->get(['id', 'coverage_rate', 'insurer_id', 'insurer_share']) as $invoice) {
            $rate = $invoice->insurer_id === null ? 0 : (int) $invoice->coverage_rate;
            $left = (int) $invoice->insurer_share;
            $lines = DB::table('finance_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('id')->get(['id', 'amount']);

            foreach ($lines as $index => $line) {
                $share = $index === count($lines) - 1 ? $left : min($left, (int) round((int) $line->amount * $rate / 100));
                $left -= $share;

                DB::table('finance_invoice_lines')->where('id', $line->id)->update([
                    'insurer_rate' => $rate,
                    'insurer_share' => $share,
                    'patient_share' => (int) $line->amount - $share,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('finance_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['insurer_rate', 'insurer_share', 'patient_share']);
        });

        Schema::dropIfExists('finance_insurer_acts');

        Schema::table('finance_insurers', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'coverage_scope']);
        });
    }
};
