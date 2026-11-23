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
            'fields' => ['name', 'parent_id', 'email', 'phone', 'street', 'city', 'zip', 'country_id', 'currency_id'],
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

    /**
     * @param  list<string>  $fields
     * @param  list<list<string>>  $rows
     * @param  array<string, mixed>  $context
     */
    public function load(Company $company, string $model, array $fields, array $rows, array $context = []): mixed
    {
        $uid = $this->authenticate($company);

        return $this->rpc($company, 'object', 'execute_kw', [
            $company->odoo_database,
            $uid,
            $company->odoo_api_key,
            $model,
            'load',
            [$fields, $rows],
            ['context' => array_merge([
                'tracking_disable' => true,
                'mail_create_nolog' => true,
                'mail_notrack' => true,
                'import_file' => true,
            ], $context)],
        ], 180);
    }

    /**
     * External id names already stored for this model, such as partner_1001.
     *
     * @return array<string, true>
     */
    public function externalIdNameSet(Company $company, string $model, string $prefix): array
    {
        $names = [];
        $lastId = 0;
        do {
            $domain = [
                ['model', '=', $model],
                ['module', '=', '__import__'],
                ['name', '=like', $prefix.'%'],
            ];
            if ($lastId > 0) {
                $domain[] = ['id', '>', $lastId];
            }
            $rows = $this->execute($company, 'ir.model.data', 'search_read', [$domain], [
                'fields' => ['id', 'name'],
                'limit' => 2000,
                'order' => 'id',
            ]);
            if (! is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $name = (string) ($row['name'] ?? '');
                if ($id > $lastId) {
                    $lastId = $id;
                }
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        } while (count($rows) === 2000);

        return $names;
    }

    /**
     * @param  array<string, true>  $known
     */
    public function resolveProductVariant(string $value, array $known): string
    {
        if ($value === '') {
            return '';
        }
        $name = preg_replace('/^__import__\./', '', $value) ?? $value;
        if (isset($known[$name])) {
            return '__import__.'.$name;
        }
        $shared = preg_replace('/^product_variant_(?:blu2|blue)_/', 'product_variant_', $name) ?? $name;
        if ($shared !== $name && isset($known[$shared])) {
            return '__import__.'.$shared;
        }

        return '';
    }

    public function findExternalIds(Company $company, array $models, array $names): array
    {
        $names = array_values(array_unique(array_filter(
            $names,
            static fn ($name) => is_string($name) && $name !== ''
        )));
        $wanted = array_fill_keys($names, true);
        $found = [];
        foreach ($models as $model) {
            foreach ($this->externalIdRows($company, $model, $names) as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (isset($wanted[$name])) {
                    $found[$name] = $row;
                }
                $full = trim((string) ($row['module'] ?? '')).'.'.$name;
                if (isset($wanted[$full])) {
                    $found[$full] = $row;
                }
            }

            if (count($found) === count($names)) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function externalIdRows(Company $company, string $model, array $names): array
    {
        $rows = [];
        try {
            $plain = $this->execute($company, 'ir.model.data', 'search_read', [[
                ['model', '=', $model],
                ['name', 'in', $names],
            ]], [
                'fields' => ['module', 'name', 'res_id', 'model'],
                'limit' => max(1, count($names)),
            ]);
            if (is_array($plain)) {
                $rows = $plain;
            }
        } catch (RuntimeException) {
            $rows = [];
        }

        $modules = [];
        $xmlNames = [];
        foreach ($names as $name) {
            if (! str_contains($name, '.')) {
                continue;
            }
            [$module, $xmlName] = explode('.', $name, 2);
            if ($module !== '' && $xmlName !== '') {
                $modules[$module] = $module;
                $xmlNames[$xmlName] = $xmlName;
            }
        }
        if ($modules === []) {
            return $rows;
        }

        try {
            $split = $this->execute($company, 'ir.model.data', 'search_read', [[
                ['model', '=', $model],
                ['module', 'in', array_values($modules)],
                ['name', 'in', array_values($xmlNames)],
            ]], [
                'fields' => ['module', 'name', 'res_id', 'model'],
                'limit' => max(1, count($modules) * count($xmlNames)),
            ]);
        } catch (RuntimeException) {
            return $rows;
        }

        return array_merge($rows, is_array($split) ? $split : []);
    }

    /**
     * @param  list<string>  $values
     */
    public function addSelectionValues(Company $company, string $model, string $field, array $values): int
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            $values
        ), static fn (string $value) => $value !== '')));
        if ($values === []) {
            return 0;
        }

        $fields = $this->execute($company, 'ir.model.fields', 'search_read', [[
            ['model', '=', $model],
            ['name', '=', $field],
        ]], ['fields' => ['id'], 'limit' => 1]);
        $fieldId = is_array($fields) ? (int) ($fields[0]['id'] ?? 0) : 0;
        if ($fieldId === 0) {
            return 0;
        }

        $rows = $this->execute($company, 'ir.model.fields.selection', 'search_read', [[
            ['field_id', '=', $fieldId],
        ]], ['fields' => ['value', 'sequence'], 'limit' => 1000]);
        $have = [];
        $sequence = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $have[trim((string) ($row['value'] ?? ''))] = true;
            $sequence = max($sequence, (int) ($row['sequence'] ?? 0));
        }

        $commands = [];
        foreach ($values as $value) {
            if (isset($have[$value])) {
                continue;
            }
            $sequence += 10;
            $commands[] = [0, 0, ['value' => $value, 'name' => $value, 'sequence' => $sequence]];
            $have[$value] = true;
        }
        if ($commands === []) {
            return 0;
        }

        try {
            $this->execute($company, 'ir.model.fields', 'write', [[$fieldId], [
                'selection_ids' => $commands,
            ]]);

            return count($commands);
        } catch (RuntimeException) {
            $added = 0;
            foreach ($commands as $command) {
                try {
                    $this->execute($company, 'ir.model.fields', 'write', [[$fieldId], [
                        'selection_ids' => [$command],
                    ]]);
                    $added++;
                } catch (RuntimeException) {
                }
            }

            return $added;
        }
    }

    private function rpc(Company $company, string $service, string $method, array $args, int $timeout = 25): mixed
    {
        $url = rtrim((string) $company->odoo_url, '/').'/jsonrpc';
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => 1,
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
        ];
        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withOptions(['verify' => ! $company->odoo_trust_tls])
                    ->post($url, $payload);
            } catch (ConnectionException) {
                throw new RuntimeException('Could not reach that Odoo address. Check the web address and try again.');
            }

            if ($response->status() !== 429 || $attempt === 3) {
                break;
            }

            $wait = (int) $response->header('Retry-After');
            sleep($wait >= 1 && $wait <= 15 ? $wait : $attempt * 2);
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Odoo is busy and asked this app to slow down. Wait a minute, then reload this page. The saved files can still be downloaded.');
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
