<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centres analytiques : « combien rapporte le laboratoire, l'imagerie… ».
 *
 * Hiérarchie libre et auto-référencée (Pôle -> Service -> Activité) : rien
 * n'impose une profondeur, l'établissement organise ses centres comme il
 * organise ses services.
 *
 * Un centre ne se supprime pas — ni en cascade, ni en détachant ses enfants
 * (`restrictOnDelete`) : un rapport financier passé doit rester lisible.
 * On le désactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_analytic_centers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('finance_analytic_centers')->restrictOnDelete();
            // revenue (produits), cost (charges), both (les deux).
            $table->string('kind', 16)->default('revenue');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_analytic_centers');
    }
};
