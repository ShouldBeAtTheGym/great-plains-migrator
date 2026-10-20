<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalespersonLink;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use App\Services\OdooClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalespersonController extends Controller
{
    public function show(GpConnection $gp, OdooClient $odoo): View
    {
        $company = Company::current();
        $loadError = null;
        $people = [];
        $users = [];

        try {
            $connection = $gp->connect($company);
            try {
                $people = $this->people($gp->rows($connection, $this->salespersonSql()));
            } finally {
                if (is_resource($connection)) {
                    sqlsrv_close($connection);
                }
            }
            $users = $this->users($odoo, $company);
        } catch (RuntimeException $exception) {
            $loadError = $exception->getMessage();
        }

        $links = SalespersonLink::query()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('gp_id');

        return view('salespeople.edit', [
            'company' => $company,
            'people' => $people,
            'users' => $users,
            'links' => $links,
            'loadError' => $loadError,
        ]);
    }

    public function download(GpConnection $gp, OdooClient $odoo): StreamedResponse|RedirectResponse
    {
        $company = Company::current();
        try {
            $connection = $gp->connect($company);
            try {
                $people = $this->people($gp->rows($connection, $this->salespersonSql()));
            } finally {
                if (is_resource($connection)) {
                    sqlsrv_close($connection);
                }
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('salespeople.show')->with('error', $exception->getMessage());
        }

        $companyName = trim((string) ($company->odoo_import_company_name ?: $company->odoo_company_name));
        $domain = $this->loginDomain($company);
        $filename = 'odoo_salesperson_users_'.date('Ymd').'.csv';
        $used = [];
        try {
            $used = $this->takenLogins($odoo, $company);
        } catch (RuntimeException) {
            $used = [];
        }

        return response()->streamDownload(function () use ($people, $companyName, $domain, $used) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'name', 'login', 'company_id', 'notification_type', 'group_ids/id', 'employee']);
            foreach ($people as $person) {
                $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $person['id']);
                fputcsv($out, [
                    'salesperson_'.$id,
                    $person['name'],
                    $this->login($person, $domain, $used),
                    $companyName,
                    'inbox',
                    'sales_team.group_sale_salesman',
                    '1',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function update(Request $request, GpConnection $gp, OdooClient $odoo, ExportCatalog $catalog): RedirectResponse
    {
        $company = Company::current();
        $posted = $request->input('links', []);
        if (! is_array($posted)) {
            return redirect()->route('salespeople.show')->with('error', 'The cross-reference was not saved.');
        }

        try {
            $allowed = collect($this->users($odoo, $company))->keyBy('id');
        } catch (RuntimeException $exception) {
            return redirect()->route('salespeople.show')->with('error', $exception->getMessage());
        }

        $keep = [];
        foreach ($posted as $gpId => $userId) {
            $gpId = trim((string) $gpId);
            $userId = (int) $userId;
            if ($gpId === '' || $userId === 0) {
                continue;
            }
            if (! $allowed->has($userId)) {
                continue;
            }
            $externalId = $catalog->userExternalId($company, $userId);
            SalespersonLink::query()->updateOrCreate(
                ['company_id' => $company->id, 'gp_id' => $gpId],
                ['odoo_user_id' => $userId, 'external_id' => $externalId]
            );
            $keep[] = $gpId;
        }

        SalespersonLink::query()
            ->where('company_id', $company->id)
            ->when($keep !== [], fn ($query) => $query->whereNotIn('gp_id', $keep), fn ($query) => $query)
            ->delete();

        return redirect()
            ->route('salespeople.show')
            ->with('status', 'The salesperson cross-reference is saved. Download the customer file and the sales files again before you import them.');
    }

    private function salespersonSql(): string
    {
        return <<<'SQL'
SELECT
    used.id,
    RTRIM(s.SLPRSNFN) AS first_name,
    RTRIM(s.SPRSNSLN) AS last_name,
    ISNULL(s.INACTIVE, 0) AS inactive,
    RTRIM(CAST(a.INET1 AS varchar(255))) AS email,
    used.orders
FROM (
    SELECT RTRIM(SLPRSNID) AS id, COUNT(*) AS orders
    FROM dbo.SOP10100
    WHERE SOPTYPE = 2
      AND VOIDSTTS = 0
      AND NULLIF(RTRIM(SLPRSNID), '') IS NOT NULL
    GROUP BY RTRIM(SLPRSNID)
) AS used
LEFT JOIN dbo.RM00301 AS s
    ON RTRIM(s.SLPRSNID) = used.id
LEFT JOIN dbo.SY01200 AS a
    ON a.Master_Type = 'SLP'
   AND RTRIM(a.Master_ID) = used.id
ORDER BY used.orders DESC, used.id
SQL;
    }

    private function people(array $rows): array
    {
        $people = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim(trim((string) ($row['first_name'] ?? '')).' '.trim((string) ($row['last_name'] ?? '')));
            $people[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'email' => trim((string) ($row['email'] ?? '')),
                'inactive' => (int) ($row['inactive'] ?? 0) === 1,
                'orders' => (int) ($row['orders'] ?? 0),
            ];
        }

        return $people;
    }

    private function users(OdooClient $odoo, Company $company): array
    {
        $rows = $odoo->execute($company, 'res.users', 'search_read', [[
            ['share', '=', false],
        ]], [
            'fields' => ['name', 'login'],
            'limit' => 200,
            'order' => 'name',
        ]);

        $users = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $users[] = [
                    'id' => $id,
                    'name' => $name,
                    'login' => trim((string) ($row['login'] ?? '')),
                ];
            }
        }

        return $users;
    }

    private function takenLogins(OdooClient $odoo, Company $company): array
    {
        $rows = $odoo->execute($company, 'res.users', 'search_read', [[]], [
            'fields' => ['login'],
            'limit' => 5000,
            'context' => ['active_test' => false],
        ]);
        $taken = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $login = strtolower(trim((string) ($row['login'] ?? '')));
            if ($login !== '') {
                $taken[$login] = true;
            }
        }

        return $taken;
    }

    private function loginDomain(Company $company): string
    {
        $username = strtolower(trim((string) $company->odoo_username));
        $domain = str_contains($username, '@') ? substr($username, strrpos($username, '@') + 1) : '';
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return $domain;
        }

        return 'example.com';
    }

    private function login(array $person, string $domain, array &$used): string
    {
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! isset($used[$email])) {
            $used[$email] = true;

            return $email;
        }

        $local = $this->loginLocal($person);
        $candidate = $local.'@'.$domain;
        $suffix = 2;
        while (isset($used[$candidate]) || ! filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $candidate = $local.'.'.$suffix.'@'.$domain;
            $suffix++;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    private function loginLocal(array $person): string
    {
        $name = strtolower((string) ($person['name'] ?? ''));
        $name = trim(preg_replace('/[^a-z0-9]+/', '.', $name) ?? '', '.');
        $id = strtolower((string) ($person['id'] ?? ''));
        $id = trim(preg_replace('/[^a-z0-9]+/', '.', $id) ?? '', '.');
        if ($id === '') {
            $id = 'user';
        }
        if ($name === '' || $name === $id) {
            return 'salesperson.'.$id;
        }

        return $name.'.'.$id;
    }
}
