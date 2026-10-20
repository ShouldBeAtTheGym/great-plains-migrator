<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\GpCopy;
use App\Models\GpCopyRow;
use App\Services\ExportCatalog;
use App\Services\GpCopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GpCopyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_copy_fingerprint_ignores_map_order(): void
    {
        $copies = app(GpCopyService::class);

        $first = $copies->fingerprint([
            'sql' => 'select 1',
            'category_id_map' => ['b' => 'B', 'a' => 'A'],
        ]);
        $second = $copies->fingerprint([
            'sql' => 'select 1',
            'category_id_map' => ['a' => 'A', 'b' => 'B'],
        ]);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $copies->fingerprint(['sql' => 'select 2']));
    }

    public function test_a_csv_line_uses_the_odoo_category_and_skips_a_missing_customer(): void
    {
        $copies = app(GpCopyService::class);
        $line = $copies->lineFrom(
            ['id' => 'product_A', 'categ_id/id' => 'product_categ_999-Gngsho'],
            ['id', 'categ_id/id'],
            ['category_id_map' => ['product_categ_999-gngsho' => 'product_categ_999-GngSho']]
        );

        $this->assertSame(['product_A', 'product_categ_999-GngSho'], $line);
        $this->assertNull($copies->lineFrom(
            ['res_id' => 'partner_1'],
            ['res_id'],
            ['res_id_map' => ['partner_2' => 9]]
        ));
        $this->assertSame(['9'], $copies->lineFrom(
            ['res_id' => 'partner_2'],
            ['res_id'],
            ['res_id_map' => ['partner_2' => 9]]
        ));
    }

    public function test_progress_reports_the_share_processed(): void
    {
        $company = Company::query()->create(['name' => 'Blue']);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'status' => 'running',
            'record_count' => 10,
            'processed_count' => 4,
            'saved_count' => 4,
            'current_record' => 'payment_term_NET',
            'started_at' => now(),
            'pid' => getmypid(),
        ]);

        $snapshot = app(GpCopyService::class)->snapshot($company, 'payment_terms');

        $this->assertSame(10, $snapshot['record_count']);
        $this->assertSame(4, $snapshot['processed_count']);
        $this->assertSame(40, $snapshot['percent']);
        $this->assertSame('payment_term_NET', $snapshot['current_record']);
    }

    public function test_the_background_service_skips_a_saved_file_and_a_fresh_failure(): void
    {
        $company = Company::query()->create(['name' => 'Blue', 'gp_server' => 'gp.example']);
        $keys = array_column(app(\App\Services\ExportCatalog::class)->all(), 'key');
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => $keys[0],
            'status' => 'complete',
            'source_hash' => 'abc',
            'saved_count' => 1,
            'record_count' => 1,
            'processed_count' => 1,
        ]);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => $keys[1],
            'status' => 'failed',
            'finished_at' => now(),
            'error' => 'Great Plains did not answer.',
        ]);

        $this->assertSame($keys[2], app(GpCopyService::class)->nextWorkKey($company));
    }

    public function test_the_queue_moves_to_the_next_file_that_is_not_saved(): void
    {
        $company = Company::query()->create(['name' => 'Blue']);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'status' => 'complete',
            'saved_count' => 3,
            'record_count' => 3,
            'processed_count' => 3,
            'source_hash' => 'abc',
        ]);

        $queue = app(GpCopyService::class)->queue($company, [
            ['key' => 'payment_terms', 'title' => 'Payment terms'],
            ['key' => 'customers', 'title' => 'Customers'],
        ]);

        $this->assertSame('customers', $queue['active']['key']);
        $this->assertSame(2, $queue['place']);
        $this->assertSame('Customers', $queue['active']['title']);
        $this->assertSame(1, $queue['finished']);
        $this->assertSame(2, $queue['total']);
        $this->assertFalse($queue['done']);
    }

    public function test_the_queue_follows_the_file_the_service_is_copying(): void
    {
        $company = Company::query()->create(['name' => 'Blue']);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'customer_price_lists',
            'status' => 'failed',
            'record_count' => 1613535,
            'processed_count' => 14000,
            'saved_count' => 14000,
            'current_record' => 'pricelist_33266',
            'error' => 'The copy stopped before it finished. Start it again.',
            'finished_at' => now(),
        ]);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'sales_orders_history_lines',
            'status' => 'running',
            'record_count' => 3285654,
            'processed_count' => 191400,
            'saved_count' => 191400,
            'current_record' => 'sale_ORD52636',
            'pid' => getmypid(),
            'started_at' => now(),
        ]);

        $queue = app(GpCopyService::class)->queue($company, [
            ['key' => 'customer_price_lists', 'title' => 'Customer price lists'],
            ['key' => 'sales_orders_history_lines', 'title' => 'Historical sales orders — lines'],
        ]);

        $this->assertSame('sales_orders_history_lines', $queue['active']['key']);
        $this->assertSame('running', $queue['active']['status']);
        $this->assertSame(2, $queue['place']);
        $this->assertSame('customer_price_lists', $queue['failed'][0]['key']);
        $this->assertFalse($queue['done']);
    }

    public function test_a_part_download_reads_only_that_slice_from_mysql(): void
    {
        $company = Company::query()->create(['name' => 'Blue']);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'status' => 'complete',
            'columns' => ['id', 'name'],
            'record_count' => 2,
            'processed_count' => 2,
            'saved_count' => 2,
        ]);
        GpCopyRow::query()->insert([
            [
                'company_id' => $company->id,
                'export_key' => 'payment_terms',
                'row_no' => 1,
                'record_key' => 'payment_term_NET',
                'payload' => json_encode(['payment_term_NET', 'NET']),
            ],
            [
                'company_id' => $company->id,
                'export_key' => 'payment_terms',
                'row_no' => ExportCatalog::PART_ROWS + 1,
                'record_key' => 'payment_term_DUE',
                'payload' => json_encode(['payment_term_DUE', 'DUE']),
            ],
        ]);

        ob_start();
        app(GpCopyService::class)->stream($company, 'payment_terms', 2);
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('payment_term_DUE', $csv);
        $this->assertStringNotContainsString('payment_term_NET', $csv);
    }
}
