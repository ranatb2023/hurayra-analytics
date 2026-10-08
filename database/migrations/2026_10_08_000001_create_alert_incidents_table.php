<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per alert incident. The unique key is what makes an alert
        // fire once per incident instead of every hour it stays true.
        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);            // e.g. no_orders
            $table->string('incident_key', 191);   // e.g. order:21025 (the last order before the gap)
            $table->text('message');
            $table->json('channels')->nullable();  // per-channel delivery result
            $table->dateTime('fired_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'incident_key'], 'alert_incidents_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_incidents');
    }
};
