<?php

namespace App\Services;

use App\Models\Company;
use App\Models\GpCopy;
use App\Models\GpCopyRow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GpCopyService
{
    public const BATCH = 200;

    public function __construct(
        private ExportCatalog $catalog,
        private GpConnection $gp,
    ) {}

    public function snapshot(Company $company, string $key): array
    {
        $this->reconcile($company, $key);
        $copy = $this->find($company, $key);

        return $this->present($copy);
    }

    public function fingerprint(array $export): string
    {
        $extra = is_array($export['extra'] ?? null) ? $export['extra'] : [];
        $categories = is_array($export['category_id_map'] ?? null) ? $export['category_id_map'] : [];
        ksort($extra);
        ksort($categories);

        return hash('sha256', json_encode([
            'sql' => (string) ($export['sql'] ?? ''),
            'extra' => $extra,
            'categories' => $categories,
            'partners' => [],
        ]));
    }

    public function ready(Company $company, string $key, array $export): ?GpCopy
    {
        $copy = $this->find($company, $key);
        if (! $copy || $copy->status !== 'complete') {
            return null;
        }
        if ($copy->source_hash !== $this->fingerprint($export)) {
            return null;
        }

        return $copy;
    }

    public function applyLiveLookups(Company $company, array $export): array
    {
        $sql = (string) ($export['sql'] ?? '');
        $needs = ($export['resolve_res_id'] ?? null) === 'res.partner'
            || str_contains($sql, '[[event_user_id]]');
        if (! $needs) {
            return $export;
        }

        $connection = $this->gp->connect($company);
        try {
            return $this->catalog->assignCrmEventUsers($company, $connection, $export);
        } finally {
            if (is_resource($connection)) {
                sqlsrv_close($connection);
            }
        }
    }

    public function forget(Company $company, string $key): void
    {
        $copy = $this->find($company, $key);
        if (! $copy) {
            return;
        }
        if ($this->processAlive((int) $copy->pid)) {
            $this->stopProcess((int) $copy->pid);
        }
        $copy->update([
            'status' => 'pending',
            'record_count' => 0,
            'processed_count' => 0,
            'saved_count' => 0,
            'current_record' => null,
            'columns' => null,
            'source_hash' => null,
            'run_token' => null,
            'pid' => null,
            'error' => null,
            'finished_at' => null,
        ]);
    }

    public function kickoff(Company $company, string $key): void
    {
        if ($this->workerAlive()) {
            return;
        }
        $result = $this->ensure($company, $key);
        if (! $result['run_here']) {
            return;
        }

        app()->terminating(function () use ($company, $key) {
            $this->run($company->id, $key);
        });
    }

    /**
     * @param  list<array{key: string, title: string}>  $steps
     * @return array{steps: list<array<string, mixed>>, active: ?array<string, mixed>, finished: int, total: int, done: bool}
     */
    public function queue(Company $company, array $steps): array
    {
        $items = [];
        $running = null;
        $failed = [];
        $next = null;
        $place = null;
        $finished = 0;
        foreach ($steps as $index => $step) {
            $key = (string) $step['key'];
            $this->reconcile($company, $key);
            $item = $this->present($this->find($company, $key));
            $item['key'] = $key;
            $item['title'] = (string) $step['title'];
            $item['place'] = $index + 1;
            if ($item['status'] === 'complete') {
                $finished++;
            } elseif ($item['status'] === 'running' && $running === null) {
                $running = $item;
            } elseif ($item['status'] === 'failed') {
                $failed[] = $item;
            } elseif ($next === null) {
                $next = $item;
            }
            $items[] = $item;
        }
        $active = $running ?? $next;

        return [
            'steps' => $items,
            'active' => $active,
            'failed' => $failed,
            'finished' => $finished,
            'place' => $active['place'] ?? null,
            'total' => count($items),
            'done' => $items !== [] && $finished === count($items),
        ];
    }

    public function catalogQueue(Company $company): array
    {
        $steps = [];
        foreach ($this->catalog->all() as $export) {
            $steps[] = ['key' => (string) $export['key'], 'title' => (string) $export['title']];
        }

        return $this->queue($company, $steps);
    }

    public function retryFailed(Company $company): void
    {
        GpCopy::query()
            ->where('company_id', $company->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'error' => null,
                'finished_at' => null,
                'pid' => null,
                'updated_at' => now(),
            ]);
    }

    public function kickoffCatalog(Company $company): void
    {
        $keys = [];
        foreach ($this->catalog->all() as $export) {
            $keys[] = (string) $export['key'];
        }
        $this->kickoffNext($company, $keys);
    }

    /**
     * @param  list<string>  $keys
     */
    public function kickoffNext(Company $company, array $keys): void
    {
        if ($this->workerAlive()) {
            return;
        }

        Cache::lock('gp-copy-queue-'.$company->id, 15)->block(8, function () use ($company, $keys) {
            if ($this->workerAlive() || $this->anyRunning($company)) {
                return;
            }
            foreach ($keys as $key) {
                $copy = $this->find($company, $key);
                if ($copy && $copy->status === 'failed') {
                    continue;
                }
                if (! $copy || $copy->status !== 'complete') {
                    $this->kickoff($company, $key);

                    return;
                }
            }
        });
    }

    /**
     * @return array{run_here: bool}
     */
    public function ensure(Company $company, string $key): array
    {
        if (app()->environment('testing')) {
            return ['run_here' => false];
        }

        return Cache::lock('gp-copy-start-'.$company->id.'-'.$key, 15)->block(8, function () use ($company, $key) {
            $copy = GpCopy::query()->firstOrCreate(
                ['company_id' => $company->id, 'export_key' => $key],
                ['status' => 'pending']
            );
            if ($copy->status === 'complete' && $copy->source_hash) {
                return ['run_here' => false];
            }
            if ($copy->status === 'failed') {
                return ['run_here' => false];
            }
            if ($this->stillRunning($copy) || $this->anyRunning($company, $key)) {
                return ['run_here' => false];
            }

            $copy->fill([
                'status' => 'running',
                'record_count' => 0,
                'processed_count' => 0,
                'saved_count' => 0,
                'current_record' => 'Starting',
                'error' => null,
                'source_hash' => null,
                'run_token' => null,
                'finished_at' => null,
                'started_at' => now(),
                'pid' => null,
            ])->save();

            $pid = $this->launch($company, $key);
            if ($pid > 0) {
                $copy->update(['pid' => $pid]);

                return ['run_here' => false];
            }

            return ['run_here' => true];
        });
    }

    public function nextWorkKey(Company $company): ?string
    {
        foreach ($this->catalog->all() as $export) {
            $key = (string) $export['key'];
            $copy = $this->find($company, $key);
            if (! $copy || $copy->status === 'pending') {
                return $key;
            }
            if ($copy->status === 'complete') {
                continue;
            }
            if ($copy->status === 'running') {
                return $this->stillRunning($copy) ? null : $key;
            }
            if ($copy->status === 'failed' && $copy->finished_at && $copy->finished_at->greaterThan(now()->subMinutes(10))) {
                continue;
            }

            return $key;
        }

        return null;
    }

    public function workOnce(Company $company): bool
    {
        $key = $this->nextWorkKey($company);
        if ($key === null) {
            return false;
        }
        $this->run($company->id, $key);

        return true;
    }

    public function workerAlive(): bool
    {
        $pid = (int) trim((string) @file_get_contents($this->workerPidPath()));
        if (! $this->processAlive($pid)) {
            return false;
        }
        $command = @file_get_contents('/proc/'.$pid.'/cmdline');

        return is_string($command) && str_contains($command, 'gp:copy-queue');
    }

    public function refreshCatalog(): void
    {
        $this->catalog->all();
    }

    public function workerPidPath(): string
    {
        return storage_path('app/gp-copy-worker.pid');
    }

    public function run(int $companyId, string $key): void
    {
        if (app()->environment('testing')) {
            return;
        }

        @set_time_limit(0);
        ignore_user_abort(true);
        DB::disableQueryLog();
        $this->refreshCatalog();

        $company = Company::query()->find($companyId);
        if (! $company) {
            return;
        }
        $export = $this->catalog->find($key);
        if ($export === null) {
            return;
        }

        $token = (string) Str::uuid();
        $copy = null;
        $busy = false;
        DB::transaction(function () use ($company, $key, $token, &$copy, &$busy) {
            $copy = GpCopy::query()
                ->where('company_id', $company->id)
                ->where('export_key', $key)
                ->lockForUpdate()
                ->first();
            if (! $copy) {
                $copy = new GpCopy([
                    'company_id' => $company->id,
                    'export_key' => $key,
                ]);
            }
            if ($copy->exists
                && $copy->status === 'running'
                && (int) $copy->pid !== getmypid()
                && $this->processAlive((int) $copy->pid)
                && $copy->run_token) {
                $busy = true;

                return;
            }
            $copy->fill([
                'status' => 'running',
                'processed_count' => 0,
                'saved_count' => 0,
                'current_record' => 'Counting rows in Great Plains',
                'error' => null,
                'run_token' => $token,
                'pid' => getmypid(),
                'started_at' => $copy->started_at ?? now(),
                'finished_at' => null,
            ])->save();
        });
        if ($busy || ! $copy) {
            return;
        }

        $connection = null;
        try {
            if (! $this->owns($copy, $token)) {
                return;
            }
            $this->clearRows($company->id, $key);
            $this->clearRows($company->id, $this->problemKey($key));
            if (! $this->owns($copy, $token)) {
                return;
            }

            $export = $this->catalog->prepare($company, $export);
            $connection = $this->gp->connect($company);
            $export = $this->catalog->assignCrmEventUsers($company, $connection, $export);
            $export = $this->catalog->attachPartnerResIds($company, $connection, $export);
            $hash = $this->fingerprint($export);

            $countRows = $this->gp->rows($connection, $export['count_sql'], 1);
            $total = isset($countRows[0]['c']) ? (int) $countRows[0]['c'] : 0;
            $copy->update([
                'record_count' => $total,
                'source_hash' => $hash,
                'current_record' => $total === 0 ? 'No rows' : 'Reading rows',
            ]);
            if (! $this->owns($copy, $token)) {
                return;
            }

            $statement = $this->catalog->open($connection, $export);
            $headers = array_values(array_filter(
                array_map(
                    static fn ($field) => $field['Name'],
                    sqlsrv_field_metadata($statement) ?: []
                ),
                static fn ($name) => $name !== 'export_part_row'
            ));
            foreach (array_keys($export['extra'] ?? []) as $name) {
                $headers[] = $name;
            }
            $copy->update(['columns' => $headers]);

            $processed = 0;
            $saved = 0;
            $batch = [];
            $problems = [];
            $problemNo = 0;
            $label = $total === 0 ? 'No rows' : 'Reading rows';
            while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
                $processed++;
                $problem = $this->problemFor($row, $export);
                if ($problem !== null) {
                    $problemNo++;
                    $label = $problem[0] !== '' ? $problem[0] : $label;
                    $problems[] = [
                        'company_id' => $company->id,
                        'export_key' => $this->problemKey($key),
                        'row_no' => $problemNo,
                        'record_key' => mb_substr($problem[0], 0, 180),
                        'payload' => json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ];
                    if (count($problems) >= self::BATCH && ! $this->flushProblems($copy, $token, $problems, $processed, $saved, $label)) {
                        return;
                    }
                    if (count($problems) >= self::BATCH) {
                        $problems = [];
                    }

                    continue;
                }
                $line = $this->lineFrom($row, $headers, $export);
                if ($line !== null) {
                    $saved++;
                    $label = $this->recordLabel($row);
                    $batch[] = [
                        'company_id' => $company->id,
                        'export_key' => $key,
                        'row_no' => $saved,
                        'record_key' => mb_substr($label, 0, 180),
                        'payload' => json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ];
                }
                if (count($batch) >= self::BATCH && ! $this->flushBatch($copy, $token, $batch, $processed, $saved, $label)) {
                    return;
                }
                if (count($batch) >= self::BATCH) {
                    $batch = [];
                }
            }
            if ($problems !== [] && ! $this->flushProblems($copy, $token, $problems, $processed, $saved, $label)) {
                return;
            }
            if ($batch !== [] && ! $this->flushBatch($copy, $token, $batch, $processed, $saved, $label)) {
                return;
            }
            sqlsrv_free_stmt($statement);

            if (! $this->owns($copy, $token)) {
                return;
            }
            $copy->update([
                'status' => 'complete',
                'record_count' => max($total, $processed),
                'processed_count' => $processed,
                'saved_count' => $saved,
                'current_record' => $saved === 0 ? 'No rows' : $label,
                'source_hash' => $hash,
                'finished_at' => now(),
                'pid' => null,
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('GP copy failed', [
                'company_id' => $companyId,
                'export_key' => $key,
                'message' => $exception->getMessage(),
            ]);
            if ($copy && $this->owns($copy, $token)) {
                $copy->update([
                    'status' => 'failed',
                    'error' => $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'The copy from Great Plains failed.',
                    'pid' => null,
                    'finished_at' => now(),
                ]);
            }
        } finally {
            if (is_resource($connection)) {
                sqlsrv_close($connection);
            }
        }
    }

    public function headers(Company $company, string $key): array
    {
        $columns = $this->find($company, $key)?->columns;

        return is_array($columns) ? array_values($columns) : [];
    }

    public function sample(Company $company, string $key, int $limit = 3): array
    {
        $headers = $this->headers($company, $key);
        if ($headers === []) {
            return [];
        }

        $rows = GpCopyRow::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->orderBy('row_no')
            ->limit($limit)
            ->get();

        $sample = [];
        foreach ($rows as $row) {
            $values = json_decode((string) $row->payload, true);
            if (! is_array($values)) {
                continue;
            }
            $line = [];
            foreach ($headers as $index => $name) {
                $line[$name] = (string) ($values[$index] ?? '');
            }
            $sample[] = $line;
        }

        return $sample;
    }

    public function stream(Company $company, string $key, ?int $part = null): void
    {
        $this->catalog->all();
        $headers = $this->headers($company, $key);
        $query = GpCopyRow::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->orderBy('row_no');
        if ($part !== null) {
            [$from, $to] = $this->partBounds($part);
            $query->whereBetween('row_no', [$from, $to]);
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);

        $written = 0;
        foreach ($query->cursor() as $row) {
            $line = json_decode((string) $row->payload, true);
            if (! is_array($line)) {
                continue;
            }
            fputcsv($out, $line);
            if (++$written % 500 === 0) {
                fflush($out);
                flush();
            }
        }
        fclose($out);
    }

    /**
     * A CRM row that cannot point at a customer in Odoo.
     *
     * @return list<string>|null
     */
    public function problemFor(array $row, array $export): ?array
    {
        if (! is_array($export['res_id_map'] ?? null)) {
            return null;
        }

        $externalId = preg_replace('/[^A-Za-z0-9._-]/', '_', trim((string) ($row['res_id'] ?? ''))) ?? '';
        if ($externalId !== '' && isset($export['res_id_map'][$externalId])) {
            return null;
        }

        $customer = str_starts_with($externalId, 'partner_') ? substr($externalId, 8) : $externalId;
        $document = trim((string) csv_value($row['id'] ?? '', 'id'));
        $subject = trim((string) csv_value($row['subject'] ?? $row['name'] ?? '', 'subject'));

        return [
            $document,
            (string) csv_value($row['date'] ?? $row['start'] ?? '', 'date'),
            $customer,
            mb_substr($subject, 0, 180),
            $externalId === '' ? 'No customer number.' : 'Customer is not in Odoo.',
        ];
    }

    /**
     * @return array{0: int, 1: list<array<string, string>>}
     */
    public function storedProblems(Company $company, string $key, int $limit = 50): array
    {
        $query = GpCopyRow::query()
            ->where('company_id', $company->id)
            ->where('export_key', $this->problemKey($key));
        $count = (int) (clone $query)->count();
        $rows = [];
        foreach ($query->orderBy('row_no')->limit($limit)->get() as $row) {
            $values = json_decode((string) $row->payload, true);
            if (! is_array($values)) {
                continue;
            }
            $rows[] = [
                'document' => (string) ($values[0] ?? ''),
                'document_date' => (string) ($values[1] ?? ''),
                'customer_number' => (string) ($values[2] ?? ''),
                'subject' => (string) ($values[3] ?? ''),
                'problem' => (string) ($values[4] ?? ''),
            ];
        }

        return [$count, $rows];
    }

    public function streamProblems(Company $company, string $key): void
    {
        $headers = ['document', 'date', 'customer_number', 'subject', 'problem'];
        $query = GpCopyRow::query()
            ->where('company_id', $company->id)
            ->where('export_key', $this->problemKey($key))
            ->orderBy('row_no');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($query->cursor() as $row) {
            $line = json_decode((string) $row->payload, true);
            if (! is_array($line)) {
                continue;
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    public function problemKey(string $key): string
    {
        return $key.'#problems';
    }

    public function lineFrom(array $row, array $headers, array $export): ?array
    {
        $this->catalog->all();
        if (is_array($export['res_id_map'] ?? null)) {
            $externalId = preg_replace('/[^A-Za-z0-9._-]/', '_', trim((string) ($row['res_id'] ?? ''))) ?? '';
            if ($externalId === '' || ! isset($export['res_id_map'][$externalId])) {
                return null;
            }
            $row['res_id'] = $export['res_id_map'][$externalId];
        }

        $line = [];
        foreach ($headers as $name) {
            $value = array_key_exists($name, $export['extra'] ?? [])
                ? (string) csv_value($export['extra'][$name], $name)
                : (string) csv_value($row[$name] ?? null, $name);
            $line[] = $this->catalog->categoryExternalId($export, $name, $value);
        }

        return $line;
    }

    public function recordLabel(array $row): string
    {
        $this->catalog->all();
        foreach (['id', 'name', 'Name'] as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            $value = trim((string) csv_value($row[$column], $column));
            if ($value !== '') {
                return mb_substr($value, 0, 180);
            }
        }
        $first = reset($row);
        $value = trim((string) csv_value($first === false ? '' : $first, 'id'));

        return mb_substr($value, 0, 180);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function partBounds(int $part): array
    {
        $part = max(1, $part);
        $start = ($part - 1) * ExportCatalog::PART_ROWS;

        return [$start + 1, $start + ExportCatalog::PART_ROWS];
    }

    private function flushBatch(GpCopy $copy, string $token, array $batch, int $processed, int $saved, string $label): bool
    {
        if (! $this->owns($copy, $token)) {
            return false;
        }
        GpCopyRow::query()->insert($batch);
        $copy->update([
            'processed_count' => $processed,
            'saved_count' => $saved,
            'current_record' => $label,
        ]);

        return true;
    }

    private function flushProblems(GpCopy $copy, string $token, array $batch, int $processed, int $saved, string $label): bool
    {
        if (! $this->owns($copy, $token)) {
            return false;
        }
        GpCopyRow::query()->insert($batch);
        $copy->update([
            'processed_count' => $processed,
            'saved_count' => $saved,
            'current_record' => $label,
        ]);

        return true;
    }

    private function present(?GpCopy $copy): array
    {
        $total = (int) ($copy->record_count ?? 0);
        $processed = (int) ($copy->processed_count ?? 0);
        $percent = $total > 0 ? (int) min(100, round(($processed / $total) * 100)) : null;

        return [
            'status' => $copy->status ?? 'missing',
            'record_count' => $total,
            'processed_count' => $processed,
            'saved_count' => (int) ($copy->saved_count ?? 0),
            'current_record' => (string) ($copy->current_record ?? ''),
            'error' => (string) ($copy->error ?? ''),
            'percent' => $percent,
        ];
    }

    private function reconcile(Company $company, string $key): void
    {
        $copy = $this->find($company, $key);
        if (! $copy || $copy->status !== 'running') {
            return;
        }
        if ($this->stillRunning($copy)) {
            return;
        }
        $copy->update([
            'status' => 'failed',
            'pid' => null,
            'error' => 'The copy stopped before it finished. Start it again.',
            'finished_at' => now(),
        ]);
    }

    private function anyRunning(Company $company, ?string $except = null): bool
    {
        $rows = GpCopy::query()
            ->where('company_id', $company->id)
            ->where('status', 'running')
            ->when($except !== null, fn ($query) => $query->where('export_key', '!=', $except))
            ->get();
        foreach ($rows as $row) {
            if ($this->stillRunning($row)) {
                return true;
            }
        }

        return false;
    }

    private function stillRunning(GpCopy $copy): bool
    {
        if ($copy->status !== 'running') {
            return false;
        }
        if ($this->processAlive((int) $copy->pid)) {
            return true;
        }
        if ($copy->updated_at !== null && $copy->updated_at->greaterThan(now()->subSeconds(45))) {
            return true;
        }

        return $copy->started_at !== null && $copy->started_at->greaterThan(now()->subSeconds(25));
    }

    private function owns(GpCopy $copy, string $token): bool
    {
        return GpCopy::query()->whereKey($copy->id)->value('run_token') === $token;
    }

    private function find(Company $company, string $key): ?GpCopy
    {
        return GpCopy::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->first();
    }

    private function clearRows(int $companyId, string $key): void
    {
        do {
            $deleted = GpCopyRow::query()
                ->where('company_id', $companyId)
                ->where('export_key', $key)
                ->limit(2000)
                ->delete();
        } while ($deleted > 0);
    }

    private function launch(Company $company, string $key): int
    {
        $php = $this->phpCli();
        $log = storage_path('logs/gp-copy.log');
        $command = sprintf(
            'nohup %s %s gp:copy %d %s >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            $company->id,
            escapeshellarg($key),
            escapeshellarg($log)
        );
        $pid = (int) trim((string) shell_exec($command));

        return $pid > 0 ? $pid : 0;
    }

    private function phpCli(): string
    {
        $candidates = [PHP_BINDIR.'/php', '/usr/bin/php'];
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }

    private function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (is_string($stat) && $stat !== '') {
            return ! preg_match('/\)\s+Z\s/', $stat);
        }
        if (function_exists('posix_kill') && posix_kill($pid, 0)) {
            return true;
        }

        return function_exists('posix_get_last_error') && posix_get_last_error() === 1;
    }

    private function stopProcess(int $pid): void
    {
        if ($pid <= 0 || ! $this->processAlive($pid)) {
            return;
        }
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15);
        }
        shell_exec('kill '.$pid);
    }
}
