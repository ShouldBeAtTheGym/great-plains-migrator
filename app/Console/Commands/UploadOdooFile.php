<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\GpCopy;
use App\Models\GpCopyRow;
use App\Services\ApiUploadQueue;
use App\Services\BadCopyReport;
use App\Services\OdooClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class UploadOdooFile extends Command
{
    protected $signature = 'odoo:upload {key : Export key} {--once : Send one batch and stop}';

    protected $description = 'Upload a saved Great Plains file to Odoo, starting after part 1';

    public function handle(ApiUploadQueue $queue, OdooClient $odoo, BadCopyReport $report): int
    {
        $key = (string) $this->argument('key');
        $plan = $queue->plan($key);
        if ($plan === null) {
            $this->error('That file is not set up for a server upload.');

            return self::FAILURE;
        }

        $company = Company::current();
        $copy = GpCopy::query()
            ->where('company_id', $company->id)
            ->where('export_key', $key)
            ->where('status', 'complete')
            ->first();
        $total = (int) ($copy->saved_count ?? 0);
        if ($total < 1) {
            $this->error('The Great Plains copy is not ready.');

            return self::FAILURE;
        }

        $progress = $this->readProgress((string) $plan['progress']);
        $next = (int) ($progress['next'] ?? (ApiUploadQueue::PART + 1));
        if ($next < 1) {
            $next = 1;
        }
        $companies = $odoo->execute($company, 'res.company', 'search', [[]]);
        $context = [
            'allowed_company_ids' => array_values(array_map('intval', is_array($companies) ? $companies : [])),
        ];
        $fields = array_values($plan['fields']);
        $indexes = array_values($plan['indexes']);
        $productColumn = null;
        foreach ($fields as $position => $field) {
            if ($field === 'order_line/product_id/id' || $field === 'invoice_line_ids/product_id/id' || $field === 'product_id/id') {
                $productColumn = $position;
            }
        }
        $knownProducts = $productColumn === null
            ? []
            : $odoo->externalIdNameSet($company, 'product.product', 'product_variant_');
        $batch = max(1, (int) $plan['batch']);
        $this->writeProgress((string) $plan['progress'], $next, $total, '');

        while ($next <= $total) {
            $started = microtime(true);
            $rows = GpCopyRow::query()
                ->where('company_id', $company->id)
                ->where('export_key', $key)
                ->where('row_no', '>=', $next)
                ->orderBy('row_no')
                ->limit($batch)
                ->get();
            if ($rows->isEmpty()) {
                $this->writeProgress((string) $plan['progress'], $total + 1, $total, '');
                $this->info('Upload finished.');

                return self::SUCCESS;
            }

            $payload = [];
            $relinked = 0;
            $blanked = 0;
            foreach ($rows as $row) {
                $values = json_decode((string) $row->payload, true);
                $line = [];
                foreach ($indexes as $index) {
                    $line[] = is_array($values) ? (string) ($values[$index] ?? '') : '';
                }
                if ($productColumn !== null) {
                    $resolved = $odoo->resolveProductVariant($line[$productColumn], $knownProducts);
                    if ($resolved !== $line[$productColumn]) {
                        $resolved === '' ? $blanked++ : $relinked++;
                    }
                    $line[$productColumn] = $resolved;
                }
                $payload[] = $line;
            }
            $from = (int) $rows->first()->row_no;
            $to = (int) $rows->last()->row_no;

            try {
                $result = $odoo->load($company, (string) $plan['model'], $fields, $payload, $context);
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
                $this->writeProgress((string) $plan['progress'], $next, $total, $message);
                if ($this->canRetry($message)) {
                    $this->warn($message);
                    sleep(20);
                    continue;
                }
                $this->error($message);

                return self::FAILURE;
            }

            $messages = is_array($result) && is_array($result['messages'] ?? null) ? $result['messages'] : [];
            $errors = array_values(array_filter($messages, static fn ($message) => is_array($message) && ($message['type'] ?? 'error') === 'error'));
            if ($errors !== []) {
                $report->record($key, $company->id, $key, $from, $to, $errors);
                $text = trim((string) ($errors[0]['message'] ?? 'Odoo rejected this batch.'));
                $this->writeProgress((string) $plan['progress'], $next, $total, $text);
                $this->error($text);

                return self::FAILURE;
            }

            $next = $to + 1;
            $seconds = round(microtime(true) - $started, 2);
            $this->writeProgress((string) $plan['progress'], $next, $total, '', $seconds);
            $note = '';
            if ($relinked > 0) {
                $note .= ' Relinked '.$relinked.' products to the shared item.';
            }
            if ($blanked > 0) {
                $note .= ' Left '.$blanked.' products blank.';
            }
            $this->info(number_format($to).' of '.number_format($total).$note);
            if ($this->option('once')) {
                return self::SUCCESS;
            }
        }

        $this->writeProgress((string) $plan['progress'], $total + 1, $total, '');
        $this->info('Upload finished.');
        $started = $queue->startWaitingWhenIdle();
        if ($started !== null) {
            $this->info('Started '.$started.'.');
        }

        return self::SUCCESS;
    }

    private function canRetry(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'slow down')
            || str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '502')
            || str_contains($message, '503')
            || str_contains($message, 'could not reach')
            || str_contains($message, 'timed out');
    }

    /**
     * @return array<string, mixed>
     */
    private function readProgress(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return void
     */
    private function writeProgress(string $path, int $next, int $total, string $error, float $seconds = 0): void
    {
        $loaded = max(0, $next - 1);
        $payload = [
            'next' => $next,
            'loaded_through' => $loaded,
            'last' => $total,
            'error' => $error,
            'at' => date('c'),
            'seconds' => $seconds,
        ];
        file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
