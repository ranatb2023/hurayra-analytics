<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // Present in the real wp_wc_orders export; unlocks customer-level analytics.
            $table->unsignedBigInteger('customer_id')->nullable()->after('subscription_id');
            $table->index(['record_type', 'customer_id', 'date_created_gmt'], 'records_type_customer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex('records_type_customer_date_idx');
            $table->dropColumn('customer_id');
        });
    }
};
