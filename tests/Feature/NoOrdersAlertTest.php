<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\Record;
use App\Services\NoOrdersAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P0-4: no new order for N hours during UK trading hours alerts once per
 * incident, never every hour.
 */
class NoOrdersAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'alerts.no_orders.hours' => 12,
            'alerts.no_orders.from_hour' => 8,
            'alerts.no_orders.until_hour' => 23,
            'alerts.mail_to' => 'ops@example.test',
            'alerts.slack_webhook' => null,
        ]);

        Mail::fake();
    }

    private function order(int $id, string $londonTime): void
    {
        Record::create([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_order', 'status' => 'completed',
            'date_created_gmt' => CarbonImmutable::parse($londonTime, 'Europe/London')->utc()->toDateTimeString(),
            'total_amount' => 10, 'subscription_id' => null, 'order_relationship' => 'one_time', 'billing_email' => null,
        ]);
    }

    /** Run the hourly check with the clock at a UK wall time. */
    private function checkAt(string $londonTime): string
    {
        $this->travelTo(CarbonImmutable::parse($londonTime, 'Europe/London'));

        return app(NoOrdersAlert::class)->check();
    }

    public function test_it_fires_once_and_suppresses_repeats_until_a_new_order_arrives(): void
    {
        $this->order(1, '2026-10-08 06:00:00');

        $this->assertSame('ok', $this->checkAt('2026-10-08 17:00:00'));          // 11h: fine
        $this->assertSame('fired', $this->checkAt('2026-10-08 18:00:00'));       // 12h: alert
        $this->assertSame('suppressed', $this->checkAt('2026-10-08 19:00:00'));  // same incident
        $this->assertSame('suppressed', $this->checkAt('2026-10-08 22:00:00'));
        Mail::assertSentCount(1);

        // A new order arrives: the incident closes.
        $this->order(2, '2026-10-08 22:30:00');
        $this->assertSame('ok', $this->checkAt('2026-10-08 22:45:00'));
        $this->assertNotNull(DB::table('alert_incidents')->value('resolved_at'));

        // ...and the next silence is a new incident.
        $this->assertSame('fired', $this->checkAt('2026-10-09 11:00:00'));       // 12.5h after #2
        $this->assertSame('suppressed', $this->checkAt('2026-10-09 12:00:00'));
        Mail::assertSentCount(2);

        $this->assertSame(['order:1', 'order:2'], DB::table('alert_incidents')->orderBy('id')->pluck('incident_key')->all());
    }

    public function test_it_stays_quiet_outside_uk_trading_hours(): void
    {
        $this->order(1, '2026-10-07 06:00:00');

        $this->assertSame('outside_hours', $this->checkAt('2026-10-08 03:00:00'));
        $this->assertSame('outside_hours', $this->checkAt('2026-10-08 23:00:00'));
        $this->assertSame('outside_hours', $this->checkAt('2026-10-08 07:59:00'));
        Mail::assertNothingSent();

        $this->assertSame('fired', $this->checkAt('2026-10-08 08:00:00'));
    }

    public function test_the_email_names_the_last_order(): void
    {
        $this->order(21025, '2026-10-01 00:20:00');

        $this->checkAt('2026-10-08 09:00:00');

        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->hasTo('ops@example.test')
            && str_contains($m->body, '#21025')
            && str_contains($m->body, '1 Oct 2026 00:20'));
    }

    public function test_slack_is_used_when_a_webhook_is_configured(): void
    {
        config(['alerts.slack_webhook' => 'https://hooks.slack.test/services/T/B/X']);
        Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
        $this->order(1, '2026-10-07 06:00:00');

        $this->checkAt('2026-10-08 09:00:00');

        Http::assertSentCount(1);
        $channels = json_decode(DB::table('alert_incidents')->value('channels'), true);
        $this->assertSame(['mail' => 'sent', 'slack' => 'sent'], $channels);
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['alerts.no_orders.hours' => 3]);
        $this->order(1, '2026-10-08 09:00:00');

        $this->assertSame('fired', $this->checkAt('2026-10-08 12:00:00'));
    }

    public function test_the_command_runs_the_check(): void
    {
        $this->order(1, '2026-10-07 06:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'Europe/London'));

        $this->artisan('alerts:no-orders')->expectsOutput('No-orders check: fired')->assertSuccessful();
    }
}
