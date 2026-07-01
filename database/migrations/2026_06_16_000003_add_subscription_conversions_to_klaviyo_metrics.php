<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            // Subscription purchases attributed to campaigns (WC Subscription Created / Renewal).
            $table->unsignedInteger('sub_created_conversions')->nullable()->after('conversions');
            $table->decimal('sub_created_revenue', 14, 2)->nullable()->after('sub_created_conversions');
            $table->unsignedInteger('sub_renewal_conversions')->nullable()->after('sub_created_revenue');
            $table->decimal('sub_renewal_revenue', 14, 2)->nullable()->after('sub_renewal_conversions');
        });
    }

    public function down(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            $table->dropColumn(['sub_created_conversions', 'sub_created_revenue', 'sub_renewal_conversions', 'sub_renewal_revenue']);
        });
    }
};
