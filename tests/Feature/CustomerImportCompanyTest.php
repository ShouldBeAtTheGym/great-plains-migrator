<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use App\Services\OdooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerImportCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_chosen_odoo_company_is_saved_and_added_to_the_customer_file(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($company, string $model) {
                if ($model === 'ir.model.data') {
                    return [['module' => 'odooexport', 'name' => 'company_2']];
                }

                return [
                    ['id' => 1, 'name' => 'Blueoco, LLC'],
                    ['id' => 2, 'name' => 'Geckobrands, LLC'],
                ];
            });
        });

        app(ConversionPlanner::class)->syncSteps($company);

        $this->actingAs($user)
            ->get(route('steps.show', 'customers'))
            ->assertOk()
            ->assertDontSee('Pick the company these customers belong to')
            ->assertDontSee('Save the company above before you download')
            ->assertSee('A customer in both companies has a blank Company');

        $export = app(ExportCatalog::class)->prepare($company, [
            'key' => 'customers',
            'fields' => [],
        ]);

        $this->assertSame('Company', $export['fields']['company_id']);
        $this->assertArrayNotHasKey('company_id/id', $export['extra'] ?? []);
    }

    public function test_an_unknown_company_is_rejected(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturn([
                ['id' => 1, 'name' => 'Blueoco, LLC'],
            ]);
        });

        $this->actingAs($user)
            ->from(route('steps.show', 'customers'))
            ->post(route('steps.import-company', 'customers'), [
                'odoo_import_company_id' => 99,
            ])
            ->assertRedirect(route('steps.show', 'customers'))
            ->assertSessionHas('error');

        $this->assertNull(Company::current()->odoo_import_company_id);
    }
}
