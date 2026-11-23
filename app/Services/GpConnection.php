<?php

namespace App\Services;

use App\Models\Company;
use RuntimeException;

class GpConnection
{
    public function connect(Company $company)
    {
        if (! function_exists('sqlsrv_connect')) {
            throw new RuntimeException('The PHP SQL Server extension is not installed on this server.');
        }

        if (! $company->gp_server || ! $company->gp_database || ! $company->gp_username || ! $company->gp_password) {
            throw new RuntimeException('Enter the Great Plains server, company database, user, and password.');
        }

        $server = $company->gp_server;
        if (! str_contains($server, ',')) {
            $server .= ','.$company->gpPort();
        }

        $connection = sqlsrv_connect($server, [
            'Database' => $company->gp_database,
            'UID' => $company->gp_username,
            'PWD' => $company->gp_password,
            'TrustServerCertificate' => $company->gp_trust_cert,
            'LoginTimeout' => 8,
            'CharacterSet' => 'UTF-8',
        ]);

        if ($connection === false) {
            throw new RuntimeException($this->errorText('Great Plains did not accept that connection.'));
        }

        return $connection;
    }

    public function connectDatabase(Company $company, string $database)
    {
        $copy = clone $company;
        $copy->gp_database = $database;

        return $this->connect($copy);
    }

    /**
     * Company databases on this Great Plains server, leaving out test companies.
     *
     * @return list<array{name: string, database: string}>
     */
    public function companyList(Company $company): array
    {
        $fallback = [[
            'name' => $company->gp_company_name ?: $company->name,
            'database' => (string) $company->gp_database,
        ]];
        try {
            $connection = $this->connect($company);
        } catch (RuntimeException) {
            return $fallback;
        }

        try {
            $rows = $this->rows($connection, 'SELECT RTRIM(INTERID) AS db, RTRIM(CMPNYNAM) AS name FROM DYNAMICS.dbo.SY01500');
        } catch (RuntimeException) {
            return $fallback;
        } finally {
            if (is_resource($connection)) {
                sqlsrv_close($connection);
            }
        }

        $list = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $database = trim((string) ($row['db'] ?? ''));
            if ($name === '' || $database === '' || str_starts_with(strtoupper($name), 'TEST')) {
                continue;
            }
            $list[] = ['name' => $name, 'database' => $database];
        }
        usort($list, function (array $left, array $right) use ($company): int {
            $current = strcasecmp((string) $company->gp_database, $left['database']) === 0 ? 0 : 1;
            $other = strcasecmp((string) $company->gp_database, $right['database']) === 0 ? 0 : 1;

            return $current <=> $other ?: strcasecmp($left['name'], $right['name']);
        });

        return $list !== [] ? $list : $fallback;
    }

    public function rows($connection, string $sql, int $limit = 0): array
    {
        $statement = sqlsrv_query($connection, $sql, [], [
            'QueryTimeout' => 120,
            'Scrollable' => SQLSRV_CURSOR_FORWARD,
        ]);

        if ($statement === false) {
            throw new RuntimeException($this->errorText('A Great Plains query failed.'));
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function errorText(string $fallback): string
    {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if (! is_array($errors) || $errors === []) {
            return $fallback;
        }

        $messages = [];
        foreach ($errors as $error) {
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === [] ? $fallback : $fallback.' '.implode(' ', $messages);
    }
}
