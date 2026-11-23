<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\GpCopyRow;
use App\Services\ApiUploadQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiUploadQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_third_job_waits_when_two_are_already_running(): void
    {
        $path = storage_path('framework/testing/api-upload-queue.json');
        $history = storage_path('framework/testing/api-upload-history.json');
        foreach ([$path, $history] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $started = [];
        $queue = new ApiUploadQueue(
            $path,
            fn () => ['odooexport-price-lists.service', 'odooexport-history-invoice-lines.service'],
            function (string $unit, string $key) use (&$started): void {
                $started[] = [$unit, $key];
            }
        );

        $result = $queue->schedule('customer_interactions');

        $this->assertTrue($result['waiting']);
        $this->assertSame([], $started);
        $this->assertStringContainsString('Only two jobs can run at a time', $result['message']);

        $status = $queue->status();
        $this->assertTrue($status['limited']);
        $this->assertSame('customer_price_lists', $status['current']['key']);
        $this->assertSame('running', $status['current']['state']);
        $waiting = collect($status['jobs'])->firstWhere('key', 'customer_interactions');
        $this->assertSame('waiting', $waiting['state']);
        $this->assertSame($waiting['total'] - $waiting['loaded'], $waiting['remaining']);
        $recorded = collect($status['history'])->firstWhere('key', 'customer_interactions');
        $this->assertSame('waiting', $recorded['state']);
        $this->assertSame('odooexport-api-customer-interactions.service', $recorded['unit']);

        $again = $queue->status();
        $this->assertCount(count($status['history']), $again['history']);
    }

    public function test_part_one_samples_come_from_the_first_30000_saved_rows(): void
    {
        $company = Company::query()->create([
            'name' => 'Blue',
            'intro_open' => false,
        ]);
        foreach ([1 => 'invoice_first', 7500 => 'invoice_middle', 30000 => 'invoice_last', 30001 => 'invoice_part_two'] as $rowNo => $id) {
            GpCopyRow::query()->create([
                'company_id' => $company->id,
                'export_key' => 'sales_invoices_history_headers',
                'row_no' => $rowNo,
                'record_key' => $id,
                'payload' => json_encode([$id, $id]),
            ]);
        }

        $ids = (new ApiUploadQueue)->partOneIds($company, 'sales_invoices_history_headers');

        $this->assertSame(['invoice_first', 'invoice_middle', 'invoice_last'], $ids);
        $this->assertFalse((new ApiUploadQueue)->isLarge(300000));
        $this->assertTrue((new ApiUploadQueue)->isLarge(300001));
    }
}
