<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GpCopyRow;
use App\Models\User;
use App\Services\BadCopyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadCopyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rejected_row_is_listed_from_settings(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'intro_open' => false,
        ]);
        $path = storage_path('framework/testing/bad-copy-report.json');
        if (is_file($path)) {
            unlink($path);
        }
        $this->app->instance(BadCopyReport::class, new BadCopyReport($path));

        GpCopyRow::query()->create([
            'company_id' => 1,
            'export_key' => 'customer_price_lists',
            'row_no' => 11,
            'record_key' => 'pricelist_100',
            'payload' => json_encode(['pricelist_100', '100 Acme']),
        ]);

        app(BadCopyReport::class)->record('Customer price lists', 1, 'customer_price_lists', 10, 12, [[
            'type' => 'error',
            'message' => 'No matching records found for external id product_MISSING',
            'field' => 'item_ids/product_tmpl_id',
            'record' => 1,
        ]]);
        app(BadCopyReport::class)->record('Customer price lists', 1, 'customer_price_lists', 10, 12, [[
            'type' => 'error',
            'message' => 'No matching records found for external id product_MISSING',
            'field' => 'item_ids/product_tmpl_id',
            'record' => 1,
        ]]);

        $settings = $this->actingAs($user)->get(route('settings.edit'));
        $settings->assertOk();
        $settings->assertSee('Bad copy report');
        $settings->assertSee('bad row.', false);
        $settings->assertSee(route('settings.bad-copy'), false);

        $report = $this->actingAs($user)->get(route('settings.bad-copy'));
        $report->assertOk();
        $report->assertSee('pricelist_100');
        $report->assertSee('100 Acme');
        $report->assertSee('product_MISSING');
        $report->assertSee('>11<', false);

        $download = $this->actingAs($user)->get(route('settings.bad-copy.download'));
        $download->assertOk();
        $this->assertStringContainsString('pricelist_100', $download->streamedContent());
    }
}
