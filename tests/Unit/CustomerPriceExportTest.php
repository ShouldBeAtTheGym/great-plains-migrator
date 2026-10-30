<?php

namespace Tests\Unit;

use App\Services\ExportCatalog;
use App\Services\OdooClient;
use Tests\TestCase;

class CustomerPriceExportTest extends TestCase
{
    public function test_customer_price_files_follow_products_and_read_salespad(): void
    {
        $catalog = new ExportCatalog($this->createMock(OdooClient::class));
        $keys = array_column($catalog->all(), 'key');
        $productAt = array_search('products', $keys, true);
        $priceAt = array_search('customer_price_lists', $keys, true);
        $assignAt = array_search('customer_price_assign', $keys, true);

        $this->assertNotFalse($priceAt);
        $this->assertNotFalse($assignAt);
        $this->assertGreaterThan($productAt, $priceAt);
        $this->assertGreaterThan($priceAt, $assignAt);

        $lists = $catalog->find('customer_price_lists');
        $this->assertSame('Products', $lists['group']);
        $this->assertSame('Products', $catalog->find('customer_price_assign')['group']);
        $this->assertStringContainsString('spvCustomPrice', $lists['sql']);
        $this->assertStringContainsString('__import__.product_', $lists['sql']);
        $this->assertStringContainsString('item_ids/product_tmpl_id/id', $lists['sql']);
        $this->assertStringNotContainsString('product_variant_', $lists['sql']);
        $this->assertSame('product_variant_H-STSR', $catalog->variantExternalName('product_H-STSR'));
        $this->assertNull($catalog->variantExternalName('product_categ_tape'));
        $this->assertNull($catalog->variantExternalName('product_variant_H-STSR'));
        $inventory = $catalog->find('inventory_on_hand');
        $this->assertStringContainsString("Item_Type) = 'Sales Inventory'", $inventory['sql']);
        $this->assertStringContainsString("Item_Type) = 'Sales Inventory'", $inventory['count_sql']);
        $this->assertStringContainsString('spvInventoryMultibin', $inventory['sql']);
        $this->assertStringContainsString('__import__.stock_bin_', $inventory['sql']);
        $this->assertStringContainsString("'/Stock/', REPLACE(REPLACE(RTRIM(m.Bin), CHAR(13), ''), CHAR(10), '')) AS location_id", $inventory['sql']);
        $warehouses = $catalog->find('warehouses');
        $bins = $catalog->find('stock_locations');
        $this->assertStringContainsString('IV40700', $warehouses['sql']);
        $this->assertSame('Short Name', $warehouses['fields']['code']);
        $this->assertStringContainsString('spvBin', $bins['sql']);
        $this->assertStringContainsString("/Stock') AS location_id", $bins['sql']);
        $this->assertSame('Bins', $bins['title']);
        $customers = $catalog->find('customers');
        $this->assertStringContainsString("WHEN 'SOUTH CAROLINA' THEN 'SC'", $customers['sql']);
        $this->assertStringContainsString("WHEN 'MARYLAND' THEN 'MD'", $customers['sql']);
        $this->assertStringNotContainsString('RTRIM(a.State) AS [state_id/code]', $customers['sql']);
        $assign = $catalog->find('customer_price_assign');
        $this->assertSame('Pricelist / External ID', $assign['fields']['property_product_pricelist/id']);
        $this->assertStringContainsString('AS name', $assign['sql']);
        $this->assertSame('Name', $assign['fields']['name']);
        $this->assertStringContainsString('INNER JOIN dbo.spvCustomer', $assign['sql']);
        $this->assertStringContainsString('INNER JOIN dbo.spvCustomer', $assign['count_sql']);
    }
}
