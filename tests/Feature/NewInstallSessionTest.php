<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewInstallSessionTest extends TestCase
{
    public function test_a_new_install_creates_the_sessions_table_before_the_page_loads(): void
    {
        config(['session.driver' => 'database']);

        $this->assertFalse(Schema::hasTable('sessions'));

        $this->get(route('product'))->assertOk();

        $this->assertTrue(Schema::hasTable('sessions'));
    }
}
