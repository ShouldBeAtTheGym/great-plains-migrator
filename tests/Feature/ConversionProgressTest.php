<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_checklist_waits_for_both_connections(): void
    {
        $this->get('/dashboard')->assertRedirect(route('setup.gp'));
    }

    public function test_preflight_is_before_any_login_and_creates_no_account(): void
    {
        $this->post('/start')->assertRedirect(route('start.ready'));
        $this->get('/start/ready')->assertOk()->assertSee('Odoo Enterprise');
        $this->get('/start/overview')->assertRedirect(route('start.ready'));

        $this->post('/start/ready', [
            'gp_ready' => '1',
            'odoo_ready' => '1',
            'browser_ready' => '1',
        ])->assertRedirect(route('start.overview'));

        $this->get('/start/overview')->assertOk()->assertSee('documentation');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_great_plains_page_states_the_database_is_only_read(): void
    {
        $this->withSession(['office.preflight' => true])
            ->get('/setup/gp')
            ->assertOk()
            ->assertSee('No data is ever written to this Great Plains database')
            ->assertSee('local MySQL database')
            ->assertSee('this intro is not shown again')
            ->assertSee('Reset intro');
    }

    public function test_finished_setup_with_an_account_skips_the_intro(): void
    {
        User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/start/ready')->assertRedirect(route('login'));
        $this->get('/setup/gp')->assertRedirect(route('login'));
        $this->get('/setup/odoo')->assertRedirect(route('login'));
    }

    public function test_saved_setup_asks_for_a_login_instead_of_starting_over(): void
    {
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
        ]);

        $this->get('/')->assertRedirect(route('start.account'));
        $this->get('/start/account')->assertOk()->assertSee('Create your login');

        $this->post('/start/account', [
            'name' => 'Office Manager',
            'email' => 'office@example.com',
            'password' => 'Test-login-1',
            'password_confirmation' => 'Test-login-1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_account_sends_a_guest_to_login_not_welcome(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/start/ready')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_can_still_see_an_unfinished_intro(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Get started');
    }

    public function test_settings_reset_opens_the_intro_again(): void
    {
        $user = User::factory()->create();
        Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('settings.intro.reset'))
            ->assertRedirect(route('welcome'));

        $this->get('/')->assertOk()->assertSee('Get started');
        $this->assertTrue(Company::query()->first()->intro_open);
    }

    public function test_recheck_restarts_a_finished_step(): void
    {
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        $step = ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'sort' => 0,
            'status' => 'complete',
            'confirm_message' => 'Already confirmed.',
            'completed_at' => now(),
        ]);

        $this->partialMock(\App\Services\ConversionPlanner::class, function ($mock) {
            $mock->shouldReceive('removeImported')->once()->andReturn([
                'deleted' => 3,
                'archived' => 1,
                'remaining' => 0,
                'message' => 'Odoo deleted 3 records and archived 1.',
            ]);
        });

        $this->get('/steps/payment_terms')
            ->assertOk()
            ->assertSee('Recheck')
            ->assertSee('Delete in Odoo and restart')
            ->assertSee('Archive')
            ->assertDontSee('Download CSV again');

        $this->post('/steps/payment_terms/restart')
            ->assertRedirect(route('steps.show', 'payment_terms'));

        $fresh = $step->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_the_current_step_can_be_skipped_without_checking_odoo(): void
    {
        $company = Company::query()->create([
            'name' => 'Blue',
            'gp_tested_at' => now(),
            'odoo_tested_at' => now(),
            'preflight_passed_at' => now(),
        ]);
        $current = ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'sort' => 0,
            'status' => 'pending',
        ]);
        $next = ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'product_categories',
            'sort' => 1,
            'status' => 'pending',
        ]);

        $this->post(route('steps.skip', 'product_categories'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->post(route('steps.skip', 'payment_terms'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertSame('complete', $current->fresh()->status);
        $this->assertSame('Skipped. This file was not confirmed in Odoo.', $current->fresh()->confirm_message);
        $this->assertSame($next->id, ConversionStep::currentFor($company)->id);
    }

    public function test_welcome_page_is_the_front_door(): void
    {
        $this->get('/')->assertOk()->assertSee('Get started')->assertSee('some planning still has to take place')->assertSee('Test and test again. Import.');
    }

    public function test_current_step_is_the_first_one_not_confirmed(): void
    {
        $company = Company::query()->create(['name' => 'Blue']);
        ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'payment_terms',
            'sort' => 0,
            'status' => 'complete',
        ]);
        $next = ConversionStep::query()->create([
            'company_id' => $company->id,
            'export_key' => 'product_categories',
            'sort' => 1,
            'status' => 'pending',
        ]);

        $this->assertSame($next->id, ConversionStep::currentFor($company)->id);
    }

    public function test_api_login_returns_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'office@example.com',
            'password' => 'secret-pass',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['name', 'email']]);
    }
};
