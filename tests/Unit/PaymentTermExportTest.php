<?php

namespace Tests\Unit;

use App\Services\ExportCatalog;
use App\Services\OdooClient;
use Tests\TestCase;

class PaymentTermExportTest extends TestCase
{
    public function test_payment_terms_include_terms_used_only_on_documents(): void
    {
        $catalog = new ExportCatalog($this->createMock(OdooClient::class));
        $export = $catalog->find('payment_terms');

        $this->assertStringContainsString('dbo.SOP30200', $export['sql']);
        $this->assertStringContainsString('dbo.SOP10100', $export['sql']);
        $this->assertStringContainsString('dbo.PM20000', $export['count_sql']);
        $this->assertStringContainsString("CONCAT('payment_term_', UPPER(term))", $export['sql']);

        $orders = $catalog->find('sales_orders_history_headers');
        $bills = $catalog->find('vendor_bills_history');
        $this->assertStringContainsString('UPPER(RTRIM(h.PYMTRMID))', $orders['sql']);
        $this->assertStringContainsString('UPPER(RTRIM(v.PYMTRMID))', $bills['sql']);
    }
}
