<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Services\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2: labels and UX consistency.
 */
class LabelsTest extends TestCase
{
    use RefreshDatabase;

    private function sub(int $id, string $status, array $extra = []): void
    {
        Record::create(array_merge([
            'id' => $id, 'import_id' => null, 'record_type' => 'shop_subscription', 'status' => $status,
            'date_created_gmt' => '2026-09-01 10:00:00', 'total_amount' => 0, 'subscription_id' => null,
            'order_relationship' => 'subscription', 'billing_email' => "s{$id}@example.test",
        ], $extra));
    }

    private function october(): array
    {
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Europe/London');

        return (new MetricsService)->compute($start, $start->addMonth());
    }

    // ---- P2-1 ---------------------------------------------------------------

    public function test_awaiting_payment_and_pending_cancellation_are_separate_figures(): void
    {
        $this->sub(1, 'pending');
        $this->sub(2, 'pending');
        $this->sub(3, 'pending-cancel');

        $m = $this->october();

        $this->assertSame(2, $m['awaiting_payment']);
        $this->assertSame(1, $m['pending_cancellation']);
    }

    public function test_both_labels_are_on_the_dashboard(): void
    {
        $this->sub(1, 'active');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Awaiting payment', $html);
        $this->assertStringContainsString('Pending cancellation', $html);
        // Overview (status mix chips) and Subscribers (state chips) both carry them.
        $this->assertGreaterThanOrEqual(2, substr_count($html, "value('awaiting_payment')"));
        $this->assertGreaterThanOrEqual(2, substr_count($html, "value('pending_cancellation')"));
    }

    // ---- P2-2 ---------------------------------------------------------------

    public function test_the_overview_renewals_card_leads_with_the_total_and_value(): void
    {
        $this->sub(1, 'active');

        $html = $this->get('/')->assertOk()->getContent();
        $headline = substr($html, strpos($html, 'data-renewals-headline'), 300);

        $this->assertStringContainsString('renewals.total', $headline);
        $this->assertStringContainsString('money(renewals.value)', $headline);
        $this->assertStringContainsString('at first renewal', $html);
    }
}
