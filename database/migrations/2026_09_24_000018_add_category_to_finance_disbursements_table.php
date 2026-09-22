<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La catégorie d'une dépense (`finance.expense_categories`) : de quoi dire
 * où part l'argent, au-delà du motif libre. Nullable : les décaissements
 * existants, et ceux saisis sans catégorie, sont « non classés ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_disbursements', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->after('reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('finance_disbursements', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
