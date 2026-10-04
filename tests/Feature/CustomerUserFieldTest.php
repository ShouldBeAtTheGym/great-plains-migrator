<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SalespadUserField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerUserFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_user_field_list_can_be_saved(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        $field = SalespadUserField::query()->create([
            'company_id' => $company->id,
            'model_key' => 'customers',
            'source_table' => 'spxCustomer',
            'field_name' => 'Company_Type',
            'label' => 'Company Type',
            'selected' => false,
            'field_type' => 'selection',
            'options' => 'AIRPORT; APPAREL',
            'disabled' => true,
            'sql_type' => 'varchar',
            'sort' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('user-fields.show', 'customers'))
            ->assertOk()
            ->assertSee('Company Type')
            ->assertSee('Disabled in SalesPad');

        $this->actingAs($user)
            ->post(route('user-fields.update', 'customers'), [
                'action' => 'save',
                'fields' => [
                    $field->id => [
                        'selected' => '1',
                        'field_type' => 'text',
                        'options' => 'AIRPORT; APPAREL',
                    ],
                ],
            ])
            ->assertRedirect(route('user-fields.show', 'customers'));

        $fresh = $field->fresh();
        $this->assertTrue($fresh->selected);
        $this->assertSame('text', $fresh->field_type);
        $this->assertNull($fresh->odoo_field);
    }

    public function test_a_selection_needs_semicolon_choices(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        $field = SalespadUserField::query()->create([
            'company_id' => $company->id,
            'model_key' => 'customers',
            'source_table' => 'spxCustomer',
            'field_name' => 'Status',
            'label' => 'Status',
            'selected' => false,
            'field_type' => 'text',
            'sql_type' => 'varchar',
            'sort' => 0,
        ]);

        $this->actingAs($user)
            ->from(route('user-fields.show', 'customers'))
            ->post(route('user-fields.update', 'customers'), [
                'action' => 'save',
                'fields' => [
                    $field->id => [
                        'selected' => '1',
                        'field_type' => 'selection',
                        'options' => '   ',
                    ],
                ],
            ])
            ->assertRedirect(route('user-fields.show', 'customers'))
            ->assertSessionHas('error');

        $this->assertFalse($field->fresh()->selected);
    }
}
