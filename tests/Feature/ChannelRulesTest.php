<?php

namespace Tests\Feature;

use App\Models\ChannelRule;
use App\Models\Record;
use App\Services\ChannelResolver;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3-1: raw sources are normalised into channels by editable rules.
 */
class ChannelRulesTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(?string $source, ?string $medium = null, ?string $type = null): string
    {
        return app(ChannelResolver::class)->resolve($source, $medium, $type);
    }

    public function test_the_default_rules_map_the_audit_sources(): void
    {
        foreach (['ig', 'instagram', 'l.instagram.com', 'instagram.com'] as $s) {
            $this->assertSame('Instagram Organic', $this->resolve($s, 'social'), $s);
            $this->assertSame('Instagram Paid', $this->resolve($s, 'paid'), $s);
        }
        foreach (['fb', 'facebook.com', 'm.facebook.com', 'l.facebook.com'] as $s) {
            $this->assertSame('Facebook', $this->resolve($s, 'paid'), $s);
        }
        $this->assertSame('Google Ads', $this->resolve('google', 'cpc'));
        $this->assertSame('Google Organic', $this->resolve('google', 'organic'));
        foreach (['com.google.android.gm', 'mail.google.com', 'Klaviyo'] as $s) {
            $this->assertSame('Email', $this->resolve($s, 'email'), $s);
        }
        $this->assertSame('Other Search', $this->resolve('bing.com'));
        $this->assertSame('Other Search', $this->resolve('duckduckgo.com', 'organic'));
        $this->assertSame('Direct', $this->resolve('(direct)', 'NULL', 'typein'));
    }

    public function test_no_utm_source_falls_back_to_the_woocommerce_source_type(): void
    {
        $this->assertSame('Direct', $this->resolve(null, null, 'typein'));
    }

    public function test_unmapped_sources_are_other(): void
    {
        $this->assertSame('Other', $this->resolve('snapchat.com', 'referral'));
        $this->assertSame('Other', $this->resolve('google', 'referral')); // google needs cpc or organic
        $this->assertSame('Other', $this->resolve(null));
    }

    public function test_acquisition_by_channel_reconciles_and_lists_raw_sources(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/London'));

        $rows = [
            ['ig', 'social'], ['instagram', 'social'], ['l.instagram.com', 'social'],
            ['google', 'cpc'], ['google', 'organic'], ['snapchat.com', 'referral'], ['(direct)', null],
        ];
        foreach ($rows as $i => [$source, $medium]) {
            Record::create([
                'id' => $i + 1, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
                'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 0, 'subscription_id' => null,
                'order_relationship' => 'subscription', 'billing_email' => "s{$i}@example.test",
                'utm_source' => $source, 'utm_medium' => $medium,
            ]);
        }

        $seg = (new MetricsService)->segmentPerformance('channel', 1);
        $byChannel = collect($seg['rows'])->keyBy('segment');

        $this->assertSame(3, $byChannel['Instagram Organic']['subs']);
        $this->assertEqualsCanonicalizing(['ig', 'instagram', 'l.instagram.com'],
            array_column($byChannel['Instagram Organic']['sources'], 'source'));
        $this->assertSame(1, $byChannel['Google Ads']['subs']);
        $this->assertSame(1, $byChannel['Google Organic']['subs']);
        $this->assertSame(1, $byChannel['Other']['subs']);

        // P1-4 still holds: every subscription is in exactly one row.
        $this->assertSame(7, $seg['totals']['subs']);
        $this->assertSame(7, $seg['totals']['still_active']);
    }

    public function test_the_small_segment_rollup_merges_into_other(): void
    {
        foreach (['snapchat.com', 'linktr.ee', 'bing.com'] as $i => $source) {
            Record::create([
                'id' => $i + 1, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
                'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 0, 'subscription_id' => null,
                'order_relationship' => 'subscription', 'billing_email' => "s{$i}@example.test", 'utm_source' => $source,
            ]);
        }

        $rows = (new MetricsService)->segmentPerformance('channel', 5)['rows'];

        // Unmapped (2) and the small "Other Search" (1) form one Other row.
        $this->assertSame(['Other'], array_column($rows, 'segment'));
        $this->assertSame(3, $rows[0]['subs']);
    }

    public function test_unmapped_sources_are_listed(): void
    {
        Record::create([
            'id' => 1, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => 'active',
            'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'utm_source' => 'snapchat.com', 'utm_medium' => 'referral',
        ]);

        $unmapped = (new MetricsService)->unmappedSources();

        $this->assertSame([['source' => 'snapchat.com', 'medium' => 'referral', 'subscriptions' => 1, 'orders' => 0]], $unmapped);
    }

    public function test_rules_are_editable_from_the_admin_screen(): void
    {
        $this->get('/settings/channels')->assertOk()->assertSee('Instagram Organic')->assertSee('Unmapped sources');

        $this->post('/settings/channels', [
            'priority' => 90, 'channel' => 'Snapchat', 'sources' => ' Snapchat.com , snapchat ', 'mediums' => '',
        ])->assertRedirect('/settings/channels');

        $rule = ChannelRule::where('channel', 'Snapchat')->first();
        $this->assertSame('snapchat.com, snapchat', $rule->sources);
        $this->assertNull($rule->mediums);
        $this->assertSame('Snapchat', $this->resolve('snapchat.com', 'referral'));

        $this->put("/settings/channels/{$rule->id}", [
            'priority' => 90, 'channel' => 'Snapchat Ads', 'sources' => 'snapchat.com', 'mediums' => 'paid',
        ])->assertRedirect('/settings/channels');
        $this->assertSame('Snapchat Ads', $this->resolve('snapchat.com', 'paid'));
        $this->assertSame('Other', $this->resolve('snapchat.com', 'referral'));

        $this->delete("/settings/channels/{$rule->id}")->assertRedirect('/settings/channels');
        $this->assertNull(ChannelRule::find($rule->id));
    }

    public function test_the_segments_endpoint_defaults_to_channels(): void
    {
        $this->getJson('/api/metrics/segments')
            ->assertOk()
            ->assertJsonPath('dimension', 'channel')
            ->assertJsonPath('dimensions.0', 'channel');
    }
}
