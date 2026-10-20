<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\GpCopyService;
use Illuminate\Console\Command;

class CopyGpQueue extends Command
{
    protected $signature = 'gp:copy-queue';

    protected $description = 'Keep copying Great Plains files into MySQL after the website is closed';

    private bool $stop = false;

    private int $catalogMtime = 0;

    public function handle(GpCopyService $copies): int
    {
        $this->listenForStop();
        $this->catalogMtime = $this->exportMtime();
        file_put_contents($copies->workerPidPath(), (string) getmypid());

        try {
            while (! $this->stop) {
                if ($this->exportMtime() > $this->catalogMtime) {
                    $this->info('Export SQL changed. The copy service is starting again with the new file.');
                    if (function_exists('pcntl_exec')) {
                        pcntl_exec(PHP_BINARY, [base_path('artisan'), 'gp:copy-queue']);
                    }

                    return self::SUCCESS;
                }
                $worked = false;
                $companies = Company::query()->whereNotNull('gp_server')->where('gp_server', '!=', '')->get();
                foreach ($companies as $company) {
                    if ($this->stop) {
                        break;
                    }
                    $copies->refreshCatalog();
                    $key = $copies->nextWorkKey($company);
                    if ($key === null) {
                        continue;
                    }
                    $this->info('Copying '.$key.' for '.$company->name);
                    $copies->workOnce($company);
                    $worked = true;
                }
                if (! $worked && ! $this->stop) {
                    sleep(5);
                }
            }
        } finally {
            $path = $copies->workerPidPath();
            if (is_file($path) && (int) trim((string) file_get_contents($path)) === getmypid()) {
                unlink($path);
            }
        }

        return self::SUCCESS;
    }

    private function exportMtime(): int
    {
        clearstatcache(true, base_path('legacy/includes/exports.php'));

        return (int) (@filemtime(base_path('legacy/includes/exports.php')) ?: 0);
    }

    private function listenForStop(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
