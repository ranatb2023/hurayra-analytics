<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every status change a subscription went through, from the "Status changed
 * from X to Y" notes WooCommerce Subscriptions writes on each change.
 *
 * `records.status` only holds the status today, so without this table a
 * subscription that was on hold in March and has since resumed leaves no trace
 * of that hold anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->unsignedBigInteger('subscription_id');
            $table->dateTime('changed_at');
            // '' rather than NULL when unknown, so the unique key still dedupes.
            $table->string('from_status', 32)->default('');
            $table->string('to_status', 32);
            $table->timestamps();

            $table->unique(['subscription_id', 'changed_at', 'from_status', 'to_status'], 'sub_status_changes_unique');
            $table->index(['subscription_id', 'changed_at'], 'sub_status_changes_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_status_changes');
    }
};
