<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Services\MetricsService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly MetricsService $metrics) {}

    public function index(): View
    {
        return view('dashboard.index', [
            'hasData' => Record::query()->exists(),
            'trendMetrics' => collect($this->metrics->trendMetrics())
                ->map(fn ($spec, $key) => ['key' => $key, 'label' => $spec['label']])
                ->values(),
            'segmentDimensions' => MetricsService::SEGMENT_DIMENSIONS,
            'years' => $this->availableYears(),
        ]);
    }

    /** Distinct years present in the data, for the year/month pickers. */
    private function availableYears(): array
    {
        $yearExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y', date_created_gmt)"
            : 'YEAR(date_created_gmt)';

        $years = Record::query()
            ->whereNotNull('date_created_gmt')
            ->selectRaw("DISTINCT {$yearExpr} as y")
            ->pluck('y')
            ->filter()
            ->map(fn ($y) => (int) $y)
            ->sortDesc()
            ->values()
            ->all();

        return $years !== [] ? $years : [(int) date('Y')];
    }
}
