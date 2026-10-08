<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw utm_source values are not channels: `ig`, `instagram` and
     * `l.instagram.com` are one channel, `google` is two (paid and organic),
     * and `Klaviyo` is a medium. These rules map raw values to channels; they
     * are edited on the Channel rules screen. Lowest priority wins.
     */
    private const DEFAULTS = [
        // [priority, channel, sources, mediums (null = any)]
        [10, 'Instagram Paid', 'ig, instagram, l.instagram.com, instagram.com', 'paid, cpc, ppc, paid_social'],
        [20, 'Instagram Organic', 'ig, instagram, l.instagram.com, instagram.com', null],
        [30, 'Facebook', 'fb, facebook, facebook.com, m.facebook.com, l.facebook.com', null],
        [40, 'Google Ads', 'google', 'cpc, ppc, paid'],
        [50, 'Google Organic', 'google', 'organic'],
        [60, 'Email', 'com.google.android.gm, mail.google.com, klaviyo', null],
        [70, 'Other Search', 'bing.com, duckduckgo.com', null],
        [80, 'Direct', '(direct), typein', null],
    ];

    public function up(): void
    {
        Schema::create('channel_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('priority')->default(100);
            $table->string('channel', 64);
            $table->text('sources');               // comma-separated raw utm_source values, case-insensitive
            $table->text('mediums')->nullable();   // comma-separated utm_medium values; null = any medium
            $table->timestamps();
        });

        $now = now();

        DB::table('channel_rules')->insert(array_map(fn (array $r) => [
            'priority' => $r[0],
            'channel' => $r[1],
            'sources' => $r[2],
            'mediums' => $r[3],
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULTS));
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_rules');
    }
};
