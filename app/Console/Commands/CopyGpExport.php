<?php

namespace App\Console\Commands;

use App\Services\GpCopyService;
use Illuminate\Console\Command;

class CopyGpExport extends Command
{
    protected $signature = 'gp:copy {company : Company id} {key : Export key}';

    protected $description = 'Copy one Great Plains export into MySQL';

    public function handle(GpCopyService $copies): int
    {
        $copies->run((int) $this->argument('company'), (string) $this->argument('key'));

        return self::SUCCESS;
    }
}
