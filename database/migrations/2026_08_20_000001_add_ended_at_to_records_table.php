<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records', function (Blueprint $table) {
            // When a subscription LEFT the active lifecycle (cancelled/expired).
            // `status` alone is a live value with no history, so without this the
            // only answer to "was this active in March?" is "what is it today?" —
            // which silently rewrites past months every time someone cancels.
            $table->dateTime('ended_at')->nullable()->after('date_created_gmt');
            $table->index(['record_type', 'status', 'ended_at'], 'records_type_status_ended_idx');
        });
    }

    public function down(): void
    {
        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex('records_type_status_ended_idx');
            $table->dropColumn('ended_at');
        });
    }
};
