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
        $this->assertSame('Customers and vendors', $lists['group']);
        $this->assertStringContainsString('spvCustomPrice', $lists['sql']);
        $this->assertStringContainsString('__import__.product_variant_', $lists['sql']);
        $this->assertSame('Pricelist / External ID', $catalog->find('customer_price_assign')['fields']['property_product_pricelist/id']);
    }
}
