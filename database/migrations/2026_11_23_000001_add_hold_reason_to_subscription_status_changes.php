<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a subscription went on hold, read from the note WooCommerce wrote:
 *
 *  - payment_due: "Subscription renewal payment due: Status changed from
 *    Active to On hold." — WooCommerce paused it because a renewal was not paid.
 *  - manual: any other "→ On hold" note (a bare status change, a bulk edit,
 *    the customer suspending from My Account) — someone paused it by hand.
 *
 * NULL for every other change, and for rows imported from a file that carried
 * from/to columns instead of the note text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_status_changes', function (Blueprint $table) {
            $table->string('hold_reason', 16)->nullable()->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_status_changes', function (Blueprint $table) {
            $table->dropColumn('hold_reason');
        });
    }
};
