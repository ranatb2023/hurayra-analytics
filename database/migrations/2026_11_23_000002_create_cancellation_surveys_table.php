<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cancellation form: why each customer cancelled, and whether they took
 * the retention discount it offers. Imported from 07-export-cancellation-form.sql.
 *
 * One row per survey submission. A subscription that accepted the offer with
 * no survey on record has a row too (survey_id NULL), so the accepted count
 * reconciles with what WooCommerce holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            // "survey:{id}", or "sub:{id}" for an offer accepted with no survey.
            $table->string('source_key', 40)->unique();
            $table->unsignedBigInteger('survey_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            // When the form was sent, or the offer accepted when there is no form.
            $table->dateTime('submitted_at')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('reason', 64)->nullable();
            $table->string('satisfaction', 32)->nullable();
            $table->string('would_return', 16)->nullable();
            $table->text('feedback')->nullable();
            $table->decimal('subscription_total', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->text('products')->nullable();
            // The survey's own flag, and the subscription's record of the offer.
            $table->boolean('retention_used')->default(false);
            $table->dateTime('retention_accepted_at')->nullable();
            $table->decimal('retention_percent', 6, 2)->nullable();
            $table->decimal('retention_shipping', 8, 2)->nullable();
            // Paid renewals that carried the discount: the offer actually spent.
            $table->unsignedInteger('retention_spent_orders')->default(0);
            $table->dateTime('retention_first_spent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_surveys');
    }
};
