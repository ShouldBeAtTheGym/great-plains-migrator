<?php

namespace Tests\Unit;

use App\Services\ExportCatalog;
use App\Services\OdooClient;
use Tests\TestCase;

class SalesProblemOrderTest extends TestCase
{
    public function test_open_sales_orders_leave_out_problem_documents_and_offer_a_report(): void
    {
        $catalog = new ExportCatalog($this->createMock(OdooClient::class));
        $headers = $catalog->find('sales_orders_open_headers');
        $lines = $catalog->find('sales_orders_open_lines');

        foreach ([$headers, $lines] as $export) {
            $this->assertStringContainsString('NOT (NULLIF(RTRIM(h.CUSTNMBR)', $export['sql']);
            $this->assertStringContainsString('NOT (NULLIF(RTRIM(h.CUSTNMBR)', $export['count_sql']);
            $this->assertStringContainsString('No customer number.', $export['problem_sql']);
            $this->assertStringNotContainsString('spvItemMaster', $export['problem_sql']);
            $this->assertSame('odoo18_sales_orders_open_problems.csv', $export['problem_file']);
        }

        $this->assertStringNotContainsString('__import__.partner_', $headers['problem_sql']);
        $this->assertSame(1, $catalog->partCount(30000));
        $this->assertSame(16, $catalog->partCount(453478));

        $part = $catalog->partSql($lines['sql'], 2);
        $this->assertStringContainsString('export_part_row > 30000', $part);
        $this->assertStringContainsString('export_part_row <= 60000', $part);
        $this->assertStringContainsString('[order_line/sequence]', $part);
        $this->assertStringContainsString("Item_Type) <> 'Kit'", $lines['sql']);
        $this->assertStringContainsString('product_item.Item_Number IS NULL', $lines['sql']);
        $this->assertStringNotContainsString('gp_warehouse', $headers['sql']);
    }

    public function test_sales_order_headers_use_the_odoo_warehouse_name(): void
    {
        $odoo = $this->createMock(OdooClient::class);
        $odoo->method('execute')->willReturnCallback(function ($company, string $model) {
            if ($model === 'res.company') {
                return [['id' => 2, 'name' => 'Geckobrands, LLC']];
            }
            if ($model === 'stock.warehouse') {
                return [['id' => 1, 'name' => 'Geckobrands, LLC', 'lot_stock_id' => [5, 'GRR/Stock']]];
            }

            return [];
        });

        $company = new \App\Models\Company(['gp_company_name' => 'Geckobrands']);
        $export = (new ExportCatalog($odoo))->prepare($company, [
            'key' => 'sales_orders_open_headers',
            'fields' => [],
        ]);

        $this->assertSame('Geckobrands, LLC', $export['extra']['warehouse_id']);
        $this->assertSame('Warehouse', $export['fields']['warehouse_id']);
    }
}
