<?php

namespace App\Console\Commands;

use App\Services\ApiUploadQueue;
use Illuminate\Console\Command;

class ReleaseOdooUploads extends Command
{
    protected $signature = 'odoo:release-uploads';

    protected $description = 'Start the next queued upload after the current one finishes';

    public function handle(ApiUploadQueue $queue): int
    {
        while (true) {
            $started = $queue->startWaitingWhenIdle();
            if ($started !== null) {
                $this->info('Started '.$started.'.');

                return self::SUCCESS;
            }
            if ($queue->queued() === []) {
                $this->info('No upload is waiting.');

                return self::SUCCESS;
            }
            sleep(20);
        }
    }
}
