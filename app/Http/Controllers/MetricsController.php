<?php

namespace App\Http\Controllers;

use App\Services\MetricsService;
use App\Support\PeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly PeriodResolver $resolver,
    ) {
    }

    /** AJAX endpoint: all metric cards + comparison for the active filter. */
    public function summary(Request $request): JsonResponse
    {
        $validated = $this->validateFilter($request);

        $period = $this->resolver->resolve($validated);

        return response()->json($this->metrics->summary(
            $period,
            strictNotCompleted: $request->boolean('strict'),
            compare: $request->boolean('compare'),
        ));
    }

    /** AJAX endpoint: trend series for one metric, bucketed by granularity. */
    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => ['required', 'string'],
            'granularity' => ['required', 'in:week,month,year,custom'],
        ]);

        // The trend spans all data unless a custom window is supplied.
        $window = null;
        if ($request->filled('from') && $request->filled('to')) {
            $window = $this->resolver->resolve([
                'granularity' => 'custom',
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ]);
        }

        return response()->json($this->metrics->trend(
            $request->string('metric')->toString(),
            $request->string('granularity')->toString(),
            $window,
        ));
    }

    /** Print-optimised client report (a curated subset, for the active filter). */
    public function clientReport(Request $request): \Illuminate\View\View
    {
        $validated = $this->validateFilter($request);
        $period = $this->resolver->resolve($validated);
        $data = $this->metrics->summary($period, strictNotCompleted: $request->boolean('strict'));

        return view('reports.client', [
            'period' => $period,
            'm' => $data['metrics'],
            'generatedAt' => now(),
        ]);
    }

    /** AJAX endpoint: lifetime top customers by completed spend. */
    public function topCustomers(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 10);

        return response()->json([
            'customers' => $this->metrics->topCustomers(min(50, max(1, $limit))),
        ]);
    }

    /** AJAX endpoint: customers who bought one-off first, then subscribed. */
    public function oneTimeToSubscription(Request $request): JsonResponse
    {
        return response()->json($this->metrics->oneTimeToSubscription(
            conversionsOnly: $request->boolean('conversions_only', true),
            completedOnly: $request->boolean('completed_only'),
            limit: min(5000, max(1, (int) $request->integer('limit', 500))),
        ));
    }

    /** Download the full one-time → subscription list (no row cap). */
    public function oneTimeToSubscriptionExport(Request $request): StreamedResponse
    {
        $data = $this->metrics->oneTimeToSubscription(
            conversionsOnly: $request->boolean('conversions_only', true),
            completedOnly: $request->boolean('completed_only'),
        );

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Customer', 'Customer ID', 'First one-time order', 'Last one-time order',
                'One-time orders', 'One-time spend (completed)', 'Subscribed on', 'Days to convert',
                'Subscription ID', 'Subscription status', 'Subscriptions', 'Subscription orders',
                'Subscription spend (completed)', 'Subscription after one-time',
            ]);

            foreach ($data['customers'] as $c) {
                fputcsv($out, [
                    $c['email'] ?? ('Customer #'.$c['customer_id']),
                    $c['customer_id'],
                    $c['first_one_time_at'],
                    $c['last_one_time_at'],
                    $c['one_time_orders'],
                    $c['one_time_spend'],
                    $c['subscribed_at'],
                    $c['days_to_convert'] ?? '',
                    $c['subscription_id'],
                    $c['subscription_status'],
                    $c['subscriptions'],
                    $c['subscription_orders'],
                    $c['subscription_spend'],
                    $c['is_conversion'] ? 'yes' : 'no',
                ]);
            }
        }, 'hurayra-one-time-to-subscription.csv', ['Content-Type' => 'text/csv']);
    }

    /** AJAX endpoint: cohort retention matrix (sign-up month × renewal months). */
    public function cohorts(Request $request): JsonResponse
    {
        $offset = min(12, max(1, (int) $request->integer('offset', 6)));

        return response()->json($this->metrics->cohortRetention($offset));
    }

    /** AJAX endpoint: small monthly series for the headline sparklines. */
    public function sparklines(Request $request): JsonResponse
    {
        $out = [];
        foreach (['new_subscribers', 'completed', 'total_revenue'] as $metric) {
            $series = $this->metrics->trend($metric, 'month');
            // Keep the last 12 buckets for a compact sparkline.
            $out[$metric] = array_slice($series['values'], -12);
        }

        return response()->json($out);
    }

    /** Download the active filter's metrics as a CSV report. */
    public function export(Request $request): StreamedResponse
    {
        $validated = $this->validateFilter($request);
        $period = $this->resolver->resolve($validated);
        $data = $this->metrics->summary($period, strictNotCompleted: $request->boolean('strict'), compare: $request->boolean('compare'));

        $filename = 'hurayra-metrics-'.str_replace([' ', ':', '–', '/'], '-', $period->label).'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Value', 'Previous', 'Change %']);

            foreach ($data['metrics'] as $key => $value) {
                if (is_array($value)) {
                    continue; // breakdowns exported separately below
                }
                $cmp = $data['comparison'][$key] ?? null;
                fputcsv($out, [
                    $key,
                    $value,
                    $cmp['previous'] ?? '',
                    $cmp['change'] ?? '',
                ]);
            }

            // Not-completed status breakdown.
            fputcsv($out, []);
            fputcsv($out, ['Not-completed status', 'Count']);
            foreach (($data['metrics']['not_completed_breakdown'] ?? []) as $status => $count) {
                fputcsv($out, [$status, $count]);
            }
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilter(Request $request): array
    {
        return $request->validate([
            'granularity' => ['required', 'in:week,month,year,custom'],
            'year' => ['nullable', 'integer', 'min:1970', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'week' => ['nullable', 'integer', 'min:1', 'max:6'],
            'from' => ['nullable', 'date', 'required_if:granularity,custom'],
            'to' => ['nullable', 'date', 'required_if:granularity,custom'],
        ]);
    }
}
