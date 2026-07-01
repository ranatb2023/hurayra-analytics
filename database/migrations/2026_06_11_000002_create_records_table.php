<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            // PK = the WooCommerce wp_wc_orders.id. Re-uploads upsert on this id,
            // so importing the same file twice never duplicates rows.
            $table->unsignedBigInteger('id')->primary();

            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();

            $table->string('record_type', 32);              // shop_order | shop_subscription
            $table->string('status', 32);                   // normalised (wc- stripped)
            $table->dateTime('date_created_gmt')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('order_relationship', 32)->nullable(); // subscription|parent|renewal|one_time
            $table->string('billing_email')->nullable();

            $table->timestamps();

            // Cohort filters (orders) and snapshot filters (subscriptions) both hit these.
            $table->index(['record_type', 'status', 'date_created_gmt'], 'records_type_status_date_idx');
            $table->index(['record_type', 'order_relationship', 'date_created_gmt'], 'records_type_rel_date_idx');
            $table->index('subscription_id');
            $table->index('date_created_gmt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
