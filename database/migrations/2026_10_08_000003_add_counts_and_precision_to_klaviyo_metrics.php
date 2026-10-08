<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rates were stored at one decimal place, so a campaign click rate of
     * 0.04% -- three clicks on eight thousand sends -- was saved as 0.0% and
     * read as "click tracking is broken". Two decimals, plus the counts the
     * rates are computed from, so a real zero can be told from a small one.
     */
    private const RATES = ['delivery_rate', 'open_rate', 'click_rate', 'flow_delivery_rate', 'flow_open_rate', 'flow_click_rate'];

    public function up(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            foreach (self::RATES as $column) {
                $table->decimal($column, 6, 2)->nullable()->change();
            }

            $table->unsignedInteger('delivered')->nullable()->after('click_rate');
            $table->unsignedInteger('opens_unique')->nullable()->after('delivered');
            $table->unsignedInteger('clicks_unique')->nullable()->after('opens_unique');
            $table->unsignedInteger('flow_delivered')->nullable()->after('flow_click_rate');
            $table->unsignedInteger('flow_opens_unique')->nullable()->after('flow_delivered');
            $table->unsignedInteger('flow_clicks_unique')->nullable()->after('flow_opens_unique');
        });
    }

    public function down(): void
    {
        Schema::table('klaviyo_metrics', function (Blueprint $table) {
            $table->dropColumn(['delivered', 'opens_unique', 'clicks_unique', 'flow_delivered', 'flow_opens_unique', 'flow_clicks_unique']);

            foreach (self::RATES as $column) {
                $table->decimal($column, 5, 1)->nullable()->change();
            }
        });
    }
};
