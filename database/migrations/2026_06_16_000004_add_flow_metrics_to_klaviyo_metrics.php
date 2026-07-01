<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            // Automated-flow performance (mirrors the campaign columns).
            $table->decimal('flow_delivery_rate', 5, 1)->nullable()->after('sub_renewal_revenue');
            $table->decimal('flow_open_rate', 5, 1)->nullable()->after('flow_delivery_rate');
            $table->decimal('flow_click_rate', 5, 1)->nullable()->after('flow_open_rate');
            $table->decimal('flow_revenue', 14, 2)->nullable()->after('flow_click_rate');
            $table->unsignedInteger('flow_conversions')->nullable()->after('flow_revenue');
            $table->unsignedInteger('flow_sub_created_conversions')->nullable()->after('flow_conversions');
            $table->decimal('flow_sub_created_revenue', 14, 2)->nullable()->after('flow_sub_created_conversions');
            $table->unsignedInteger('flow_sub_renewal_conversions')->nullable()->after('flow_sub_created_revenue');
            $table->decimal('flow_sub_renewal_revenue', 14, 2)->nullable()->after('flow_sub_renewal_conversions');
        });
    }

    public function down(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'flow_delivery_rate', 'flow_open_rate', 'flow_click_rate', 'flow_revenue', 'flow_conversions',
                'flow_sub_created_conversions', 'flow_sub_created_revenue', 'flow_sub_renewal_conversions', 'flow_sub_renewal_revenue',
            ]);
        });
    }
};
