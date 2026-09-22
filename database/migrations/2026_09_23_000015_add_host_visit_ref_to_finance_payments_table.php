<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La visite de l'hôte qu'un encaissement a réglée, quand le caissier a
 * encaissé un patient appelé depuis la file.
 *
 * Référence opaque, sans clé étrangère : la visite appartient à l'hôte. Elle
 * sert à retrouver, depuis Finance, ce qui a été payé pour quel passage, et
 * à refuser qu'une même visite soit encaissée deux fois.
 *
 * Nullable : un encaissement au guichet, hors file, n'en a pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->string('host_visit_ref', 64)->nullable()->after('patient_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('finance_payments', function (Blueprint $table): void {
            $table->dropIndex(['host_visit_ref']);
            $table->dropColumn('host_visit_ref');
        });
    }
};
