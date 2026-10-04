<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
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
            $mock->shouldReceive('execute')->andReturn([
                ['id' => 1, 'name' => 'Blueoco, LLC'],
                ['id' => 2, 'name' => 'Geckobrands, LLC'],
            ]);
        });

        $this->actingAs($user)
            ->post(route('steps.import-company', 'customers'), [
                'odoo_import_company_id' => 2,
            ])
            ->assertRedirect(route('steps.show', 'customers'));

        $company->refresh();
        $this->assertSame(2, $company->odoo_import_company_id);
        $this->assertSame('Geckobrands, LLC', $company->odoo_import_company_name);

        $export = app(ExportCatalog::class)->prepare($company, [
            'key' => 'customers',
            'fields' => [],
        ]);

        $this->assertSame('Geckobrands, LLC', $export['extra']['company_id']);
        $this->assertSame('Company', $export['fields']['company_id']);
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
