<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SalespersonLink;
use App\Models\User;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use App\Services\OdooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalespersonLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_cross_reference_is_used_on_the_sales_file(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
            'intro_open' => false,
            'odoo_import_company_name' => 'Geckobrands, LLC',
        ]);

        $this->mock(GpConnection::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(null);
            $mock->shouldReceive('rows')->andReturn([
                ['id' => '205', 'first_name' => 'ALT', 'last_name' => 'TRACIE', 'inactive' => 0, 'orders' => 7],
            ]);
        });
        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($company, string $model, string $method) {
                if ($model === 'res.users') {
                    return [['id' => 5, 'name' => 'Corinne Vandermolen', 'login' => 'corinne@example.com']];
                }
                if ($model === 'ir.model.data' && $method === 'search_read') {
                    return [];
                }

                return 1;
            });
        });

        $this->actingAs($user)
            ->get(route('salespeople.show'))
            ->assertOk()
            ->assertSee('Create the salesperson in Odoo first')
            ->assertSee('ALT TRACIE')
            ->assertSee('Download user file');

        $download = $this->actingAs($user)
            ->get(route('salespeople.download'));
        $download->assertOk();
        $download->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('salesperson_205,ALT TRACIE,gp.205,"Geckobrands, LLC",inbox,sales_team.group_sale_salesman', $download->streamedContent());

        $this->actingAs($user)
            ->post(route('salespeople.update'), ['links' => ['205' => 5]])
            ->assertRedirect(route('salespeople.show'));

        $link = SalespersonLink::query()->first();
        $this->assertSame('205', $link->gp_id);
        $this->assertSame('odooexport.user_5', $link->external_id);

        $export = app(ExportCatalog::class)->prepare($company, app(ExportCatalog::class)->find('customers'));
        $this->assertStringContainsString("WHEN '205' THEN 'odooexport.user_5'", $export['sql']);
        $this->assertStringNotContainsString('gp_salesperson', $export['sql']);
        $this->assertSame('Salesperson / External ID', $export['fields']['user_id/id']);

        $invoice = app(ExportCatalog::class)->prepare($company, app(ExportCatalog::class)->find('sales_invoices_open_headers'));
        $this->assertSame('Salesperson / External ID', $invoice['fields']['invoice_user_id/id']);
        $this->assertStringContainsString("WHEN '205' THEN 'odooexport.user_5'", $invoice['sql']);
    }
}
