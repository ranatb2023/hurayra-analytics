<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sources the QA pass found landing in "Other":
 *
 *  - search.brave.com, ecosia.org, uk.search.yahoo.com: search engines.
 *  - tiktok.com: its own channel.
 *  - mollie.com, pay.mollie.nl: the payment page sending the customer back
 *    after checkout. Not where they came from — the real source is lost — so
 *    they get a channel that says exactly that instead of posing as traffic.
 *
 * Only adds what is missing, so rules someone edited on the Channel rules
 * screen are left alone. linktr.ee is not mapped: which profile the link sits
 * on is a business call, made on that screen.
 */
return new class extends Migration
{
    private const ADD = [
        // channel => [priority if the rule is new, sources]
        'Other Search' => [70, ['search.brave.com', 'ecosia.org', 'uk.search.yahoo.com', 'search.yahoo.com']],
        'TikTok' => [65, ['tiktok.com', 'tiktok', 'www.tiktok.com']],
        'Payment redirect (source lost)' => [90, ['mollie.com', 'pay.mollie.nl', 'www.mollie.com']],
    ];

    public function up(): void
    {
        foreach (self::ADD as $channel => [$priority, $sources]) {
            $rule = DB::table('channel_rules')->where('channel', $channel)->first();

            if ($rule === null) {
                DB::table('channel_rules')->insert([
                    'priority' => $priority,
                    'channel' => $channel,
                    'sources' => implode(', ', $sources),
                    'mediums' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $have = array_filter(array_map('trim', explode(',', strtolower((string) $rule->sources))));
            $missing = array_diff($sources, $have);

            if ($missing !== []) {
                DB::table('channel_rules')->where('id', $rule->id)->update([
                    'sources' => implode(', ', array_merge($have, $missing)),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Rules are user data once they exist; nothing to undo automatically.
    }
};
