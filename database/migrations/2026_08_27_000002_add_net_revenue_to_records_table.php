<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // `total_amount` is GROSS: it carries VAT and shipping. On this data
            // that is ~24% of the figure, so every LTV, ARPU and retention
            // number built on it is overstated by roughly a third. Nullable
            // because a file exported before these columns existed still
            // imports — null means "gross only", not "zero".
            $table->decimal('net_amount', 12, 2)->nullable()->after('total_amount');
            $table->decimal('tax_amount', 12, 2)->nullable()->after('net_amount');
            $table->decimal('shipping_amount', 12, 2)->nullable()->after('tax_amount');

            // WooCommerce stores a refund as its own record with a negative
            // total, so a query filtered on orders never sees it and refunded
            // money counts as revenue. Summed onto the order, positive.
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('shipping_amount');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'tax_amount', 'shipping_amount', 'refunded_amount']);
        });
    }
};
