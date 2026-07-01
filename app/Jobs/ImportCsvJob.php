<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800; // large files

    public function __construct(public int $importId)
    {
    }

    public function handle(CsvImportService $service): void
    {
        $import = Import::findOrFail($this->importId);

        $absolutePath = Storage::disk('local')->path($import->stored_path);

        $service->import($import, $absolutePath);
    }

    public function failed(Throwable $e): void
    {
        Import::where('id', $this->importId)->update([
            'status' => 'failed',
            'error_log' => ['exception' => $e->getMessage()],
        ]);
    }
}
