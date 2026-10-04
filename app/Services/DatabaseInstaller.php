<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class DatabaseInstaller
{
    public function ready(): bool
    {
        if (! app()->environment('testing') && $this->flagged()) {
            return true;
        }

        try {
            $ready = Schema::hasTable('companies');
        } catch (Throwable) {
            return false;
        }

        if ($ready && ! app()->environment('testing')) {
            $this->flag();
        }

        return $ready;
    }

    public function install(array $input): void
    {
        if ($this->ready()) {
            throw new RuntimeException('The MySQL tables are already created.');
        }

        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 3306);
        $database = $this->databaseName((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($host === '' || $username === '') {
            throw new RuntimeException('Enter the MySQL host and user.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The MySQL port must be between 1 and 65535.');
        }

        $this->createDatabase($host, $port, $database, $username, $password);
        $this->writeEnv([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
        ]);

        config([
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => $username,
            'database.connections.mysql.password' => $password,
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        Artisan::call('migrate', ['--force' => true]);

        if (trim((string) config('app.key')) === '') {
            Artisan::call('key:generate', ['--force' => true]);
        }

        $this->flag();
    }

    public function databaseName(string $name): string
    {
        $name = trim($name);
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new RuntimeException('The database name must start with a letter and use only letters, numbers, and underscores.');
        }

        return $name;
    }

    public function envValue(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9._-]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    private function createDatabase(string $host, int $port, string $database, string $username, string $password): void
    {
        try {
            $pdo = new PDO(
                'mysql:host='.$host.';port='.$port,
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $exception) {
            throw new RuntimeException($this->safeMessage($exception->getMessage(), $password));
        }
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            $example = base_path('.env.example');
            if (! is_file($example)) {
                throw new RuntimeException('The .env.example file is missing, so the connection cannot be saved.');
            }
            copy($example, $path);
        }

        $contents = (string) file_get_contents($path);
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->envValue($value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $contents)) {
                $contents = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $contents) ?? $contents;
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('The connection could not be saved.');
        }
    }

    private function safeMessage(string $message, string $password): string
    {
        if ($password !== '') {
            $message = str_replace($password, '********', $message);
        }

        return 'MySQL did not accept that connection. '.$message;
    }

    private function flagged(): bool
    {
        return is_file($this->flagPath());
    }

    private function flag(): void
    {
        @file_put_contents($this->flagPath(), '1');
    }

    private function flagPath(): string
    {
        return storage_path('framework/database.ready');
    }
}
