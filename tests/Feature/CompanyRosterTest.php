<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\OdooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_file_links_an_existing_user_and_leaves_the_rest_blank(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
            'intro_open' => false,
            'odoo_import_company_name' => 'Howies Hockey Tape',
        ]);
        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($company, string $model) {
                if ($model === 'res.users') {
                    return [[
                        'id' => 42,
                        'login' => 'dave@howieshockeytape.com',
                        'partner_id' => [77, 'David Visser'],
                    ]];
                }

                return [];
            });
        });

        $departments = $this->actingAs($user)->get(route('employees.departments'));
        $departments->assertOk();
        $departmentCsv = $departments->streamedContent();
        $this->assertStringContainsString('department_sales,Sales,department_executive,"Howies Hockey Tape"', $departmentCsv);

        $employees = $this->actingAs($user)->get(route('employees.download'));
        $employees->assertOk();
        $csv = $employees->streamedContent();
        $this->assertStringContainsString('emp_dave_howieshockeytape_com,"David Visser","Sales Director",dave@howieshockeytape.com,department_sales,emp_max_howieshockeytape_com,42,"Howies Hockey Tape"', $csv);
        $this->assertStringContainsString('emp_max_howieshockeytape_com,"Max Sieplinga","CEO / Owner",max@howieshockeytape.com,department_executive,,,"Howies Hockey Tape"', $csv);
        $this->assertStringNotContainsString('work_contact_id', $csv);
        $this->assertStringNotContainsString(',1052,', $csv);
    }

    public function test_employee_file_updates_the_salesperson_employee_already_in_odoo(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
            'intro_open' => false,
            'odoo_import_company_name' => 'Howies Hockey Tape',
        ]);
        $this->mock(OdooClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($company, string $model, string $method, array $args = []) {
                if ($model === 'res.users') {
                    return [[
                        'id' => 845,
                        'login' => 'dave@howieshockeytape.com',
                        'partner_id' => [77, 'Dave Visser'],
                    ]];
                }
                if ($model === 'hr.employee') {
                    return [[
                        'id' => 26,
                        'name' => 'Dave Visser',
                        'work_email' => 'dave@howieshockeytape.com',
                        'user_id' => [845, 'Dave Visser'],
                    ]];
                }
                $domain = $args[0] ?? [];
                foreach ($domain as $clause) {
                    if (($clause[0] ?? '') === 'model' && ($clause[2] ?? '') === 'hr.employee') {
                        return [[
                            'module' => '__import__',
                            'name' => 'salesperson_employee_0004',
                            'res_id' => 26,
                        ]];
                    }
                }

                return [];
            });
        });

        $employees = $this->actingAs($user)->get(route('employees.download'));
        $employees->assertOk();
        $csv = $employees->streamedContent();
        $this->assertStringContainsString('salesperson_employee_0004,"David Visser","Sales Director",dave@howieshockeytape.com,department_sales,emp_max_howieshockeytape_com,845,"Howies Hockey Tape"', $csv);
        $this->assertStringContainsString('emp_alyssa_howieshockeytape_com,"Alyssa Exline","Inside Sales & Account Manager - Canada",alyssa@howieshockeytape.com,department_sales,salesperson_employee_0004,,"Howies Hockey Tape"', $csv);
    }
}
