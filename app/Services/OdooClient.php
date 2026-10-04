<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OdooClient
{
    private ?int $uid = null;

    public function version(Company $company): array
    {
        $result = $this->rpc($company, 'common', 'version', []);

        return is_array($result) ? $result : [];
    }

    public function authenticate(Company $company): int
    {
        if ($this->uid) {
            return $this->uid;
        }

        $uid = $this->rpc($company, 'common', 'authenticate', [
            $company->odoo_database,
            $company->odoo_username,
            $company->odoo_api_key,
            new \stdClass,
        ]);

        if (! is_int($uid) && ! (is_string($uid) && ctype_digit($uid))) {
            throw new RuntimeException('Odoo did not accept that database, user, or API key.');
        }

        return $this->uid = (int) $uid;
    }

    public function companies(Company $company): array
    {
        return $this->execute($company, 'res.company', 'search_read', [[]], [
            'fields' => ['name', 'email', 'phone', 'street', 'city', 'zip', 'country_id', 'currency_id'],
            'limit' => 5,
        ]);
    }

    public function execute(Company $company, string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->authenticate($company);

        return $this->rpc($company, 'object', 'execute_kw', [
            $company->odoo_database,
            $uid,
            $company->odoo_api_key,
            $model,
            $method,
            $args,
            $kwargs === [] ? new \stdClass : $kwargs,
        ]);
    }

    public function findExternalIds(Company $company, array $models, array $names): array
    {
        $found = [];
        foreach ($models as $model) {
            try {
                $rows = $this->execute($company, 'ir.model.data', 'search_read', [[
                    ['model', '=', $model],
                    ['name', 'in', $names],
                ]], [
                    'fields' => ['name', 'res_id', 'model'],
                    'limit' => count($names),
                ]);
            } catch (RuntimeException) {
                continue;
            }

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['name'])) {
                    continue;
                }
                $found[$row['name']] = $row;
            }

            if (count($found) === count($names)) {
                break;
            }
        }

        return $found;
    }

    private function rpc(Company $company, string $service, string $method, array $args): mixed
    {
        $url = rtrim((string) $company->odoo_url, '/').'/jsonrpc';

        try {
            $response = Http::timeout(25)
                ->acceptJson()
                ->withOptions(['verify' => ! $company->odoo_trust_tls])
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'id' => 1,
                    'params' => [
                        'service' => $service,
                        'method' => $method,
                        'args' => $args,
                    ],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach that Odoo address. Check the web address and try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Odoo returned HTTP '.$response->status().'. Check the web address.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Odoo did not return a JSON-RPC response. The address may not be an Odoo site.');
        }

        if (isset($body['error'])) {
            $message = $body['error']['data']['message'] ?? $body['error']['message'] ?? 'Odoo rejected the request.';
            throw new RuntimeException((string) $message);
        }

        return $body['result'] ?? null;
    }
}
