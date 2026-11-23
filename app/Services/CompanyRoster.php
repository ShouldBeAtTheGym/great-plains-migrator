<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CompanyRoster
{
    public function __construct(private OdooClient $odoo) {}

    public function path(Company $company): string
    {
        return Storage::disk('local')->path('rosters/'.$company->id.'.csv');
    }

    public function departments(Company $company): array
    {
        $seen = [];
        foreach ($this->roster($company) as $person) {
            $id = trim((string) ($person['department_id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $name = trim((string) ($person['department_name'] ?? ''));
            $seen[$id] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $this->departmentName($id),
                'parent_id' => trim((string) ($person['department_parent_id'] ?? '')),
            ];
        }

        return array_values($seen);
    }

    public function employees(Company $company): array
    {
        $people = $this->managersFirst($this->roster($company));
        $users = $this->usersByEmail($company, array_column($people, 'email'));
        $existing = $this->existingEmployees($company);
        $used = [];
        $resolved = [];
        foreach ($people as $person) {
            $email = strtolower($person['email']);
            $match = $existing === null ? null : $this->matchEmployee($person, $existing, $users[$email]['id'] ?? 0, $used);
            if ($match) {
                $used[$match['id']] = true;
            }
            $userId = '';
            if ($match && $match['user_id'] !== '') {
                $userId = $match['user_id'];
            } elseif ($existing !== null && isset($users[$email])) {
                $userId = $users[$email]['id'];
            }
            $importId = is_array($match) ? (string) $match['import_id'] : '';
            if ($importId === '') {
                $importId = $this->importId((string) $person['external_id']);
                if ($match) {
                    $this->ensureEmployeeXmlid($company, $importId, (int) $match['id']);
                }
            }
            $resolved[$person['external_id']] = [
                'id' => $importId,
                'user_id' => $userId,
            ];
        }

        $rows = [];
        foreach ($people as $person) {
            $row = $resolved[$person['external_id']];
            $manager = $person['manager_external_id'];
            $rows[] = [
                'id' => $row['id'],
                'name' => $person['name'],
                'job_title' => $person['job_title'],
                'work_email' => $person['email'],
                'department_id' => $person['department_id'],
                'parent_id' => $manager !== '' ? ($resolved[$manager]['id'] ?? '') : '',
                'user_id' => $row['user_id'],
            ];
        }

        return $rows;
    }

    public function companyExternalId(Company $company): array
    {
        $companyId = (int) $company->odoo_import_company_id;
        if ($companyId > 0) {
            try {
                $rows = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
                    ['model', '=', 'res.company'],
                    ['res_id', '=', $companyId],
                ]], [
                    'fields' => ['module', 'name'],
                    'limit' => 1,
                ]);
                $module = trim((string) ($rows[0]['module'] ?? ''));
                $name = trim((string) ($rows[0]['name'] ?? ''));
                if ($module !== '' && $name !== '') {
                    return ['company_id/id', $module.'.'.$name];
                }
            } catch (RuntimeException) {
            }
        }

        return ['company_id', trim((string) ($company->odoo_import_company_name ?: $company->odoo_company_name))];
    }

    private function departmentName(string $id): string
    {
        $name = preg_replace('/^department_/', '', $id) ?? $id;
        $name = str_replace('_', ' ', $name);

        return ucwords($name);
    }

    private function roster(Company $company): array
    {
        $path = $this->path($company);
        if (! is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        $header = fgetcsv($handle) ?: [];
        $people = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < count($header)) {
                continue;
            }
            $row = array_combine($header, array_slice($line, 0, count($header)));
            if (! is_array($row) || trim((string) ($row['external_id'] ?? '')) === '') {
                continue;
            }
            $people[] = $row;
        }
        fclose($handle);

        return $people;
    }

    private function usersByEmail(Company $company, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email) => strtolower(trim((string) $email)),
            $emails
        ))));
        if ($emails === []) {
            return [];
        }

        try {
            $rows = $this->odoo->execute($company, 'res.users', 'search_read', [[
                ['login', 'in', $emails],
            ]], [
                'fields' => ['login', 'partner_id'],
                'limit' => count($emails),
                'context' => ['active_test' => false],
            ]);
        } catch (RuntimeException) {
            return [];
        }

        $users = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $login = strtolower(trim((string) ($row['login'] ?? '')));
            $partner = $row['partner_id'] ?? null;
            $partnerId = is_array($partner) ? (int) ($partner[0] ?? 0) : 0;
            if ($login !== '' && (int) ($row['id'] ?? 0) > 0) {
                $users[$login] = [
                    'id' => (int) $row['id'],
                    'partner_id' => $partnerId > 0 ? $partnerId : '',
                ];
            }
        }

        return $users;
    }

    private function existingEmployees(Company $company): ?array
    {
        try {
            $rows = $this->odoo->execute($company, 'hr.employee', 'search_read', [[]], [
                'fields' => ['name', 'work_email', 'user_id'],
                'limit' => 500,
                'context' => ['active_test' => false],
            ]);
            $xmlids = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
                ['model', '=', 'hr.employee'],
            ]], [
                'fields' => ['module', 'name', 'res_id'],
                'limit' => 500,
            ]);
        } catch (RuntimeException) {
            return null;
        }

        $importIds = [];
        foreach (is_array($xmlids) ? $xmlids : [] as $xmlid) {
            if (! is_array($xmlid)) {
                continue;
            }
            $module = trim((string) ($xmlid['module'] ?? ''));
            $name = trim((string) ($xmlid['name'] ?? ''));
            $resId = (int) ($xmlid['res_id'] ?? 0);
            if ($module === '' || $name === '' || $resId <= 0) {
                continue;
            }
            $importIds[$resId] = $module === '__import__' ? $name : $module.'.'.$name;
        }

        $employees = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
                continue;
            }
            $user = $row['user_id'] ?? null;
            $userId = is_array($user) ? (int) ($user[0] ?? 0) : (int) $user;
            $employees[] = [
                'id' => (int) $row['id'],
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => strtolower(trim((string) ($row['work_email'] ?? ''))),
                'user_id' => $userId > 0 ? (string) $userId : '',
                'import_id' => $importIds[(int) $row['id']] ?? '',
            ];
        }

        return $employees;
    }

    private function ensureEmployeeXmlid(Company $company, string $name, int $resId): void
    {
        $existing = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
            ['module', '=', '__import__'],
            ['name', '=', $name],
        ]], [
            'fields' => ['model', 'res_id'],
            'limit' => 1,
        ]);
        if (is_array($existing) && isset($existing[0]) && is_array($existing[0])) {
            return;
        }

        $this->odoo->execute($company, 'ir.model.data', 'create', [[
            'module' => '__import__',
            'name' => $name,
            'model' => 'hr.employee',
            'res_id' => $resId,
            'noupdate' => true,
        ]]);
    }

    private function matchEmployee(array $person, array $employees, int $userId, array $used): ?array
    {
        if ($userId > 0) {
            foreach ($employees as $employee) {
                if (isset($used[$employee['id']])) {
                    continue;
                }
                if ((int) $employee['user_id'] === $userId) {
                    return $employee;
                }
            }
        }

        $email = strtolower(trim((string) $person['email']));
        if ($email !== '') {
            foreach ($employees as $employee) {
                if (isset($used[$employee['id']])) {
                    continue;
                }
                if ($employee['email'] !== '' && $employee['email'] === $email) {
                    return $employee;
                }
            }
        }

        $wanted = $this->nameKey((string) $person['name']);
        if ($wanted === '') {
            return null;
        }
        foreach ($employees as $employee) {
            if (isset($used[$employee['id']])) {
                continue;
            }
            if ($this->nameKey($employee['name']) === $wanted) {
                return $employee;
            }
        }

        return null;
    }

    private function managersFirst(array $people): array
    {
        $byId = [];
        foreach ($people as $person) {
            $byId[$person['external_id']] = $person;
        }
        $ordered = [];
        $seen = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$seen, $byId): void {
            if (isset($seen[$id]) || ! isset($byId[$id])) {
                return;
            }
            $seen[$id] = true;
            $manager = trim((string) ($byId[$id]['manager_external_id'] ?? ''));
            if ($manager !== '') {
                $visit($manager);
            }
            $ordered[] = $byId[$id];
        };
        foreach ($people as $person) {
            $visit((string) $person['external_id']);
        }

        return $ordered;
    }

    private function importId(string $externalId): string
    {
        $externalId = trim($externalId);
        $prefix = '__import__.';
        if (str_starts_with($externalId, $prefix)) {
            return substr($externalId, strlen($prefix));
        }

        return $externalId;
    }

    private function nameKey(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $nicknames = [
            'david' => 'dave',
            'patrick' => 'pat',
            'matthew' => 'matt',
            'jeffrey' => 'jeff',
            'joshua' => 'josh',
        ];
        $tokens = array_values(array_filter(explode(' ', trim($name)), static fn ($token) => $token !== ''));
        $tokens = array_map(static fn ($token) => $nicknames[$token] ?? $token, $tokens);
        sort($tokens);

        return implode(' ', $tokens);
    }
}
