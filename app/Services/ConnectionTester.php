<?php

namespace App\Services;

use App\Models\Company;
use RuntimeException;

class ConnectionTester
{
    public function __construct(
        private GpConnection $gp,
        private SalesPadInspector $salesPad,
        private OdooClient $odoo,
    ) {}

    public function testGp(Company $company, array $input): array
    {
        $server = trim((string) ($input['gp_server'] ?? ''));
        $server = (string) preg_replace('/[,:].*$/', '', $server);
        $port = (int) ($input['gp_port'] ?? 1433);
        if ($port < 1 || $port > 65535) {
            $port = 1433;
        }

        $company->fill([
            'gp_server' => $server,
            'gp_port' => $port,
            'gp_database' => trim((string) ($input['gp_database'] ?? '')),
            'gp_username' => trim((string) ($input['gp_username'] ?? '')),
            'gp_trust_cert' => (bool) ($input['gp_trust_cert'] ?? false),
            'gp_tested_at' => null,
        ]);
        $password = (string) ($input['gp_password'] ?? '');
        if ($password !== '') {
            $company->gp_password = $password;
        }
        $company->save();

        $connection = $this->gp->connect($company);
        try {
            $report = $this->salesPad->inspect($connection);
        } finally {
            sqlsrv_close($connection);
        }

        $profile = $report['company'];
        $company->fill([
            'salespad_installed' => $report['installed'],
            'salespad_report' => $report,
            'gp_company_name' => $profile['name'] ?: $company->gp_company_name,
            'gp_site_name' => $profile['site_name'] ?: null,
            'gp_address' => $profile['address'] ?: null,
            'gp_phone' => $profile['phone'] ?: null,
            'gp_version' => $profile['version'] ?: null,
            'gp_currency' => $profile['currency'] ?: null,
            'name' => $profile['site_name'] ?: ($profile['name'] ?: $company->name),
        ]);

        if ($report['installed']) {
            $company->gp_tested_at = now();
        }
        $company->save();

        if (! $report['installed']) {
            throw new RuntimeException(
                'Great Plains answered, but SalesPad is not ready. Missing views: '.implode(', ', $report['missing_views'])
            );
        }

        return $report;
    }

    public function testOdoo(Company $company, array $input): array
    {
        [$url, $port] = $this->odooAddress($input);

        $company->fill([
            'odoo_url' => $url,
            'odoo_port' => $port,
            'odoo_database' => trim((string) ($input['odoo_database'] ?? '')),
            'odoo_username' => trim((string) ($input['odoo_username'] ?? '')),
            'odoo_trust_tls' => (bool) ($input['odoo_trust_tls'] ?? false),
            'odoo_tested_at' => null,
        ]);
        $key = (string) ($input['odoo_api_key'] ?? $input['odoo_password'] ?? '');
        if ($key !== '') {
            $company->odoo_api_key = $key;
        }
        $company->save();

        $version = $this->odoo->version($company);
        $companies = $this->odoo->companies($company);
        $this->odoo->execute($company, 'ir.model.data', 'search_count', [[]]);
        $first = is_array($companies[0] ?? null) ? $companies[0] : [];
        $country = $first['country_id'][1] ?? '';
        $currency = $first['currency_id'][1] ?? '';

        $company->fill([
            'odoo_tested_at' => now(),
            'odoo_version' => (string) ($version['server_version'] ?? ''),
            'odoo_company_name' => (string) ($first['name'] ?? ''),
            'odoo_company' => [
                'name' => $first['name'] ?? '',
                'email' => $first['email'] ?? '',
                'phone' => $first['phone'] ?? '',
                'street' => $first['street'] ?? '',
                'city' => $first['city'] ?? '',
                'zip' => $first['zip'] ?? '',
                'country' => $country,
                'currency' => $currency,
            ],
        ]);
        $company->save();

        return [
            'version' => $company->odoo_version,
            'company' => $company->odoo_company,
        ];
    }

    private function odooAddress(array $input): array
    {
        $host = trim((string) ($input['odoo_host'] ?? ''));
        if ($host !== '') {
            $host = (string) preg_replace('#^https?://#i', '', $host);
            $host = rtrim($host, '/');
            if (str_contains($host, ':')) {
                [$host, $inlinePort] = explode(':', $host, 2);
                $input['odoo_port'] = $input['odoo_port'] ?? $inlinePort;
            }
            $port = (int) ($input['odoo_port'] ?? 443);
            if ($port < 1 || $port > 65535) {
                $port = 443;
            }
            $url = match ($port) {
                443 => 'https://'.$host,
                80 => 'http://'.$host,
                default => 'http://'.$host.':'.$port,
            };

            return [rtrim($url, '/'), $port];
        }

        $url = trim((string) ($input['odoo_url'] ?? ''));
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'http' ? 80 : 443));

        return [rtrim($url, '/'), $port];
    }
}
