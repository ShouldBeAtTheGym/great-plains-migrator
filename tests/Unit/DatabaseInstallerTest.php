<?php

namespace Tests\Unit;

use App\Services\DatabaseInstaller;
use RuntimeException;
use Tests\TestCase;

class DatabaseInstallerTest extends TestCase
{
    public function test_a_database_name_is_limited_to_letters_numbers_and_underscores(): void
    {
        $installer = app(DatabaseInstaller::class);

        $this->assertSame('great_plains_migrator', $installer->databaseName('great_plains_migrator'));

        $this->expectException(RuntimeException::class);
        $installer->databaseName('migrator;drop');
    }

    public function test_a_password_with_spaces_is_quoted_for_the_env_file(): void
    {
        $installer = app(DatabaseInstaller::class);

        $this->assertSame('secret', $installer->envValue('secret'));
        $this->assertSame('"p a s s"', $installer->envValue('p a s s'));
        $this->assertSame('"say \\"hi\\""', $installer->envValue('say "hi"'));
    }
}
