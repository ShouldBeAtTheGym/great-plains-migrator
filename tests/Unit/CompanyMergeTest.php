<?php

namespace Tests\Unit;

use App\Services\CompanyMerge;
use App\Services\GpConnection;
use Tests\TestCase;

class CompanyMergeTest extends TestCase
{
    public function test_a_shared_customer_keeps_both_numbers_on_one_contact(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $links = $merge->linksFromCustomers([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => '001547', 'name' => 'ACE HARDWARE', 'zip' => '49453'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => '2016437', 'name' => 'ACE HARDWARE', 'zip' => '49453'],
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => '000001', 'name' => 'ONLY BLUEOCO', 'zip' => '49503'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => '2015750', 'name' => 'ISLAND TIME', 'zip' => '33101'],
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => '2015750', 'name' => 'DIVERSDOWN', 'zip' => '49418'],
        ]);

        $method = new \ReflectionProperty(CompanyMerge::class, 'customers');
        $method->setValue($merge, $links);

        $kept = $merge->apply('customers', [
            'id' => 'partner_001547',
            'x_gp_customer_number' => '001547',
            'name' => 'ACE HARDWARE',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);
        $skipped = $merge->apply('customers', [
            'id' => 'partner_2016437',
            'x_gp_customer_number' => '2016437',
            'name' => 'ACE HARDWARE',
        ], ['database' => 'BLUE', 'key' => 'blue', 'company' => 'Geckobrands, LLC', 'role' => 'branch']);
        $only = $merge->apply('customers', [
            'id' => 'partner_000001',
            'x_gp_customer_number' => '000001',
            'name' => 'ONLY BLUEOCO',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);

        $this->assertSame('partner_001547', $kept['id']);
        $this->assertSame('001547 / 2016437', $kept['x_gp_customer_number']);
        $this->assertSame('', $kept['company_id']);
        $this->assertNull($skipped);
        $this->assertSame('Blueoco, LLC', $only['company_id']);
        $this->assertSame('partner_blu2_2015750', $links['blu2|2015750']['external']);
        $this->assertSame('partner_blue_2015750', $links['blue|2015750']['external']);
    }

    public function test_a_sales_order_is_stamped_with_its_company_and_the_shared_customer(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $property = new \ReflectionProperty(CompanyMerge::class, 'customers');
        $property->setValue($merge, $merge->linksFromCustomers([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => '001547', 'name' => 'ACE HARDWARE', 'zip' => '49453'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => '2016437', 'name' => 'ACE HARDWARE', 'zip' => '49453'],
        ]));

        $row = $merge->apply('sales_orders_open_headers', [
            'id' => 'sale_ORD1',
            'partner_id/id' => '__import__.partner_2016437',
        ], ['database' => 'BLUE', 'key' => 'blue', 'company' => 'Geckobrands, LLC', 'role' => 'branch']);

        $this->assertSame('sale_blue_ORD1', $row['id']);
        $this->assertSame('__import__.partner_001547', $row['partner_id/id']);
        $this->assertSame('Geckobrands, LLC', $row['company_id']);
    }

    public function test_a_barcode_used_by_two_products_is_kept_on_the_first_only(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $source = ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent'];
        $other = ['database' => 'BLUE', 'key' => 'blue', 'company' => 'Geckobrands, LLC', 'role' => 'branch'];

        $first = $merge->apply('products', [
            'id' => 'product_KOL-074445',
            'name' => 'FIVE BELOW BACKPACK COOLER ASRT',
            'barcode' => '192234047798',
        ], $source);
        $second = $merge->apply('products', [
            'id' => 'product_FB-COOLER',
            'name' => 'FIVE BELOW BACKPACK COOLER ASRT',
            'barcode' => '192234047798',
        ], $other);
        $blank = $merge->apply('products', [
            'id' => 'product_NOBAR',
            'name' => 'No barcode',
            'barcode' => '',
        ], $source);

        $this->assertSame('192234047798', $first['barcode']);
        $this->assertSame('192234047798-1', $second['barcode']);
        $this->assertSame('', $blank['barcode']);
    }

    public function test_a_price_list_points_at_the_product_that_was_imported(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $property = new \ReflectionProperty(CompanyMerge::class, 'products');
        $property->setValue($merge, $merge->linksFromProducts([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => 'BAR-0190', 'name' => 'BARY3 RED MINI CUPS 2 OZ 20 PACK'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => 'BAR-0190', 'name' => 'BARY3 RED MINI CUPS 2OZ 20 PACK'],
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => '500661', 'name' => 'SHARED CUP'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => '500661', 'name' => 'SHARED CUP'],
        ]));

        $split = $merge->apply('customer_price_lists', [
            'id' => 'pricelist_000214',
            'item_ids/product_tmpl_id/id' => '__import__.product_BAR-0190',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);
        $shared = $merge->apply('customer_price_lists', [
            'id' => 'pricelist_000214',
            'item_ids/product_tmpl_id/id' => '__import__.product_500661',
        ], ['database' => 'BLUE', 'key' => 'blue', 'company' => 'Geckobrands, LLC', 'role' => 'branch']);

        $this->assertSame('__import__.product_blu2_BAR-0190', $split['item_ids/product_tmpl_id/id']);
        $this->assertSame('pricelist_blu2_000214', $split['id']);
        $this->assertSame('__import__.product_500661', $shared['item_ids/product_tmpl_id/id']);

        $assign = $merge->apply('customer_price_assign', [
            'id' => 'partner_000214',
            'property_product_pricelist/id' => '__import__.pricelist_000214',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);
        $this->assertSame('partner_000214', $assign['id']);
        $this->assertSame('__import__.pricelist_blu2_000214', $assign['property_product_pricelist/id']);
    }

    public function test_inventory_uses_the_product_spelling_that_was_imported(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $property = new \ReflectionProperty(CompanyMerge::class, 'products');
        $property->setValue($merge, $merge->linksFromProducts([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => 'BLU-0246', 'name' => 'CUP'],
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => 'KOL-0494', 'name' => 'BLUE CUP'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => 'KOL-0494', 'name' => 'GECKO CUP'],
        ]));
        $source = ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'code_prefix' => 'Blueoco_', 'role' => 'parent'];

        $case = $merge->apply('inventory_on_hand', [
            'id' => 'stock_blu-0246_GRR_110A40-KVAT',
            'product_id/id' => '__import__.product_variant_blu-0246',
            'gp_location' => 'GRR/Stock/110A40-KVAT',
        ], $source);
        $split = $merge->apply('inventory_on_hand', [
            'id' => 'stock_kol-0494_GRR_A',
            'product_id/id' => '__import__.product_variant_kol-0494',
        ], $source);

        $this->assertSame('__import__.product_variant_BLU-0246', $case['product_id/id']);
        $this->assertSame('Blueoco_GRR/Stock/110A40-KVAT', $case['gp_location']);
        $this->assertSame('__import__.product_variant_blu2_KOL-0494', $split['product_id/id']);
    }

    public function test_an_order_line_finds_a_product_whose_item_number_contains_a_space(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $property = new \ReflectionProperty(CompanyMerge::class, 'products');
        $property->setValue($merge, $merge->linksFromProducts([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => 'GNR-GRID CONNECTOR', 'name' => 'GRID CONNECTOR'],
            ['database' => 'blue', 'company' => 'Geckobrands, LLC', 'number' => 'GNR-GRID CONNECTOR', 'name' => 'HEAVY DUTY GRID CONNECTOR BLACK'],
        ]));

        $row = $merge->apply('sales_orders_open_lines', [
            'id' => 'sale_ORD232297',
            'order_line/product_id/id' => '__import__.product_variant_GNR-GRID_CONNECTOR',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);

        $this->assertSame('__import__.product_variant_blu2_GNR-GRID_CONNECTOR', $row['order_line/product_id/id']);
        $this->assertSame('sale_blu2_ORD232297', $row['id']);
    }

    public function test_an_order_line_finds_a_product_whose_item_number_contains_a_dot(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $property = new \ReflectionProperty(CompanyMerge::class, 'products');
        $property->setValue($merge, $merge->linksFromProducts([
            ['database' => 'blu2', 'company' => 'Blueoco, LLC', 'number' => 'CLIP-LABEL2.5', 'name' => 'CLIP STRIP LABEL 2.5 INCHES'],
        ]));

        $dotted = $merge->apply('sales_orders_history_lines', [
            'id' => 'sale_ORD100057',
            'order_line/product_id/id' => '__import__.product_variant_CLIP-LABEL2_5',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'role' => 'parent']);

        $this->assertSame('__import__.product_variant_CLIP-LABEL2.5', $dotted['order_line/product_id/id']);
    }

    public function test_the_same_warehouse_code_stays_on_each_company(): void
    {
        $merge = new CompanyMerge($this->createMock(GpConnection::class));
        $parent = $merge->apply('warehouses', [
            'id' => 'warehouse_GRR',
            'name' => 'Grandville - MI',
            'code' => 'GRR',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'company_xmlid' => 'base.main_company', 'code_prefix' => 'Blueoco_', 'role' => 'parent']);
        $branch = $merge->apply('warehouses', [
            'id' => 'warehouse_GRR',
            'name' => 'Grandville',
            'code' => 'GRR',
        ], ['database' => 'BLUE', 'key' => 'blue', 'company' => 'Geckobrands, LLC', 'company_xmlid' => '__import__.company_geckobrands', 'code_prefix' => 'Gecko_', 'role' => 'branch']);
        $bin = $merge->apply('stock_locations', [
            'id' => 'stock_bin_GRR_STOCK',
            'name' => 'STOCK',
            'location_id' => 'GRR/Stock',
            'barcode' => 'GRR-STOCK',
        ], ['database' => 'BLU2', 'key' => 'blu2', 'company' => 'Blueoco, LLC', 'company_xmlid' => 'base.main_company', 'code_prefix' => 'Blueoco_', 'role' => 'parent']);

        $this->assertSame('warehouse_blu2_GRR', $parent['id']);
        $this->assertSame('Blueoco_GRR', $parent['code']);
        $this->assertSame('base.main_company', $parent['company_id/id']);
        $this->assertArrayNotHasKey('company_id', $parent);
        $this->assertSame('warehouse_blue_GRR', $branch['id']);
        $this->assertSame('Gecko_GRR', $branch['code']);
        $this->assertSame('__import__.company_geckobrands', $branch['company_id/id']);
        $this->assertArrayNotHasKey('company_id', $branch);
        $this->assertSame('Blueoco_GRR/Stock', $bin['location_id']);
        $this->assertSame('Blueoco_GRR-STOCK', $bin['barcode']);
    }
}
