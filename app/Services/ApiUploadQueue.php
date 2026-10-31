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
     * @return array{limited: bool, jobs: list<array<string, mixed>>}
     */
    public function status(): array
    {
        $jobs = [];
        foreach ($this->plans() as $key => $plan) {
            $progress = $this->readProgress($plan);
            $last = $this->total($key, $plan, $progress);
            $next = (int) ($progress['next'] ?? 0);
            $loaded = (int) ($progress['loaded_through'] ?? 0);
            if ($loaded === 0 && $next > 1) {
                $loaded = $next - 1;
            }
            $active = $this->unitIsActive((string) $plan['unit']);
            $waiting = in_array($key, $this->waitingKeys(), true);
            $finished = $last > 0 && $next > $last;
            if (! $active && ! $waiting && ($finished || $next === 0)) {
                continue;
            }
            $remaining = max(0, $last - $loaded);
            $jobs[] = [
                'key' => $key,
                'title' => (string) $plan['title'],
                'state' => $active ? 'running' : ($waiting ? 'waiting' : ($finished ? 'done' : 'stopped')),
                'total' => $last,
                'loaded' => $loaded,
                'remaining' => $remaining,
                'finish' => $this->finishAt($remaining, (int) $plan['batch'], (float) ($progress['seconds'] ?? 0)),
            ];
        }
        $running = 0;
        foreach ($jobs as $job) {
            if ($job['state'] === 'running') {
                $running++;
            }
        }

        return [
            'limited' => $running >= self::MAX_JOBS && $this->waitingKeys() !== [],
            'jobs' => $jobs,
        ];
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
            return $this->activeCache = [];
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

    private function unitIsActive(string $unit): bool
    {
        return in_array($unit, $this->activeUnitNames(), true);
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
