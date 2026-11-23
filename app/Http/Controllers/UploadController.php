<?php

namespace App\Http\Controllers;

use App\Jobs\ImportCsvJob;
use App\Models\Import;
use App\Services\CancellationSurveyImportService;
use App\Services\CsvImportService;
use App\Services\StatusHistoryImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class UploadController extends Controller
{
    public function __construct(
        private readonly CsvImportService $csv,
        private readonly StatusHistoryImportService $history,
        private readonly CancellationSurveyImportService $surveys,
    ) {
    }

    /** Upload screen + history of past imports. */
    public function index(): View
    {
        return view('uploads.index', [
            'imports' => Import::latest()->get(),
            'expectedColumns' => CsvImportService::EXPECTED_COLUMNS,
        ]);
    }

    /** Accept a CSV, validate the header, store it, and queue the import. */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:204800'],
        ], [
            'file.max' => 'The CSV may not be larger than 200 MB.',
        ]);

        $uploaded = $request->file('file');

        // Validate the header up-front so the user gets an immediate, clear error.
        $header = $this->csv->readHeader($uploaded->getRealPath());
        $check = $this->csv->validateHeader($header);

        // The status history file (05-export-status-history.sql) and the
        // cancellation form (07-export-cancellation-form.sql) are the other
        // accepted formats; the job tells them apart the same way.
        if (! $check['valid'] && ! $this->history->isHistoryHeader($header) && ! $this->surveys->isSurveyHeader($header)) {
            return back()->withErrors([
                'file' => $this->headerErrorMessage($check),
            ]);
        }

        $path = $uploaded->store('imports', 'local');

        $import = Import::create([
            'original_filename' => $uploaded->getClientOriginalName(),
            'stored_path' => $path,
            'status' => 'processing',
        ]);

        ImportCsvJob::dispatch($import->id);

        return redirect()
            ->route('uploads.index')
            ->with('status', "Import queued for “{$import->original_filename}”. Refresh to see row counts as it processes.");
    }

    /** Delete an import batch and all of its records. */
    public function destroy(Import $import): RedirectResponse
    {
        if ($import->stored_path) {
            Storage::disk('local')->delete($import->stored_path);
        }

        // Records cascade via the FK's nullOnDelete? No — explicitly remove them.
        $import->records()->delete();
        DB::table('subscription_status_changes')->where('import_id', $import->id)->delete();
        DB::table('cancellation_surveys')->where('import_id', $import->id)->delete();
        $import->delete();

        return back()->with('status', 'Import batch deleted.');
    }

    private function headerErrorMessage(array $check): string
    {
        return 'CSV is missing required column(s): '.implode(', ', $check['missing'])
            .'. Required: '.implode(', ', CsvImportService::EXPECTED_COLUMNS).'.'
            .' (A status history file needs subscription_id, changed_at and note instead;'
            .' a cancellation form file needs '.implode(', ', CancellationSurveyImportService::REQUIRED_COLUMNS).'.)';
    }
}
