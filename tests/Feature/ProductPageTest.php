<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_page_explains_the_application_when_tables_exist(): void
    {
        $this->get(route('product'))
            ->assertOk()
            ->assertSee('What it is for')
            ->assertSee('What it does')
            ->assertSee('How to install')
            ->assertSee('Nothing in that database is changed')
            ->assertSee('ongoing remove and update cannot be done via sync')
            ->assertSee('Get started')
            ->assertDontSee('Create the tables');
    }

    public function test_the_table_installer_refuses_to_run_again_after_the_tables_exist(): void
    {
        $this->post(route('install.store'), [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'great_plains_migrator',
            'username' => 'migrator',
            'password' => 'secret',
        ])->assertRedirect(route('product'))
            ->assertSessionHas('error');
    }

    public function test_a_login_page_points_at_the_product_page(): void
    {
        User::factory()->create();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('What this application does');
    }
}
