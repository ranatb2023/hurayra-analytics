<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Services\MetricsService;
use App\Support\Clock;
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

    /** Distinct UK calendar years present in the data, for the year/month pickers. */
    private function availableYears(): array
    {
        $driver = DB::connection()->getDriverName();
        $local = Clock::localSql('date_created_gmt', $driver);
        $yearExpr = $driver === 'sqlite' ? "strftime('%Y', {$local})" : "YEAR({$local})";

        $years = Record::query()
            ->whereNotNull('date_created_gmt')
            ->selectRaw("DISTINCT {$yearExpr} as y")
            ->pluck('y')
            ->filter()
            ->map(fn ($y) => (int) $y)
            ->sortDesc()
            ->values()
            ->all();

        return $years !== [] ? $years : [Clock::now()->year];
    }
}
