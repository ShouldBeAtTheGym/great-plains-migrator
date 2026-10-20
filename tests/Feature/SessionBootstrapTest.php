<?php

namespace Tests\Feature;

use App\Services\DatabaseInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sessions_table_is_ready_before_laravel_starts_the_session(): void
    {
        config(['session.driver' => 'database']);

        $this->mock(DatabaseInstaller::class, function ($mock) {
            $mock->shouldReceive('ensureSessions')->once()->andReturnUsing(function () {
                $this->assertFalse(session()->isStarted());

                return true;
            });
            $mock->shouldReceive('ready')->andReturn(true);
        });

        $this->get(route('product'))->assertOk();
    }
}
