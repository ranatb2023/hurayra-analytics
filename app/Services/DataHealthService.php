<?php

namespace App\Services;

use App\Models\Record;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Data Health page: every row the metrics leave out, or that would mislead
 * them, listed so it can be checked against WooCommerce and fixed at source.
 * Nothing here modifies data.
 *
 * Each section is {key, title, description, count, columns, rows}, so the page
 * and the CSV export render from the same shape.
 */
class DataHealthService
{
    /** On-hold age buckets, as [label, inclusive upper bound in days]. */
    public const HOLD_AGE_BUCKETS = [
        ['0–15 days', 15],
        ['16–30 days', 30],
        ['31–45 days', 45],
        ['45+ days', null],
    ];

    public function __construct(
        private readonly MetricsService $metrics,
        private readonly DataFreshness $freshness,
    ) {}

    /** @return array<string, array<string, mixed>> keyed by section */
    public function sections(): array
    {
        return [
            'invalid_status' => $this->invalidStatuses(),
            'zero_completed' => $this->zeroValueCompletedOrders(),
            'duplicate_active' => $this->duplicateActiveSubscriptions(),
            'unmapped_sources' => $this->unmappedSources(),
            'on_hold_age' => $this->onHoldByAge(),
            'status_counts' => $this->statusCounts(),
        ];
    }

    public function section(string $key): ?array
    {
        return match ($key) {
            'invalid_status' => $this->invalidStatuses(),
            'zero_completed' => $this->zeroValueCompletedOrders(),
            'duplicate_active' => $this->duplicateActiveSubscriptions(),
            'unmapped_sources' => $this->unmappedSources(),
            'on_hold_age' => $this->onHoldByAge(),
            'status_counts' => $this->statusCounts(),
            default => null,
        };
    }

    /** Orders carrying a subscription status, subscriptions carrying an order status, and the like. */
    private function invalidStatuses(): array
    {
        $rows = DB::table('records')
            ->where(fn ($q) => $q
                ->where(fn ($o) => $o->where('record_type', 'shop_order')
                    ->whereNotIn('status', array_merge(Record::ORDER_STATUSES, Record::EXCLUDED_ORDER_STATUSES)))
                ->orWhere(fn ($s) => $s->where('record_type', 'shop_subscription')
                    ->whereNotIn('status', Record::SUBSCRIPTION_STATUSES)))
            ->orderBy('id')
            ->get(['id', 'record_type', 'status', 'date_created_gmt', 'total_amount'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'type' => $r->record_type,
                'status' => 'wc-'.$r->status,
                'created' => $this->ukTime($r->date_created_gmt),
                'total' => round((float) $r->total_amount, 2),
            ])
            ->all();

        return $this->make('invalid_status', 'Invalid status for type',
            'Rows whose status does not belong to their type (e.g. a shop_order with the subscription status wc-active). WP Admin hides them, and every metric excludes them. They are not modified here.',
            ['id' => 'ID', 'type' => 'Type', 'status' => 'Status', 'created' => 'Created (UK)', 'total' => 'Total'],
            $rows);
    }

    /** Completed orders worth nothing: samples, replacements or a broken checkout. */
    private function zeroValueCompletedOrders(): array
    {
        $rows = DB::table('records')
            ->where('record_type', 'shop_order')
            ->where('status', 'completed')
            ->where('total_amount', '<=', 0)
            ->orderByDesc('date_created_gmt')
            ->get(['id', 'order_relationship', 'billing_email', 'subscription_id', 'date_created_gmt', 'coupon_code'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'relationship' => $r->order_relationship,
                'customer' => $r->billing_email,
                'subscription' => $r->subscription_id,
                'coupon' => $r->coupon_code,
                'created' => $this->ukTime($r->date_created_gmt),
            ])
            ->all();

        return $this->make('zero_completed', '£0 completed orders',
            'Completed orders with a total of £0 or less. They are not counted as one-time purchases but do count as completed orders.',
            ['id' => 'Order', 'relationship' => 'Relationship', 'customer' => 'Customer', 'subscription' => 'Subscription', 'coupon' => 'Coupon', 'created' => 'Created (UK)'],
            $rows);
    }

    /** One customer holding two or more active subscriptions to the same product. */
    private function duplicateActiveSubscriptions(): array
    {
        $product = DB::table('records')
            ->where('record_type', 'shop_order')
            ->whereNotNull('subscription_id')
            ->whereNotNull('primary_product')
            ->selectRaw('subscription_id, MAX(primary_product) as product')
            ->groupBy('subscription_id')
            ->pluck('product', 'subscription_id');

        $groups = [];

        foreach (DB::table('records')
            ->where('record_type', 'shop_subscription')
            ->where('status', 'active')
            ->get(['id', 'billing_email', 'customer_id']) as $s) {
            $email = strtolower(trim((string) $s->billing_email));
            $customer = $email !== '' ? $email : ((int) $s->customer_id > 0 ? 'Customer #'.$s->customer_id : null);
            $name = $product[$s->id] ?? null;

            if ($customer === null || $name === null) {
                continue;
            }

            $groups[$customer."\0".$name][] = (int) $s->id;
        }

        $rows = [];
        foreach ($groups as $key => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            [$customer, $name] = explode("\0", $key, 2);
            $rows[] = ['customer' => $customer, 'product' => $name, 'subscriptions' => count($ids), 'ids' => implode(' ', array_map(fn ($i) => '#'.$i, $ids))];
        }

        usort($rows, fn ($a, $z) => $z['subscriptions'] <=> $a['subscriptions']);

        return $this->make('duplicate_active', 'Multiple active subscriptions to one product',
            'Customers with two or more active subscriptions to the same product: often a duplicate checkout, sometimes deliberate (two cats).',
            ['customer' => 'Customer', 'product' => 'Product', 'subscriptions' => 'Active subs', 'ids' => 'Subscription IDs'],
            $rows);
    }

    private function unmappedSources(): array
    {
        return $this->make('unmapped_sources', 'Unmapped traffic sources',
            'Raw utm_source values no channel rule matches; they are reported as Other. Add them on the Channel rules screen.',
            ['source' => 'Source', 'medium' => 'Medium', 'subscriptions' => 'Subscriptions', 'orders' => 'Orders'],
            array_map(fn ($u) => $u + ['medium' => $u['medium'] ?? '(none)'], $this->metrics->unmappedSources()));
    }

    /** On-hold subscriptions by how long they have been on hold. */
    private function onHoldByAge(): array
    {
        $asOf = $this->freshness->asOf();
        $timelines = $this->metrics->statusTimelines();
        $estimated = $this->metrics->holdStarts();
        $reasons = $this->metrics->holdReasons(Clock::gmt($asOf));
        $labels = [
            StatusHistoryImportService::HOLD_MANUAL => 'Paused manually',
            StatusHistoryImportService::HOLD_PAYMENT_DUE => 'Payment pending',
        ];

        $rows = [];
        $buckets = array_fill_keys(array_column(self::HOLD_AGE_BUCKETS, 0), 0);

        foreach (DB::table('records')
            ->where('record_type', 'shop_subscription')
            ->where('status', 'on-hold')
            ->get(['id', 'billing_email']) as $s) {
            // Real hold date from the status history; otherwise the estimate.
            $since = null;
            foreach ($timelines[$s->id] ?? [] as $change) {
                if ($change['to'] === 'on-hold') {
                    $since = $change['at'];
                }
            }
            $since ??= $estimated[$s->id]['held'] ?? null;

            if ($since === null) {
                continue;
            }

            $days = (int) max(0, floor(CarbonImmutable::parse($since, 'UTC')->diffInDays($asOf)));
            $bucket = $this->bucketFor($days);
            $buckets[$bucket]++;

            $rows[] = [
                'id' => (int) $s->id,
                'customer' => $s->billing_email,
                'on_hold_since' => $this->ukTime($since),
                'days' => $days,
                'bucket' => $bucket,
                // "(est.)" when worked out from the orders rather than read from the note.
                'reason' => isset($reasons[$s->id])
                    ? $labels[$reasons[$s->id]['reason']].($reasons[$s->id]['source'] === 'estimate' ? ' (est.)' : '')
                    : '',
                'dated_from' => isset($timelines[$s->id]) ? 'status history' : 'estimate',
            ];
        }

        usort($rows, fn ($a, $z) => $z['days'] <=> $a['days']);

        return $this->make('on_hold_age', 'On-hold subscriptions by age',
            'How long each on-hold subscription has been on hold, as of the data timestamp, and why. Dated from the status history where there is one, otherwise estimated as one billing cycle after the last payment. Reason: "Payment pending" when WooCommerce paused it for a renewal payment due, "Paused manually" when the customer or an admin paused it; estimated from unpaid renewal orders where the history has no note.',
            ['id' => 'Subscription', 'customer' => 'Customer', 'reason' => 'Reason', 'on_hold_since' => 'On hold since (UK)', 'days' => 'Days', 'bucket' => 'Bucket', 'dated_from' => 'Dated from'],
            $rows) + ['buckets' => $buckets];
    }

    /**
     * Rows per type and status: in the source vs counted by the dashboard.
     * The source is the imported data (or WooCommerce itself once the direct
     * sync is configured); the dashboard counts real statuses only.
     */
    private function statusCounts(): array
    {
        $source = $this->freshness->sourceStatusCounts();

        $rows = [];
        foreach ($source['counts'] as $type => $statuses) {
            foreach ($statuses as $status => $n) {
                $counted = match ($type) {
                    'shop_order' => in_array($status, Record::ORDER_STATUSES, true),
                    'shop_subscription' => in_array($status, Record::SUBSCRIPTION_STATUSES, true),
                    default => false,
                };
                $dashboard = $counted ? (int) DB::table('records')->where('record_type', $type)->where('status', $status)->count() : 0;

                $rows[] = [
                    'type' => $type,
                    'status' => 'wc-'.$status,
                    'source' => $n,
                    'dashboard' => $dashboard,
                    'difference' => $dashboard - $n,
                    'note' => $counted ? ($dashboard === $n ? 'match' : 'mismatch') : 'excluded: not a valid '.str_replace('shop_', '', $type).' status',
                ];
            }
        }

        return $this->make('status_counts', 'Dashboard vs source, per status',
            'Row counts per type and status in the source ('.$source['from'].') against what the dashboard counts.',
            ['type' => 'Type', 'status' => 'Status', 'source' => 'Source', 'dashboard' => 'Dashboard', 'difference' => 'Difference', 'note' => 'Note'],
            $rows);
    }

    private function bucketFor(int $days): string
    {
        foreach (self::HOLD_AGE_BUCKETS as [$label, $max]) {
            if ($max === null || $days <= $max) {
                return $label;
            }
        }

        return self::HOLD_AGE_BUCKETS[count(self::HOLD_AGE_BUCKETS) - 1][0];
    }

    private function ukTime(?string $gmt): ?string
    {
        return $gmt === null ? null : Clock::toLocal($gmt)->format('Y-m-d H:i');
    }

    /** @return array<string, mixed> */
    private function make(string $key, string $title, string $description, array $columns, array $rows): array
    {
        return compact('key', 'title', 'description', 'columns', 'rows') + ['count' => count($rows)];
    }
}
