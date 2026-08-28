<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // --- Marketing attribution (WooCommerce Order Attribution) -------
            // Without these, retention can be measured but never traced to the
            // spend that bought it, so acquisition cannot be judged.
            $table->string('attribution_type', 32)->nullable()->after('billing_email');
            $table->string('utm_source', 191)->nullable()->after('attribution_type');
            $table->string('utm_medium', 191)->nullable()->after('utm_source');
            $table->string('utm_campaign', 191)->nullable()->after('utm_medium');
            $table->string('device_type', 32)->nullable()->after('utm_campaign');

            // --- Billing cycle (subscriptions) -------------------------------
            // This store is NOT uniformly monthly: roughly 73% renew monthly,
            // 16% every two months, 8% every six weeks. Any "overdue" or
            // "dormant" rule based on a fixed day count is wrong for a quarter
            // of the book, so the cycle has to travel with the row.
            $table->string('billing_period', 16)->nullable()->after('ended_at');
            $table->unsignedSmallInteger('billing_interval')->nullable()->after('billing_period');

            // The scheduled next renewal for a live subscription. Turns "who
            // might churn" from a guess into a dated list.
            $table->dateTime('next_payment_at')->nullable()->after('billing_interval');

            // --- Commercial context ------------------------------------------
            $table->string('coupon_code', 191)->nullable()->after('total_amount');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('coupon_code');
            $table->string('primary_product', 191)->nullable()->after('discount_amount');

            // Channel roll-ups scan by type + source; the renewal-risk list
            // scans live subscriptions by their scheduled date.
            $table->index(['record_type', 'utm_source'], 'records_type_source_idx');
            $table->index(['record_type', 'status', 'next_payment_at'], 'records_type_status_next_idx');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex('records_type_source_idx');
            $table->dropIndex('records_type_status_next_idx');
            $table->dropColumn([
                'attribution_type', 'utm_source', 'utm_medium', 'utm_campaign', 'device_type',
                'billing_period', 'billing_interval', 'next_payment_at',
                'coupon_code', 'discount_amount', 'primary_product',
            ]);
        });
    }
};
