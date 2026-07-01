<?php

namespace App\Http\Controllers;

use App\Jobs\ImportCsvJob;
use App\Models\Import;
use App\Services\CsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class UploadController extends Controller
{
    public function __construct(private readonly CsvImportService $csv)
    {
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

        if (! $check['valid']) {
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
        $import->delete();

        return back()->with('status', 'Import batch deleted.');
    }

    private function headerErrorMessage(array $check): string
    {
        return 'CSV is missing required column(s): '.implode(', ', $check['missing'])
            .'. Required: '.implode(', ', CsvImportService::EXPECTED_COLUMNS).'.';
    }
}
