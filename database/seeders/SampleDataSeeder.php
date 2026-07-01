<?php

namespace Database\Seeders;

use App\Models\Import;
use App\Services\CsvImportService;
use App\Support\SampleCsvGenerator;
use Illuminate\Database\Seeder;

/**
 * Generates the sample CSV (if missing) and imports it through the real
 * CsvImportService, so the seeded data goes through the exact same
 * normalisation/upsert path as a genuine upload.
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/seeders/sample/woocommerce_sample.csv');

        if (! file_exists($path)) {
            app(SampleCsvGenerator::class)->write($path);
        }

        $import = Import::create([
            'original_filename' => 'woocommerce_sample.csv',
            'stored_path' => null,
            'status' => 'processing',
        ]);

        app(CsvImportService::class)->import($import, $path);

        $this->command?->info("Imported sample data: {$import->imported_rows} rows ({$import->skipped_rows} skipped).");
    }
}
