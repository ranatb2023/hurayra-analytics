<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small key/value store for cached Klaviyo state (resolved conversion
        // metric id, last sync status/error). The API key / list id / revision
        // live in config (.env), never the DB.
        Schema::create('klaviyo_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klaviyo_settings');
    }
};
