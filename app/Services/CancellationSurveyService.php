<?php

namespace App\Services;

use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The cancellation form, read for a period: why people cancelled, how they
 * felt, whether they would come back, and what the retention discount did.
 *
 * Counted by when the form was sent (UK dates), so it follows the period
 * filter like every other panel.
 */
class CancellationSurveyService
{
    /** Statuses that mean the subscription is still on the books today. */
    private const STILL_SUBSCRIBED = ['active', 'on-hold'];

    /**
     * @return array{
     *   surveys:int, offers_without_survey:int,
     *   reasons: array<int, array{reason:?string, label:string, count:int, share:float}>,
     *   satisfaction: array<string,int>, would_return: array<string,int>,
     *   retention: array{accepted:int, declined:int, spent:int, stayed:int, cancelled_anyway:int},
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function forPeriod(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('cancellation_surveys as c')
            ->leftJoin('records as s', function ($j) {
                $j->on('s.id', '=', 'c.subscription_id')->where('s.record_type', 'shop_subscription');
            })
            ->where('c.submitted_at', '>=', Clock::gmt($start))
            ->where('c.submitted_at', '<', Clock::gmt($end))
            // A no-survey offer row must be a subscription: an older export
            // also listed the renewal order that spent the discount.
            ->where(fn ($q) => $q->whereNotNull('c.survey_id')
                ->orWhereNotExists(fn ($o) => $o->from('records as o')
                    ->whereColumn('o.id', 'c.subscription_id')
                    ->where('o.record_type', 'shop_order')
                    ->selectRaw('1')))
            ->orderByDesc('c.submitted_at')
            ->get(['c.*', 's.status as subscription_status', 's.billing_email as subscription_email']);

        $surveys = $rows->whereNotNull('survey_id');

        $reasons = $surveys->groupBy(fn ($r) => $r->reason ?? '')
            ->map(fn ($g, $reason) => [
                'reason' => $reason === '' ? null : $reason,
                'label' => CancellationSurveyImportService::reasonLabel($reason === '' ? null : $reason),
                'count' => $g->count(),
                'share' => $surveys->count() ? round($g->count() / $surveys->count() * 100, 1) : 0.0,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $accepted = $rows->filter(fn ($r) => $this->accepted($r));

        return [
            'surveys' => $surveys->count(),
            'offers_without_survey' => $rows->whereNull('survey_id')->count(),
            'reasons' => $reasons,
            'satisfaction' => $this->counts($surveys, 'satisfaction', ['very-satisfied', 'satisfied', 'neutral', 'dissatisfied', 'very-dissatisfied']),
            'would_return' => $this->counts($surveys, 'would_return', ['yes', 'maybe', 'no']),
            'retention' => [
                'accepted' => $accepted->count(),
                'declined' => $surveys->reject(fn ($r) => $this->accepted($r))->count(),
                'spent' => $accepted->filter(fn ($r) => (int) $r->retention_spent_orders > 0)->count(),
                'stayed' => $accepted->filter(fn ($r) => in_array($r->subscription_status, self::STILL_SUBSCRIBED, true))->count(),
                'cancelled_anyway' => $accepted->filter(fn ($r) => $r->subscription_status !== null
                    && ! in_array($r->subscription_status, self::STILL_SUBSCRIBED, true))->count(),
            ],
            'rows' => $rows->map(fn ($r) => [
                'survey_id' => $r->survey_id === null ? null : (int) $r->survey_id,
                'subscription_id' => $r->subscription_id === null ? null : (int) $r->subscription_id,
                'submitted_at' => $r->submitted_at === null ? null : Clock::toLocal((string) $r->submitted_at)->format('Y-m-d H:i'),
                'customer' => $r->email ?? $r->subscription_email,
                'name' => $r->customer_name,
                'reason' => $r->reason,
                'reason_label' => CancellationSurveyImportService::reasonLabel($r->reason),
                'satisfaction' => $r->satisfaction,
                'would_return' => $r->would_return,
                'feedback' => $r->feedback,
                'subscription_total' => $r->subscription_total === null ? null : (float) $r->subscription_total,
                'retention_accepted' => $this->accepted($r),
                'retention_percent' => $r->retention_percent === null ? null : (float) $r->retention_percent,
                'retention_spent' => (int) $r->retention_spent_orders > 0,
                'subscription_status' => $r->subscription_status,
            ])->values()->all(),
        ];
    }

    /** Accepted the offer: the survey says so, or the subscription recorded it. */
    private function accepted(object $r): bool
    {
        return (bool) $r->retention_used || $r->retention_accepted_at !== null;
    }

    /** @return array<string,int> the known values in order, then anything else */
    private function counts($rows, string $field, array $order): array
    {
        $counts = array_fill_keys($order, 0);
        foreach ($rows as $r) {
            $v = $r->{$field} ?? '';
            $v = $v === '' ? 'no answer' : $v;
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }

        return $counts;
    }
}
