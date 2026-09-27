<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La préparation : ce qui existe entre le comptoir et la caisse.
 *
 * Le patient règle avant d'être servi. Entre le moment où le préparateur
 * monte la commande et celui où il la délivre, il y a donc un objet qui
 * n'existait pas : une dispensation décidée, chiffrée, facturée, mais dont
 * rien n'est encore sorti du stock. C'est le statut « en préparation », posé
 * dès la première migration et resté inutilisé jusqu'ici.
 *
 * Ce qui s'ajoute tient en trois idées :
 *
 *   - **qui a préparé, et quand** : ce n'est pas forcément celui qui délivre,
 *     et le registre doit pouvoir le dire ;
 *   - **la prise en charge** : l'assureur ou l'aide sociale porte une part,
 *     le patient l'autre. La pharmacie ne calcule pas ces parts, c'est
 *     l'affaire de Finance, mais elle doit les transmettre avec la facture ;
 *   - **les réservations** : rien n'est bloqué pour une vente ordinaire,
 *     mais une prise en charge demande du temps, et on ne peut pas faire
 *     attendre quelqu'un pour lui annoncer ensuite que le dernier flacon est
 *     parti. Les unités réservées restent physiquement là et sortent du
 *     disponible, sans aucune écriture au grand livre : rien n'a bougé.
 *
 * L'abandon d'une préparation ne demande pas de colonne : c'est une
 * annulation, et les colonnes d'annulation existent déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacie_dispensations', function (Blueprint $table): void {
            $table->timestamp('prepared_at')->nullable()->after('dispensed_at');
            $table->string('prepared_by_id', 64)->nullable()->after('prepared_at');
            $table->string('prepared_by_name')->nullable()->after('prepared_by_id');

            // Ce que la pharmacie transmet à la caisse, et rien de plus : le
            // nom du tiers payant, la part qu'il prend, et la référence de
            // l'accord quand il y en a une.
            $table->string('coverage_insurer')->nullable()->after('billing_note');
            $table->unsignedSmallInteger('coverage_rate')->nullable()->after('coverage_insurer');
            $table->string('coverage_reference', 64)->nullable()->after('coverage_rate');
        });

        Schema::create('pharmacie_dispensation_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispensation_id')->constrained('pharmacie_dispensations')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('pharmacie_batches')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('pharmacie_locations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['batch_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_dispensation_reservations');

        Schema::table('pharmacie_dispensations', function (Blueprint $table): void {
            $table->dropColumn([
                'prepared_at', 'prepared_by_id', 'prepared_by_name',
                'coverage_insurer', 'coverage_rate', 'coverage_reference',
            ]);
        });
    }
};
