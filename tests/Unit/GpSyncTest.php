<?php

namespace Tests\Unit;

use App\Services\GpSync;
use Tests\TestCase;

class GpSyncTest extends TestCase
{
    public function test_a_count_check_includes_the_new_row_total(): void
    {
        $sync = app(GpSync::class);
        $sql = $sync->countQuery('SELECT COUNT(*) AS c FROM dbo.SOP30200 AS h WHERE h.SOPTYPE = 2', 'h', 40);

        $this->assertStringContainsString('MAX(h.DEX_ROW_ID)', $sql);
        $this->assertStringContainsString('h.DEX_ROW_ID > 40', $sql);
        $this->assertStringContainsString('AS new_count', $sql);
    }

    public function test_a_new_download_keeps_rows_added_after_the_watermark(): void
    {
        $sync = app(GpSync::class);

        $this->assertStringContainsString(
            'AND h.DEX_ROW_ID > 80',
            $sync->newRowsSql("SELECT id FROM dbo.SOP10100 AS h WHERE h.SOPTYPE = 2", 'h', 80)
        );
        $this->assertStringContainsString(
            "WHERE DEX_ROW_ID > 3",
            $sync->newRowsSql('SELECT id FROM dbo.POP10100', '', 3)
        );
    }

    public function test_verification_uses_the_record_counts(): void
    {
        $sync = app(GpSync::class);

        $waiting = $sync->assessment(12, 10, 2, 10, 12, false, false);
        $this->assertFalse($waiting['ok']);
        $this->assertFalse($waiting['accept']);
        $this->assertStringContainsString('Import the new file', $waiting['message']);

        $matched = $sync->assessment(12, 10, 2, 12, 12, false, true);
        $this->assertTrue($matched['ok']);
        $this->assertTrue($matched['accept']);
        $this->assertStringContainsString('Odoo has 12', $matched['message']);

        $removed = $sync->assessment(9, 10, 0, 10, 10, false, false);
        $this->assertTrue($removed['ok']);
        $this->assertStringContainsString(GpSync::NOTICE, $removed['message']);
    }

    public function test_line_files_wait_for_the_download_before_they_pass(): void
    {
        $sync = app(GpSync::class);

        $blocked = $sync->assessment(5, 3, 2, null, null, true, false);
        $this->assertFalse($blocked['ok']);

        $ready = $sync->assessment(5, 3, 2, null, null, true, true);
        $this->assertTrue($ready['ok']);
        $this->assertTrue($ready['accept']);
    }

    public function test_sales_history_reads_the_salespad_view_and_a_stable_row_id(): void
    {
        $sync = app(GpSync::class);
        $profile = $sync->profile('sales_orders_history_headers');

        $this->assertSame('h', $profile['dex']);
        $this->assertSame('spv3SalesDocumentHistory', $profile['user']['view']);
        $this->assertSame('spxSalesDocument', $profile['user']['table']);
        $this->assertSame('sale_', $profile['odoo']['prefix']);
        $this->assertSame('sale_ORD1|10', $sync->recordKey([
            'id' => 'sale_ORD1',
            'order_line/sequence' => '10',
        ], ['id', 'order_line/sequence']));
    }
}
