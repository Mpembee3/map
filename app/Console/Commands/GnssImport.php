<?php

namespace App\Console\Commands;

use App\Services\GnssLogImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('gnss:import {path? : A CSV log to import; defaults to every CSV in the data folder}')]
#[Description('Import GNSS CSV logs for display on the flight map')]
class GnssImport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GnssLogImporter $importer): int
    {
        $path = $this->argument('path');
        $paths = $path === null ? (glob(base_path('data/*.csv')) ?: []) : [$path];

        if ($paths === []) {
            $this->components->error('No CSV logs found in the data folder.');

            return self::FAILURE;
        }

        foreach ($paths as $logPath) {
            try {
                $summary = $importer->import($logPath)->summary();
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                '%s: %d samples, %d positions, %d without a fix, %d suspect.',
                basename($logPath),
                $summary['samples'],
                $summary['positions'],
                $summary['withoutFix'],
                $summary['suspect'],
            ));
        }

        return self::SUCCESS;
    }
}
