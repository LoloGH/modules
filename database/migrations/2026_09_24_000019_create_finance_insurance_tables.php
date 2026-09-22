<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assurances : les assureurs, la prise en charge d'une facture (part
 * assurance / part patient), les règlements reçus de l'assureur et ses rejets.
 *
 * Sur la facture :
 *  - `patient_share` + `insurer_share` = `total` (sans assureur, la part
 *    patient est le total) ;
 *  - ce que le patient doit = part patient + montants rejetés par l'assureur ;
 *  - ce que l'assureur doit encore = part assurance − réglé − rejeté.
 *
 * Un règlement d'assureur ne passe pas par le tiroir de caisse (virement,
 * chèque) : il a sa table, pas une ligne d'encaissement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_insurers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('default_rate')->default(80);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->foreignId('insurer_id')->nullable()->after('patient_name')->constrained('finance_insurers')->restrictOnDelete();
            $table->unsignedTinyInteger('coverage_rate')->default(0)->after('insurer_id');
            $table->string('policy_number', 64)->nullable()->after('coverage_rate');
            $table->unsignedBigInteger('insurer_share')->default(0)->after('total');
            $table->unsignedBigInteger('patient_share')->default(0)->after('insurer_share');
            $table->unsignedBigInteger('insurer_paid')->default(0)->after('paid');
            $table->unsignedBigInteger('insurer_rejected')->default(0)->after('insurer_paid');
            $table->string('claim_status', 16)->nullable()->after('status')->index();
        });

        // Les factures déjà émises n'ont pas d'assureur : le patient doit tout.
        DB::table('finance_invoices')->update(['patient_share' => DB::raw('total')]);

        Schema::create('finance_insurance_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->foreignId('insurer_id')->constrained('finance_insurers')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('reference')->nullable();
            $table->date('received_on');
            $table->string('recorded_by_id', 64)->nullable();
            $table->string('recorded_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_insurance_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->foreignId('insurer_id')->constrained('finance_insurers')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('recorded_by_id', 64)->nullable();
            $table->string('recorded_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_insurance_rejections');
        Schema::dropIfExists('finance_insurance_settlements');

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropIndex(['claim_status']);
            $table->dropConstrainedForeignId('insurer_id');
            $table->dropColumn(['coverage_rate', 'policy_number', 'insurer_share', 'patient_share', 'insurer_paid', 'insurer_rejected', 'claim_status']);
        });

        Schema::dropIfExists('finance_insurers');
    }
};
