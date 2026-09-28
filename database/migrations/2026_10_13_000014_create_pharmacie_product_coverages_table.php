<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quel organisme couvre quel produit, et a quel taux.
 *
 * La liste des assureurs et des aides sociales appartient a la caisse : elle
 * suit leurs creances et sait lesquels sont actifs. Mais ce qu'un organisme
 * couvre parmi les medicaments porte sur le catalogue de la pharmacie, et
 * c'est elle qui le tient.
 *
 * Le nom de l'organisme est recopie a cote de sa reference : si la caisse le
 * renomme ou le retire, la regle reste lisible telle qu'elle a ete posee, et
 * le comptoir sait de quoi on parlait.
 *
 * Aucune regle par defaut : un produit sans ligne ici n'est couvert par
 * personne. Une prise en charge se declare, elle ne se devine pas — un taux
 * suppose se paie en creances qu'aucun organisme ne reconnait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacie_product_coverages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('facility_id')->default(1)->index();
            $table->foreignId('product_id')->constrained('pharmacie_products')->cascadeOnDelete();

            // La reference opaque de l'hote, et le nom au moment de la regle.
            $table->string('insurer_ref', 64);
            $table->string('insurer_name');

            // Le taux, en pourcentage entier : la caisse decoupe, la pharmacie
            // ne calcule pas de centimes.
            $table->unsignedTinyInteger('rate');

            $table->string('declared_by_id', 64)->nullable();
            $table->string('declared_by_name')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'insurer_ref']);
            $table->index(['facility_id', 'insurer_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacie_product_coverages');
    }
};
