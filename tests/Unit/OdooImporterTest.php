<?php

namespace Tests\Unit;

use App\Services\OdooImporter;
use Tests\TestCase;

class OdooImporterTest extends TestCase
{
    public function test_api_import_skips_columns_the_csv_says_not_to_import(): void
    {
        $importer = app(OdooImporter::class);

        $columns = $importer->importableColumns(
            ['id', 'name', 'gp_total', 'item_ids/applied_on', 'export_part_row'],
            ['item_ids/applied_on' => 'Do not import']
        );

        $this->assertSame([
            ['index' => 0, 'name' => 'id'],
            ['index' => 1, 'name' => 'name'],
        ], $columns);
    }

    public function test_only_odoo_error_messages_are_kept(): void
    {
        $importer = app(OdooImporter::class);

        $this->assertSame(
            ['No matching record found for external id partner_1'],
            $importer->errorMessages([
                ['type' => 'warning', 'message' => 'Unknown field gp_total'],
                ['type' => 'error', 'message' => 'No matching record found for external id partner_1'],
            ])
        );
    }
}
