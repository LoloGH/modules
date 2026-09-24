<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'il advient de l'argent d'une dispensation.
 *
 * La pharmacie ne tient pas de caisse : elle dit ce qui est dû et à quel
 * titre, puis l'envoie par `Contracts\SaleSink`, vers la caisse Finance, ou
 * vers ce que l'établissement aura choisi. Ce qu'elle garde ici, c'est
 * l'état : à payer, envoyé, réglé, ou rien à payer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacie_dispensations', function (Blueprint $table): void {
            // gratuit, a_payer, envoye, regle
            $table->string('payment_status', 16)->default('a_payer')->after('total');
            // Ce qui justifie qu'on ne demande rien : hospitalisation,
            // prise en charge, gratuité décidée par l'établissement.
            $table->string('billing_kind', 24)->default('direct')->after('payment_status');
            // La pièce créée par la caisse (facture, ticket), quand elle en
            // rend une.
            $table->string('billing_reference', 64)->nullable()->after('billing_kind');
            $table->timestamp('billed_at')->nullable()->after('billing_reference');
            $table->text('billing_note')->nullable()->after('billed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacie_dispensations', function (Blueprint $table): void {
            $table->dropColumn(['payment_status', 'billing_kind', 'billing_reference', 'billed_at', 'billing_note']);
        });
    }
};
