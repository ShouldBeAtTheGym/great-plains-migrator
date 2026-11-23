<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Models\GpCopy;
use App\Models\User;
use App\Services\GpConnection;
use App\Services\OdooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenInvoiceCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_open_invoice_page_offers_the_customers_that_are_not_in_odoo(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
            'intro_open' => false,
        ]);
        ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'sales_invoices_open_headers',
            'sort' => 1,
            'status' => 'pending',
        ]);
        GpCopy::query()->create([
            'company_id' => $company->id,
            'export_key' => 'sales_invoices_open_headers',
            'status' => 'complete',
            'source_hash' => 'stale',
            'record_count' => 4,
            'saved_count' => 4,
            'columns' => ['id', 'name', 'partner_id/id'],
        ]);

        $this->mock(GpConnection::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(null);
            $mock->shouldReceive('rows')->andReturn([
                ['name' => '1000471'],
                ['name' => '31923'],
            ]);
        });
        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('findExternalIds')->andReturn([
                'partner_31923' => ['module' => '__import__', 'name' => 'partner_31923', 'res_id' => 10],
            ]);
            $mock->shouldReceive('execute')->andReturn([]);
        });

        $this->actingAs($user)
            ->get(route('steps.show', 'sales_invoices_open_headers'))
            ->assertOk()
            ->assertSee('Download customers not in Odoo')
            ->assertSee('Documents whose customer is not in Odoo yet are left out too.')
            ->assertSee(route('steps.missing-customers', 'sales_invoices_open_headers'), false);
    }
}
