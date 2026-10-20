<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\ExportCatalog;
use App\Services\OdooClient;
use App\Services\UserFieldCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFieldCatalogTest extends TestCase
{
    use RefreshDatabase;
    public function test_a_connection_scan_keeps_every_user_field_table(): void
    {
        $catalog = (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->collect([
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xCategory', 'Label' => 'Category'],
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xSubCategory', 'Label' => 'Sub Category'],
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xBarcode', 'Label' => 'Barcode'],
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xBrands', 'Label' => 'Brands'],
            ['Table_Name' => 'spxCustomer', 'Field_Name' => 'xSubClass', 'Label' => 'Sub Class'],
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xMissing', 'Label' => 'Missing'],
        ], [
            ['TABLE_NAME' => 'spxItemMaster', 'COLUMN_NAME' => 'xCategory'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'xCategory'],
            ['TABLE_NAME' => 'spxItemMaster', 'COLUMN_NAME' => 'xSubCategory'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'xSubCategory'],
            ['TABLE_NAME' => 'spxItemMaster', 'COLUMN_NAME' => 'xBarcode'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'xBarcode'],
            ['TABLE_NAME' => 'spxItemMaster', 'COLUMN_NAME' => 'xBrands'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'xBrands'],
            ['TABLE_NAME' => 'spxCustomer', 'COLUMN_NAME' => 'xSubClass'],
            ['TABLE_NAME' => 'spvCustomer', 'COLUMN_NAME' => 'xSubClass'],
        ]);

        $tables = array_values(array_unique(array_column($catalog['fields'], 'table')));
        sort($tables);
        $this->assertSame(['spxCustomer', 'spxItemMaster'], $tables);
        $this->assertSame('xCategory', (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxItemMaster', ['category'], ['sub']));
        $this->assertSame('xSubCategory', (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxItemMaster', ['subcategory']));
        $this->assertSame('xBarcode', (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxItemMaster', ['barcode']));
        $this->assertSame('xBrands', (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxItemMaster', ['brand', 'brands']));
        $this->assertSame('xSubClass', (new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxCustomer', ['subclass']));
        $this->assertNull((new UserFieldCatalog(app(\App\Services\GpConnection::class)))->find($catalog, 'spxItemMaster', ['missing']));
    }

    public function test_category_can_fall_back_when_the_user_table_has_no_category_field(): void
    {
        $finder = new UserFieldCatalog(app(\App\Services\GpConnection::class));
        $catalog = $finder->collect([
            ['Table_Name' => 'spxItemMaster', 'Field_Name' => 'xBarCode', 'Label' => 'Bar Code'],
        ], [
            ['TABLE_NAME' => 'spxItemMaster', 'COLUMN_NAME' => 'xBarCode'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'xBarCode'],
            ['TABLE_NAME' => 'spvItemMaster', 'COLUMN_NAME' => 'Item_Generic_Description'],
        ]);

        $this->assertNull($finder->find($catalog, 'spxItemMaster', ['category'], ['sub']));
        $this->assertSame('Item_Generic_Description', $finder->standard($catalog, 'spvItemMaster', 'Item_Generic_Description'));
        $this->assertSame('xBarCode', $finder->find($catalog, 'spxItemMaster', ['barcode']));
    }

    public function test_a_saved_user_field_array_is_applied_before_great_plains_is_queried(): void
    {
        $company = Company::query()->create([
            'name' => 'Howies',
            'salespad_report' => [
                'user_fields_scanned' => true,
                'user_field_catalog' => [
                    'fields' => [
                        ['table' => 'spxItemMaster', 'view' => 'spvItemMaster', 'field' => 'xCategory', 'label' => 'Category', 'on_view' => true],
                        ['table' => 'spxItemMaster', 'view' => 'spvItemMaster', 'field' => 'xSubCategory', 'label' => 'Sub Category', 'on_view' => true],
                    ],
                    'standards' => [],
                ],
            ],
        ]);
        $catalog = new ExportCatalog($this->createMock(OdooClient::class));
        $export = $catalog->bindItemUserFields($company, $catalog->find('product_categories'));

        $this->assertStringNotContainsString('[[category]]', $export['sql']);
        $this->assertStringNotContainsString('[[category]]', $export['count_sql']);
        $this->assertStringContainsString('xCategory', $export['count_sql']);
        $this->assertStringContainsString('xSubCategory', $export['sql']);
    }

    public function test_product_category_sql_waits_for_the_saved_user_field_array(): void
    {
        $catalog = new ExportCatalog($this->createMock(OdooClient::class));
        $categories = $catalog->find('product_categories');
        $products = $catalog->find('products');

        $this->assertStringContainsString('[[category]]', $categories['sql']);
        $this->assertStringContainsString('[[subcategory]]', $categories['count_sql']);
        $this->assertStringNotContainsString('xCategory', $categories['sql']);
        $this->assertStringNotContainsString('xSubCategory', $categories['sql']);
        $this->assertStringContainsString('[[barcode]]', $products['sql']);
        $this->assertStringNotContainsString('xBarcode', $products['sql']);
        $this->assertStringNotContainsString('xBrands', $products['sql']);
        $this->assertStringContainsString('[[customer_email]]', $catalog->find('customers')['sql']);
        $this->assertStringNotContainsString('Primary_Email', $catalog->find('customers')['sql']);
    }

    public function test_customer_email_uses_the_column_this_install_actually_has(): void
    {
        $finder = new UserFieldCatalog(app(\App\Services\GpConnection::class));
        $catalog = $finder->collect([], [
            ['TABLE_NAME' => 'spvCustomer', 'COLUMN_NAME' => 'Email_To'],
        ]);
        $this->assertSame('Email_To', $finder->standard($catalog, 'spvCustomer', 'customer_email'));

        $withPrimary = $finder->collect([], [
            ['TABLE_NAME' => 'spvCustomer', 'COLUMN_NAME' => 'Primary_Email'],
            ['TABLE_NAME' => 'spvCustomer', 'COLUMN_NAME' => 'Email_To'],
        ]);
        $this->assertSame('Primary_Email', $finder->standard($withPrimary, 'spvCustomer', 'customer_email'));

        $company = Company::query()->create([
            'name' => 'Howies',
            'salespad_report' => [
                'user_fields_scanned' => true,
                'user_field_catalog' => $catalog,
            ],
        ]);
        $exports = new ExportCatalog($this->createMock(OdooClient::class));
        $export = $exports->bindItemUserFields($company, $exports->find('customers'));

        $this->assertStringContainsString('RTRIM(CAST(c.Email_To AS varchar(255)))', $export['sql']);
        $this->assertStringNotContainsString('[[customer_email]]', $export['sql']);
        $this->assertStringNotContainsString('Primary_Email', $export['sql']);
    }
}
