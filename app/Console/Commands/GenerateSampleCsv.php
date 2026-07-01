<?php

namespace App\Console\Commands;

use App\Support\SampleCsvGenerator;
use Illuminate\Console\Command;

class GenerateSampleCsv extends Command
{
    protected $signature = 'sample:csv {path=database/seeders/sample/woocommerce_sample.csv}';

    protected $description = 'Generate a representative sample WooCommerce orders CSV';

    public function handle(SampleCsvGenerator $generator): int
    {
        $path = $this->argument('path');
        $absolute = base_path($path);

        $count = $generator->write($absolute);

        $this->info("Wrote {$count} rows to {$path}");

        return self::SUCCESS;
    }
}
