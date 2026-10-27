<?php

namespace App\Services;

use App\Models\Company;
use App\Models\GpCopy;
use App\Models\GpCopyRow;
use RuntimeException;

class OdooImporter
{
    public const BATCH = 40;

    public function __construct(
        private OdooClient $odoo,
        private ExportCatalog $catalog,
        private ConversionPlanner $planner,
    ) {}

    /**
     * Columns the CSV tells the user to import. gp_ columns and "Do not import" stay out.
     *
     * @param  list<string>  $columns
     * @param  array<string, string>  $labels
     * @return list<array{index: int, name: string}>
     */
    public function importableColumns(array $columns, array $labels): array
    {
        $kept = [];
        foreach ($columns as $index => $name) {
            if (! is_string($name) || $name === '' || $name === 'export_part_row') {
                continue;
            }
            if (str_starts_with($name, 'gp_')) {
                continue;
            }
            if (($labels[$name] ?? null) === 'Do not import') {
                continue;
            }
            $kept[] = ['index' => (int) $index, 'name' => $name];
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<string>
     */
    public function errorMessages(array $messages): array
    {
        $errors = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $type = (string) ($message['type'] ?? 'error');
            if ($type !== 'error') {
                continue;
            }
            $text = trim((string) ($message['message'] ?? ''));
            if ($text !== '') {
                $errors[] = $text;
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function nextBatch(Company $company, string $key, bool $restart = false): array
    {
        $copy = GpCopy::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->first();
        if ($copy === null || $copy->status !== 'complete' || (int) $copy->saved_count < 1) {
            return [
                'ok' => false,
                'done' => false,
                'loaded' => 0,
                'failed' => 0,
                'total' => (int) ($copy->saved_count ?? 0),
                'message' => 'This file is not ready. Wait until the Great Plains copy is saved, then load it.',
                'errors' => [],
            ];
        }

        if ($restart) {
            $copy->update([
                'api_loaded' => 0,
                'api_failed' => 0,
                'api_errors' => [],
            ]);
            $copy->refresh();
        }

        $saved = (int) $copy->saved_count;
        $loaded = (int) $copy->api_loaded;
        $failed = (int) $copy->api_failed;
        $storedErrors = is_array($copy->api_errors) ? $copy->api_errors : [];
        if ($loaded >= $saved) {
            return $this->progress(true, $loaded, $failed, $saved, $storedErrors, $this->doneMessage($saved, $failed));
        }

        $export = $this->catalog->find($key);
        $columns = is_array($copy->columns) ? array_values($copy->columns) : [];
        $kept = $this->importableColumns($columns, is_array($export['fields'] ?? null) ? $export['fields'] : []);
        if ($kept === []) {
            return $this->progress(false, $loaded, $failed, $saved, $storedErrors, 'This file has no columns to send to Odoo.', false);
        }

        $model = $this->planner->models($key)[0] ?? null;
        if (! is_string($model) || $model === '') {
            return $this->progress(false, $loaded, $failed, $saved, $storedErrors, 'This file has no Odoo model.', false);
        }

        $rows = GpCopyRow::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->where('row_no', '>', $loaded)
            ->orderBy('row_no')
            ->limit(self::BATCH)
            ->get();
        if ($rows->isEmpty()) {
            $copy->update(['api_loaded' => $saved]);

            return $this->progress(true, $saved, $failed, $saved, $storedErrors, $this->doneMessage($saved, $failed));
        }

        $fields = array_column($kept, 'name');
        $payloads = [];
        foreach ($rows as $row) {
            $values = json_decode((string) $row->payload, true);
            $line = [];
            foreach ($kept as $column) {
                $line[] = is_array($values) ? (string) ($values[$column['index']] ?? '') : '';
            }
            $payloads[] = [
                'row_no' => (int) $row->row_no,
                'record' => (string) $row->record_key,
                'line' => $line,
            ];
        }

        try {
            $errors = $this->loadRows($company, $model, $fields, $payloads);
        } catch (RuntimeException $exception) {
            return $this->progress(false, $loaded, $failed, $saved, $storedErrors, $exception->getMessage(), false);
        }

        if ($errors !== [] && count($payloads) > 1) {
            $payloads = [array_shift($payloads)];
            try {
                $errors = $this->loadRows($company, $model, $fields, $payloads);
            } catch (RuntimeException $exception) {
                return $this->progress(false, $loaded, $failed, $saved, $storedErrors, $exception->getMessage(), false);
            }
        }

        $last = $loaded;
        $batchError = null;
        foreach ($payloads as $payload) {
            $last = $payload['row_no'];
            if ($errors !== []) {
                $failed++;
                $batchError = $payload['record'].': '.$errors[0];
                $storedErrors = $this->rememberError($storedErrors, $payload['record'], $errors[0]);
                $errors = [];
            }
        }

        $copy->update([
            'api_loaded' => $last,
            'api_failed' => $failed,
            'api_errors' => $storedErrors,
        ]);
        $done = $last >= $saved;
        $progress = $this->progress(
            $done,
            $last,
            $failed,
            $saved,
            $storedErrors,
            $done ? $this->doneMessage($saved, $failed) : 'Loaded '.number_format($last).' of '.number_format($saved).'.'
        );
        $progress['batch_error'] = $batchError;

        return $progress;
    }

    /**
     * @param  list<array{record: string, message: string}>  $stored
     * @return list<array{record: string, message: string}>
     */
    private function rememberError(array $stored, string $record, string $message): array
    {
        $stored[] = [
            'record' => $record,
            'message' => $message,
        ];

        return array_slice($stored, -20);
    }

    /**
     * @param  list<array{row_no: int, record: string, line: list<string>}>  $payloads
     * @return list<string>
     */
    private function loadRows(Company $company, string $model, array $fields, array $payloads): array
    {
        try {
            $result = $this->odoo->load($company, $model, $fields, array_column($payloads, 'line'));
        } catch (RuntimeException $exception) {
            if ($this->isTemporary($exception)) {
                throw $exception;
            }

            return [$this->rowFault($exception)];
        }

        return $this->errorsFromResult($result);
    }

    private function isTemporary(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'slow down')
            || str_contains($message, 'Could not reach')
            || str_contains($message, 'returned HTTP')
            || str_contains($message, 'did not return a JSON-RPC');
    }

    private function rowFault(RuntimeException $exception): string
    {
        if (str_contains($exception->getMessage(), 'Pending savepoints')) {
            return 'Odoo could not save this row. A database check failed.';
        }

        return $exception->getMessage();
    }

    /**
     * @return list<string>
     */
    private function errorsFromResult(mixed $result): array
    {
        if (! is_array($result)) {
            return ['Odoo did not accept that batch.'];
        }
        $messages = $result['messages'] ?? null;
        if (! is_array($messages) && array_is_list($result)) {
            $messages = $result[1] ?? [];
        }

        return $this->errorMessages(is_array($messages) ? $messages : []);
    }

    private function doneMessage(int $saved, int $failed): string
    {
        if ($failed === 0) {
            return 'Odoo accepted all '.number_format($saved).' rows. Press Confirm import when you are ready.';
        }

        return 'Loaded '.number_format($saved - $failed).' rows. '.number_format($failed).' could not be loaded.';
    }

    /**
     * @param  list<array{record: string, message: string}>  $errors
     * @return array<string, mixed>
     */
    private function progress(bool $done, int $loaded, int $failed, int $total, array $errors, string $message, bool $ok = true): array
    {
        return [
            'ok' => $ok,
            'done' => $done,
            'loaded' => $loaded,
            'failed' => $failed,
            'total' => $total,
            'message' => $message,
            'errors' => $errors,
        ];
    }
}
