<?php

namespace App\Services;

use App\Models\Company;
use App\Models\GpCopy;
use App\Models\GpCopyRow;
use Closure;

class ApiUploadQueue
{
    public const ROWS = 300000;

    public const PART = 30000;

    public const MAX_JOBS = 2;

    public function __construct(
        private ?string $queuePath = null,
        private ?Closure $activeUnits = null,
        private ?Closure $startUnit = null,
    ) {}

    /** @var list<string>|null */
    private ?array $activeCache = null;

    public function isLarge(int $count): bool
    {
        return $count > self::ROWS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        $orderLine = [
            'model' => 'sale.order',
            'batch' => 200,
            'fields' => [
                'id',
                'order_line/sequence',
                'order_line/product_id/id',
                'order_line/name',
                'order_line/product_uom_qty',
                'order_line/product_uom_id/id',
                'order_line/price_unit',
                'order_line/discount',
            ],
            'indexes' => [0, 8, 9, 10, 11, 12, 13, 14],
        ];

        return [
            'customer_price_lists' => [
                'title' => 'Customer price lists',
                'model' => 'product.pricelist',
                'batch' => 800,
                'unit' => 'odooexport-price-lists.service',
                'progress' => storage_path('app/price-list-api.json'),
                'fields' => [
                    'id', 'name', 'currency_id/id',
                    'item_ids/product_tmpl_id/id', 'item_ids/compute_price', 'item_ids/fixed_price',
                    'item_ids/min_quantity', 'item_ids/date_start', 'item_ids/date_end',
                ],
                'indexes' => [0, 1, 2, 4, 5, 6, 7, 8, 9],
            ],
            'sales_orders_history_headers' => [
                'title' => 'Historical sales orders — headers',
                'model' => 'sale.order',
                'batch' => 300,
                'unit' => 'odooexport-api-sales-orders-history-headers.service',
                'progress' => storage_path('app/history-order-headers.json'),
                'fields' => [
                    'id', 'name', 'partner_id/id', 'client_order_ref', 'date_order', 'commitment_date',
                    'payment_term_id/id', 'currency_id/id', 'user_id/id', 'warehouse_id',
                ],
                'indexes' => [0, 1, 2, 3, 4, 6, 7, 8, 14, 16],
            ],
            'sales_orders_history_lines' => [
                'title' => 'Historical sales orders — lines',
                'model' => 'sale.order',
                'batch' => 200,
                'unit' => 'odooexport-history-lines.service',
                'progress' => storage_path('app/history-order-lines.json'),
            ] + $orderLine,
            'sales_quotes_history_lines' => [
                'title' => 'Historical quotes — lines',
                'model' => 'sale.order',
                'batch' => 200,
                'unit' => 'odooexport-api-sales-quotes-history-lines.service',
                'progress' => storage_path('app/api-upload-sales-quotes-history-lines.json'),
            ] + $orderLine,
            'sales_invoices_history_headers' => [
                'title' => 'Historical sales invoices — headers',
                'model' => 'account.move',
                'batch' => 400,
                'unit' => 'odooexport-history-invoice-headers.service',
                'progress' => storage_path('app/history-invoice-headers.json'),
                'fields' => [
                    'id', 'name', 'partner_id/id', 'move_type', 'payment_reference', 'ref',
                    'invoice_date', 'invoice_date_due', 'invoice_payment_term_id/id', 'currency_id/id',
                    'invoice_user_id/id',
                ],
                'indexes' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 15],
            ],
            'sales_invoices_history_lines' => [
                'title' => 'Historical sales invoices — lines',
                'model' => 'account.move',
                'batch' => 200,
                'unit' => 'odooexport-history-invoice-lines.service',
                'progress' => storage_path('app/history-invoice-lines.json'),
                'fields' => [
                    'id',
                    'invoice_line_ids/sequence',
                    'invoice_line_ids/product_id/id',
                    'invoice_line_ids/name',
                    'invoice_line_ids/quantity',
                    'invoice_line_ids/product_uom_id/id',
                    'invoice_line_ids/price_unit',
                    'invoice_line_ids/discount',
                ],
                'indexes' => [0, 10, 11, 12, 13, 14, 15, 16],
            ],
            'customer_interactions' => [
                'title' => 'Customer interactions',
                'model' => 'mail.message',
                'batch' => 100,
                'unit' => 'odooexport-api-customer-interactions.service',
                'progress' => storage_path('app/api-upload-customer-interactions.json'),
                'fields' => [
                    'id', 'model', 'res_id', 'subject', 'body', 'date', 'author_id/.id', 'message_type', 'subtype_id/id',
                ],
                'indexes' => [0, 1, 2, 3, 4, 5, 6, 7, 8],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(string $key): ?array
    {
        return $this->plans()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function partOneIds(Company $company, string $key): array
    {
        $ids = [];
        foreach ([1, 7500, 15000, 22500, self::PART] as $rowNo) {
            $row = GpCopyRow::query()
                ->where('company_id', $company->id)
                ->where('export_key', $key)
                ->where('row_no', $rowNo)
                ->first();
            if (! $row) {
                continue;
            }
            $line = json_decode((string) $row->payload, true);
            $id = trim((string) (is_array($line) ? ($line[0] ?? '') : ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{queued: bool, waiting: bool, message: string}
     */
    public function schedule(string $key): array
    {
        $plan = $this->plan($key);
        if ($plan === null) {
            return ['queued' => false, 'waiting' => false, 'message' => ''];
        }
        $progress = $this->readProgress($plan);
        $last = $this->total($key, $plan, $progress);
        $next = (int) ($progress['next'] ?? (self::PART + 1));
        if ($last > 0 && $next > $last) {
            return ['queued' => false, 'waiting' => false, 'message' => 'Part 2 onward is already in Odoo.'];
        }
        if ($this->unitIsActive((string) $plan['unit'])) {
            return ['queued' => false, 'waiting' => false, 'message' => 'Part 2 onward is already uploading on the server.'];
        }
        if ($this->runningCount() >= self::MAX_JOBS) {
            $this->remember($key);
            return [
                'queued' => true,
                'waiting' => true,
                'message' => 'Only two jobs can run at a time. This upload will start when a job finishes.',
            ];
        }
        $this->forget($key);
        $this->start((string) $plan['unit'], $key);

        return ['queued' => true, 'waiting' => false, 'message' => 'Part 2 onward is uploading on the server.'];
    }

    public function queue(string $key): bool
    {
        if ($this->plan($key) === null) {
            return false;
        }
        $this->remember($key);

        return true;
    }

    /**
     * @return list<string>
     */
    public function queued(): array
    {
        return $this->waitingKeys();
    }

    public function startWaitingWhenIdle(): ?string
    {
        $lock = fopen(dirname($this->queuePath()).'/api-upload-start.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return null;
        }
        try {
            if ($this->anUploadIsUnfinished()) {
                return null;
            }
            foreach ($this->waitingKeys() as $key) {
                if ($this->plan($key) === null) {
                    $this->forget($key);
                    continue;
                }
                $this->forget($key);
                $this->launch($key);

                return $key;
            }

            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function tick(): void
    {
        $slots = self::MAX_JOBS - $this->runningCount();
        if ($slots <= 0) {
            return;
        }
        foreach ($this->waitingKeys() as $key) {
            if ($slots <= 0) {
                return;
            }
            $plan = $this->plan($key);
            if ($plan === null) {
                $this->forget($key);
                continue;
            }
            $progress = $this->readProgress($plan);
            $last = $this->total($key, $plan, $progress);
            $next = (int) ($progress['next'] ?? (self::PART + 1));
            if ($last > 0 && $next > $last) {
                $this->forget($key);
                continue;
            }
            if ($this->unitIsActive((string) $plan['unit'])) {
                $this->forget($key);
                continue;
            }
            $this->forget($key);
            $this->start((string) $plan['unit'], $key);
            $slots--;
        }
    }

    /**
     * @return array{limited: bool, jobs: list<array<string, mixed>>, current: ?array<string, mixed>, history: list<array<string, mixed>>}
     */
    public function status(): array
    {
        $waitingKeys = $this->waitingKeys();
        $jobs = [];
        $current = null;
        foreach ($this->plans() as $key => $plan) {
            $job = $this->describe($key, $plan, $waitingKeys);
            $this->recordHistory($job);
            if ($this->listed($job)) {
                $jobs[] = $job;
            }
            if ($current === null && in_array($job['state'], ['running', 'retrying'], true)) {
                $current = $job;
            }
        }
        $running = 0;
        foreach ($jobs as $job) {
            if ($job['state'] === 'running' || $job['state'] === 'retrying') {
                $running++;
            }
        }

        return [
            'limited' => $running >= self::MAX_JOBS && $waitingKeys !== [],
            'jobs' => $jobs,
            'current' => $current,
            'history' => $this->readHistory(),
        ];
    }

    /**
     * @param  list<string>  $waitingKeys
     * @return array<string, mixed>
     */
    private function describe(string $key, array $plan, array $waitingKeys): array
    {
        $progress = $this->readProgress($plan);
        $last = $this->total($key, $plan, $progress);
        $next = (int) ($progress['next'] ?? 0);
        $loaded = (int) ($progress['loaded_through'] ?? 0);
        if ($loaded === 0 && $next > 1) {
            $loaded = $next - 1;
        }
        $active = $this->unitIsActive((string) $plan['unit']);
        $waiting = in_array($key, $waitingKeys, true);
        $finished = $last > 0 && $next > $last;
        $problem = trim((string) ($progress['error'] ?? ''));
        $touched = strtotime((string) ($progress['at'] ?? ''));
        $fresh = $touched !== false && $touched >= time() - 240;
        $alive = $active || ($fresh && ! $finished);
        $remaining = max(0, $last - $loaded);
        $state = 'idle';
        if ($finished) {
            $state = 'done';
        } elseif ($alive && $problem !== '') {
            $state = 'retrying';
        } elseif ($alive) {
            $state = 'running';
        } elseif ($waiting) {
            $state = 'waiting';
        } elseif ($next > 0 || $loaded > 0 || $problem !== '') {
            $state = 'stopped';
        }
        $at = $touched !== false ? date('c', $touched) : '';

        return [
            'key' => $key,
            'title' => (string) $plan['title'],
            'unit' => (string) $plan['unit'],
            'state' => $state,
            'total' => $last,
            'loaded' => $loaded,
            'remaining' => $remaining,
            'percent' => $last > 0 ? (int) min(100, round(($loaded / $last) * 100)) : 0,
            'next' => $next,
            'finish' => $state === 'running'
                ? $this->finishAt($remaining, (int) $plan['batch'], (float) ($progress['seconds'] ?? 0))
                : '',
            'note' => $state === 'retrying' ? 'Retrying. '.$problem : ($state === 'stopped' ? $problem : ''),
            'when' => $at === '' ? '' : \Illuminate\Support\Carbon::parse($at)->timezone('America/New_York')->format('M j, g:i A'),
            'at' => $at,
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function listed(array $job): bool
    {
        $alive = in_array($job['state'], ['running', 'retrying'], true);

        return $alive || $job['state'] === 'waiting' || ($job['state'] === 'stopped' && (int) $job['next'] > 0);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function recordHistory(array $job): void
    {
        if (! in_array($job['state'], ['running', 'retrying', 'waiting', 'stopped', 'done'], true)) {
            return;
        }
        $history = $this->readHistory();
        foreach ($history as $row) {
            if (($row['key'] ?? '') !== $job['key']) {
                continue;
            }
            if (($row['state'] ?? '') !== $job['state']) {
                break;
            }
            $loadedDelta = abs((int) $job['loaded'] - (int) ($row['loaded'] ?? 0));
            $elapsed = isset($row['at']) ? time() - (int) strtotime((string) $row['at']) : 9999;
            if ($loadedDelta < 5000 && $elapsed < 120) {
                return;
            }
            break;
        }
        array_unshift($history, [
            'key' => $job['key'],
            'title' => $job['title'],
            'unit' => $job['unit'],
            'state' => $job['state'],
            'loaded' => $job['loaded'],
            'total' => $job['total'],
            'percent' => $job['percent'],
            'note' => $job['note'],
            'at' => $job['at'] !== '' ? $job['at'] : now()->toIso8601String(),
            'when' => $job['when'] !== '' ? $job['when'] : now('America/New_York')->format('M j, g:i A'),
        ]);
        $this->writeHistory(array_slice($history, 0, 40));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readHistory(): array
    {
        $path = $this->historyPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return array_values(array_filter(is_array($decoded) ? $decoded : [], 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function writeHistory(array $history): void
    {
        $path = $this->historyPath();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, (string) json_encode(array_values($history), JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function historyPath(): string
    {
        return dirname($this->queuePath()).'/api-upload-history.json';
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $progress
     */
    private function total(string $key, array $plan, array $progress): int
    {
        $fromProgress = (int) ($progress['last'] ?? 0);
        if ($fromProgress > 0) {
            return $fromProgress;
        }
        $copy = GpCopy::query()->where('export_key', $key)->orderByDesc('saved_count')->first();

        return (int) ($copy->saved_count ?? $copy->record_count ?? 0);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function readProgress(array $plan): array
    {
        $path = (string) ($plan['progress'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function finishAt(int $remaining, int $batch, float $seconds): string
    {
        if ($remaining === 0 || $batch < 1 || $seconds <= 0) {
            return '';
        }
        $eta = (int) round(($remaining / $batch) * $seconds);

        return now('America/New_York')->addSeconds($eta)->format('D, M j, g:i A');
    }

    private function runningCount(): int
    {
        return count($this->activeUnitNames());
    }

    /**
     * @return list<string>
     */
    private function activeUnitNames(): array
    {
        if ($this->activeCache !== null) {
            return $this->activeCache;
        }
        if ($this->activeUnits instanceof Closure) {
            $names = ($this->activeUnits)();

            return $this->activeCache = array_values(array_filter(is_array($names) ? $names : [], 'is_string'));
        }
        $listed = trim((string) shell_exec("systemctl --user list-units 'odooexport-*.service' --state=active --no-legend --plain 2>/dev/null"));
        if ($listed === '') {
            $names = $this->publishedUnits();

            return $this->activeCache = $names !== [] ? $names : $this->probeUnits();
        }
        $names = [];
        foreach (preg_split('/\R/', $listed) ?: [] as $line) {
            $unit = strtok(trim($line), ' ');
            if (is_string($unit) && str_ends_with($unit, '.service') && ! str_contains($unit, 'watchdog') && ! str_contains($unit, 'copy.service')) {
                $names[] = $unit;
            }
        }

        return $this->activeCache = $names;
    }

    /**
     * @return list<string>
     */
    private function publishedUnits(): array
    {
        $path = storage_path('app/transfer-active.json');
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $touched = strtotime((string) ($decoded['at'] ?? ''));
        if ($touched === false || $touched < time() - 180) {
            return [];
        }
        $units = $decoded['units'] ?? [];

        return array_values(array_filter(is_array($units) ? $units : [], 'is_string'));
    }

    /**
     * @return list<string>
     */
    private function probeUnits(): array
    {
        if ($this->activeUnits instanceof Closure) {
            return [];
        }
        $active = [];
        foreach ($this->plans() as $plan) {
            $unit = (string) ($plan['unit'] ?? '');
            if ($unit === '') {
                continue;
            }
            $state = trim((string) shell_exec('systemctl --user is-active '.escapeshellarg($unit).' 2>/dev/null'));
            if ($state === 'active') {
                $active[] = $unit;
            }
        }

        return $active;
    }

    private function unitIsActive(string $unit): bool
    {
        return in_array($unit, $this->activeUnitNames(), true);
    }

    private function anUploadIsUnfinished(): bool
    {
        foreach ($this->plans() as $plan) {
            $progress = $this->readProgress($plan);
            $last = (int) ($progress['last'] ?? 0);
            $next = (int) ($progress['next'] ?? 0);
            if ($last > 0 && $next > $last) {
                continue;
            }
            if ($next > 0 && ($last === 0 || $next <= $last)) {
                return true;
            }
            if ($this->unitIsActive((string) ($plan['unit'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function launch(string $key): void
    {
        if ($this->startUnit instanceof Closure) {
            $plan = $this->plan($key);
            ($this->startUnit)((string) ($plan['unit'] ?? ''), $key);

            return;
        }
        $log = storage_path('logs/'.$key.'-upload.log');
        $pidFile = storage_path('app/'.$key.'.pid');
        $command = 'cd '.escapeshellarg(base_path())
            .' && nohup '.escapeshellarg(PHP_BINARY).' artisan odoo:upload '.escapeshellarg($key)
            .' >> '.escapeshellarg($log).' 2>&1 & echo $!';
        $pid = trim((string) shell_exec($command));
        if ($pid !== '') {
            file_put_contents($pidFile, $pid);
        }
    }

    private function start(string $unit, string $key): void
    {
        if ($this->startUnit instanceof Closure) {
            ($this->startUnit)($unit, $key);

            return;
        }
        if (str_starts_with($unit, 'odooexport-api-')) {
            $name = str_ends_with($unit, '.service') ? substr($unit, 0, -8) : $unit;
            $command = 'systemd-run --user --unit='.escapeshellarg($name)
                .' --description='.escapeshellarg('Upload '.$key.' part 2 onward')
                .' /usr/bin/php /var/www/odooexport/storage/app/load-api-upload.php '.escapeshellarg($key);
            exec($command);

            return;
        }
        exec('systemctl --user reset-failed '.escapeshellarg($unit).' && systemctl --user start '.escapeshellarg($unit));
    }

    private function queuePath(): string
    {
        return $this->queuePath ?? storage_path('app/api-upload-queue.json');
    }

    /**
     * @return list<string>
     */
    private function waitingKeys(): array
    {
        if (! is_file($this->queuePath())) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->queuePath()), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function remember(string $key): void
    {
        $keys = $this->waitingKeys();
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
        }
        $this->writeQueue($keys);
    }

    private function forget(string $key): void
    {
        $this->writeQueue(array_values(array_filter(
            $this->waitingKeys(),
            static fn (string $waiting) => $waiting !== $key
        )));
    }

    /**
     * @param  list<string>  $keys
     */
    private function writeQueue(array $keys): void
    {
        $path = $this->queuePath();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, (string) json_encode(array_values($keys), JSON_PRETTY_PRINT));
    }
}
