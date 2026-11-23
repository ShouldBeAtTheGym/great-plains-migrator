<?php

namespace App\Services;

use App\Models\GpCopyRow;
use Throwable;

class BadCopyReport
{
    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? storage_path('app/bad-copy-report.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if (! is_file($this->path())) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path()), true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return array_reverse($rows);
    }

    /**
     * @param  list<mixed>  $messages
     */
    public function record(string $job, int $companyId, string $exportKey, int $from, int $to, array $messages): void
    {
        $added = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $text = trim((string) ($message['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $index = $this->rowIndex($message);
            $rowNo = $index === null ? null : $from + $index;
            $copy = $rowNo === null ? null : $this->lookup($companyId, $exportKey, $rowNo);
            $added[] = [
                'job' => $job,
                'export_key' => $exportKey,
                'row_no' => $rowNo,
                'batch_from' => $from,
                'batch_to' => $to,
                'record' => $copy['id'] ?? '',
                'name' => $copy['name'] ?? '',
                'field' => trim((string) ($message['field'] ?? '')),
                'message' => $text,
                'at' => date('c'),
            ];
        }
        if ($added === []) {
            $added[] = [
                'job' => $job,
                'export_key' => $exportKey,
                'row_no' => null,
                'batch_from' => $from,
                'batch_to' => $to,
                'record' => '',
                'name' => '',
                'field' => '',
                'message' => 'Odoo rejected this batch.',
                'at' => date('c'),
            ];
        }
        $this->append($added);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function rowIndex(array $message): ?int
    {
        if (isset($message['record']) && is_numeric($message['record'])) {
            return (int) $message['record'];
        }
        $rows = $message['rows'] ?? null;
        if (is_array($rows) && isset($rows['from'], $rows['to']) && (int) $rows['from'] === (int) $rows['to']) {
            return (int) $rows['from'];
        }

        return null;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function lookup(int $companyId, string $exportKey, int $rowNo): ?array
    {
        try {
            $row = GpCopyRow::query()
                ->where('company_id', $companyId)
                ->where('export_key', $exportKey)
                ->where('row_no', $rowNo)
                ->first();
        } catch (Throwable) {
            return null;
        }
        if (! $row) {
            return null;
        }
        $line = json_decode((string) $row->payload, true);

        return [
            'id' => (string) ($row->record_key !== '' && $row->record_key !== null ? $row->record_key : (is_array($line) ? ($line[0] ?? '') : '')),
            'name' => is_array($line) ? (string) ($line[1] ?? '') : '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $added
     */
    private function append(array $added): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $existing = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
            if (! is_array($existing)) {
                $existing = [];
            }
            foreach ($added as $row) {
                foreach ($existing as $old) {
                    if (! is_array($old)) {
                        continue;
                    }
                    if (($old['job'] ?? '') === $row['job']
                        && ($old['row_no'] ?? null) === $row['row_no']
                        && ($old['batch_from'] ?? null) === $row['batch_from']
                        && ($old['message'] ?? '') === $row['message']) {
                        continue 2;
                    }
                }
                $existing[] = $row;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
