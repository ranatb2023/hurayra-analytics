<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('klaviyo_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('granularity', 16);          // week | month | year | custom
            $table->dateTime('period_start');
            $table->dateTime('period_end');             // exclusive upper bound (matches the rest of the app)

            $table->decimal('delivery_rate', 5, 1)->nullable(); // percentages, 1 dp
            $table->decimal('open_rate', 5, 1)->nullable();
            $table->decimal('click_rate', 5, 1)->nullable();
            $table->decimal('revenue', 14, 2)->default(0);      // GBP
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('subscribers')->default(0);

            $table->string('status', 16)->default('pending');   // pending | ok | failed
            $table->text('error')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['granularity', 'period_start', 'period_end'], 'klaviyo_metrics_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klaviyo_metrics');
    }
};
