<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\GreatPlainsTab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GreatPlainsTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setup_page_lists_distinct_great_plains_values(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->mock(GreatPlainsTab::class, function ($mock) {
            $mock->shouldReceive('choices')->once()->andReturn([
                'customer_class' => ['CORP20', 'CORP21'],
                'shipping_method' => ['UPS'],
            ]);
        });

        $this->actingAs($user)
            ->get(route('setup.great-plains'))
            ->assertOk()
            ->assertSee('Customer Number')
            ->assertSee('x_gp_customer_number')
            ->assertSee('Customer Class')
            ->assertSee('x_gp_address_code')
            ->assertSee('CORP20')
            ->assertSee('Shipping Method')
            ->assertSee('UPS');
    }

    public function test_creating_the_tab_saves_when_odoo_accepts_the_choices(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->mock(GreatPlainsTab::class, function ($mock) {
            $mock->shouldReceive('choices')->once()->andReturn([
                'customer_class' => ['CORP20'],
                'shipping_method' => ['UPS'],
            ]);
            $mock->shouldReceive('create')->once()->andReturn('The Great Plains tab is on the contact form.');
        });

        $this->actingAs($user)
            ->post(route('setup.great-plains.store'))
            ->assertRedirect(route('setup.great-plains'));

        $this->assertNotNull($company->fresh()->gp_tab_created_at);
    }
}
