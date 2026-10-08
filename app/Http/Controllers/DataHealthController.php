<?php

namespace App\Http\Controllers;

use App\Services\DataHealthService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataHealthController extends Controller
{
    public function __construct(private readonly DataHealthService $health) {}

    public function index(): View
    {
        return view('data-health.index', ['sections' => $this->health->sections()]);
    }

    /** One section as CSV, every row. */
    public function export(string $section): StreamedResponse
    {
        $data = $this->health->section($section);

        abort_if($data === null, 404);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($data['columns']));

            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map(fn ($key) => $row[$key] ?? '', array_keys($data['columns'])));
            }
        }, 'hurayra-data-health-'.$section.'.csv', ['Content-Type' => 'text/csv']);
    }
}
