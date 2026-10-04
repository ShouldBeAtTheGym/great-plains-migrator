<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ConversionStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConversionPlanner
{
    public function __construct(
        private ExportCatalog $catalog,
        private GpConnection $gp,
        private OdooClient $odoo,
    ) {}

    public function syncSteps(Company $company): void
    {
        $sort = 0;
        foreach ($this->catalog->all() as $export) {
            ConversionStep::query()->updateOrCreate(
                ['company_id' => $company->id, 'export_key' => $export['key']],
                ['sort' => $sort]
            );
            $sort++;
        }
    }

    public function counts(Company $company): array
    {
        return Cache::remember('gp-counts-'.$company->id, 120, function () use ($company) {
            $connection = $this->gp->connect($company);
            $counts = [];
            foreach ($this->catalog->all() as $export) {
                try {
                    $rows = $this->gp->rows($connection, $export['count_sql'], 1);
                    $counts[$export['key']] = isset($rows[0]['c']) ? (int) $rows[0]['c'] : null;
                } catch (RuntimeException) {
                    $counts[$export['key']] = null;
                }
            }
            sqlsrv_close($connection);

            return $counts;
        });
    }

    public function guide(string $key, string $odooLabel): array
    {
        $screens = [
            'payment_terms' => 'Accounting → Configuration → Payment Terms',
            'product_categories' => 'Inventory → Configuration → Product Categories',
            'units_of_measure' => 'Inventory → Configuration → Units & Packagings',
            'stock_locations' => 'Inventory → Configuration → Locations',
            'customers' => 'Contacts',
            'customer_addresses' => 'Contacts',
            'vendors' => 'Contacts',
            'vendor_addresses' => 'Contacts',
            'products' => 'Sales → Products → Products',
            'customer_price_lists' => 'Sales → Products → Pricelists',
            'customer_price_assign' => 'Contacts, not Pricelists',
            'inventory_on_hand' => 'Inventory → Operations → Physical Inventory',
        ];

        $screen = $screens[$key] ?? match (true) {
            str_contains($key, 'sales_orders') => 'Sales → Orders → Orders',
            str_contains($key, 'quotes') => 'Sales → Orders → Quotations',
            str_contains($key, 'returns') => 'Accounting → Customers → Credit Notes',
            str_contains($key, 'invoices') => 'Accounting → Customers → Invoices',
            str_contains($key, 'purchase_orders') => 'Purchase → Orders → Purchase Orders',
            str_contains($key, 'vendor_bill') => 'Accounting → Vendors → Bills',
            default => $odooLabel,
        };

        return [
            'screen' => $screen,
            'models' => $this->models($key),
            'lines' => str_ends_with($key, '_lines') || $key === 'customer_price_lists',
            'line_field' => $this->lineField($key),
        ];
    }

    public function removeImported(Company $company, array $export): array
    {
        $empty = ['deleted' => 0, 'archived' => 0, 'remaining' => 0];
        if (($export['key'] ?? '') === 'customer_price_assign') {
            return $empty + [
                'message' => 'This file only assigns a pricelist to a customer. It does not delete customers.',
            ];
        }

        try {
            $connection = $this->gp->connect($company);
            try {
                $countRows = $this->gp->rows($connection, $export['count_sql'], 1);
                $count = (int) ($countRows[0]['c'] ?? 0);
                if ($count > 5000) {
                    return $empty + [
                        'remaining' => $count,
                        'message' => 'This file has '.number_format($count).' rows, which is too many to delete through the API. Remove them on the Odoo screen, then import the file again.',
                    ];
                }
                $ids = $count === 0 ? [] : $this->catalog->externalIds($connection, $export, $count);
            } finally {
                sqlsrv_close($connection);
            }
        } catch (RuntimeException $exception) {
            return $empty + [
                'message' => 'Odoo was not changed because Great Plains could not be read. '.$exception->getMessage(),
            ];
        }

        if ($ids === []) {
            return $empty + [
                'message' => 'Odoo had no records from this file to delete.',
            ];
        }

        usort($ids, function (string $left, string $right): int {
            $leftChild = str_contains($left, '__');
            $rightChild = str_contains($right, '__');
            if ($leftChild !== $rightChild) {
                return $leftChild ? -1 : 1;
            }

            return strlen($right) <=> strlen($left);
        });

        $models = $this->models($export['key']);
        $byModel = [];
        foreach (array_chunk($ids, 80) as $batch) {
            foreach ($models as $model) {
                try {
                    $rows = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
                        ['model', '=', $model],
                        ['name', 'in', $batch],
                    ]], [
                        'fields' => ['res_id', 'model'],
                        'limit' => count($batch),
                    ]);
                } catch (RuntimeException) {
                    continue;
                }
                if (! is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    $resId = (int) ($row['res_id'] ?? 0);
                    if ($resId > 0) {
                        $byModel[$model][$resId] = $resId;
                    }
                }
            }
        }

        $deleted = 0;
        $archived = 0;
        $remaining = 0;
        foreach ($byModel as $model => $resIds) {
            [$gone, $failed] = $this->unlinkIds($company, $model, array_values($resIds));
            $deleted += count($gone);
            $still = $failed;
            if ($still !== [] && $this->canArchive($company, $model)) {
                $left = [];
                foreach ($still as $resId) {
                    try {
                        $this->odoo->execute($company, $model, 'write', [[$resId], ['active' => false]]);
                        $archived++;
                    } catch (RuntimeException) {
                        $left[] = $resId;
                    }
                }
                $still = $left;
            }
            $remaining += count($still);
        }

        if ($deleted === 0 && $archived === 0 && $remaining === 0) {
            $message = 'Odoo had no records from this file to delete.';
        } else {
            $message = 'Odoo deleted '.$deleted.' records';
            if ($archived > 0) {
                $message .= ' and archived '.$archived;
            }
            $message .= '.';
            if ($remaining > 0) {
                $message .= ' '.$remaining.' records are still there. Delete them on the Odoo screen. If Odoo refuses, archive them when Archive is offered. Posted invoices must be cancelled first. A category that still has products must have those products moved off it before it can be deleted. Categories cannot be archived.';
            }
        }

        return compact('deleted', 'archived', 'remaining') + ['message' => $message];
    }

    public function confirm(Company $company, array $export): array
    {
        if (($export['key'] ?? '') === 'customer_price_assign') {
            return $this->confirmAssignedPricelist($company, $export);
        }

        $connection = $this->gp->connect($company);
        try {
            $countRows = $this->gp->rows($connection, $export['count_sql'], 1);
            $gpCount = isset($countRows[0]['c']) ? (int) $countRows[0]['c'] : 0;
            if ($gpCount === 0) {
                return [
                    'ok' => true,
                    'checked' => 0,
                    'found' => 0,
                    'message' => 'Great Plains has no rows for this file, so there is nothing to import.',
                ];
            }

            $ids = $this->catalog->externalIds($connection, $export, min(5, $gpCount));
        } finally {
            sqlsrv_close($connection);
        }

        if ($ids === []) {
            return [
                'ok' => false,
                'checked' => 0,
                'found' => 0,
                'message' => 'The file has rows, but this app could not read sample record ids from Great Plains.',
            ];
        }

        $guide = $this->guide($export['key'], $export['odoo']);
        $found = $this->odoo->findExternalIds($company, $guide['models'], $ids);
        $foundCount = count(array_intersect($ids, array_keys($found)));

        if ($foundCount !== count($ids)) {
            return [
                'ok' => false,
                'checked' => count($ids),
                'found' => $foundCount,
                'message' => 'Odoo has '.$foundCount.' of '.count($ids).' sample records from this file. Import the CSV, then confirm again. Columns that start with gp_ must be set to Do not import.',
            ];
        }

        if ($guide['lines'] && $guide['line_field']) {
            $empty = 0;
            foreach ($found as $row) {
                $lines = $this->odoo->execute(
                    $company,
                    $row['model'],
                    'read',
                    [[$row['res_id']]],
                    ['fields' => [$guide['line_field']]]
                );
                $idsOnRecord = is_array($lines[0][$guide['line_field']] ?? null) ? $lines[0][$guide['line_field']] : [];
                if ($idsOnRecord === []) {
                    $empty++;
                }
            }
            if ($empty > 0) {
                return [
                    'ok' => false,
                    'checked' => count($ids),
                    'found' => $foundCount,
                    'message' => 'Odoo has the documents, but '.$empty.' of them have no lines. Import the lines file, then confirm again.',
                ];
            }
        }

        return [
            'ok' => true,
            'checked' => count($ids),
            'found' => $foundCount,
            'message' => 'Odoo has all '.$foundCount.' sample records from this file.',
        ];
    }

    public function preflight(Company $company): array
    {
        $checks = [];
        $checks[] = $this->check(
            'Application tables',
            Schema::hasTable('companies') && Schema::hasTable('conversion_steps') && Schema::hasTable('users') && Schema::hasTable('personal_access_tokens'),
            'The MySQL tables for companies, steps, users, and API tokens are ready.',
            'The application tables are missing. Open the product page and enter the MySQL connection so the tables can be created.'
        );
        $checks[] = $this->check(
            'SQL Server extension',
            function_exists('sqlsrv_connect'),
            'PHP can talk to Microsoft SQL Server.',
            'Install the PHP sqlsrv extension on this server.'
        );

        $gpOk = false;
        $gpDetail = 'Not checked yet.';
        try {
            $connection = $this->gp->connect($company);
            $gpOk = true;
            $gpDetail = 'Connected to '.$company->gp_database.' on '.$company->gp_server.'.';
            sqlsrv_close($connection);
        } catch (RuntimeException $exception) {
            $gpDetail = $exception->getMessage();
        }
        $checks[] = $this->check('Great Plains connection', $gpOk, $gpDetail, 'Go back to the Great Plains page and run the test again.');

        $salespadOk = (bool) $company->salespad_installed;
        $report = $company->salespad_report ?? [];
        $checks[] = $this->check(
            'SalesPad views and user fields',
            $salespadOk,
            $salespadOk
                ? ($report['spv_count'] ?? 0).' spv views and '.($report['spx_count'] ?? 0).' spx user-field tables.'
                : 'SalesPad views are missing: '.implode(', ', $report['missing_views'] ?? []),
            'The conversion reads spv* views. Those views are created by SalesPad. Install SalesPad for this company database, then test Great Plains again.'
        );

        $odooOk = false;
        $odooDetail = 'Not checked yet.';
        try {
            $version = $this->odoo->version($company);
            $uid = $this->odoo->authenticate($company);
            $odooOk = $uid > 0;
            $odooDetail = 'Signed in to Odoo '.(string) ($version['server_version'] ?? '').'.';
        } catch (RuntimeException $exception) {
            $odooDetail = $exception->getMessage();
        }
        $checks[] = $this->check('Odoo API sign-in', $odooOk, $odooDetail, 'Go back to the Odoo page. Use an API key from Odoo → Preferences → Account Security → API Keys.');

        $readOk = false;
        $readDetail = 'Not checked yet.';
        if ($odooOk) {
            try {
                $this->odoo->execute($company, 'ir.model.data', 'search_count', [[]]);
                $readOk = true;
                $readDetail = 'This user can read external ids, which is how a finished import is confirmed.';
            } catch (RuntimeException $exception) {
                $readDetail = $exception->getMessage();
            }
        }
        $checks[] = $this->check(
            'Odoo import check',
            $readOk,
            $readDetail,
            'Use an Odoo administrator, or a user who can read Technical → External Identifiers.'
        );

        $passed = collect($checks)->every(fn (array $check) => $check['ok']);

        return [
            'passed' => $passed,
            'checks' => $checks,
        ];
    }

    private function models(string $key): array
    {
        return match (true) {
            $key === 'payment_terms' => ['account.payment.term'],
            $key === 'product_categories' => ['product.category'],
            $key === 'units_of_measure' => ['uom.uom', 'product.uom'],
            $key === 'stock_locations' => ['stock.location'],
            $key === 'products' => ['product.template', 'product.product'],
            $key === 'inventory_on_hand' => ['stock.quant'],
            $key === 'customers' || $key === 'customer_addresses' || $key === 'vendors' || $key === 'vendor_addresses' || $key === 'customer_price_assign' => ['res.partner'],
            $key === 'customer_price_lists' => ['product.pricelist'],
            str_contains($key, 'sales_orders') || str_contains($key, 'quotes') => ['sale.order'],
            str_contains($key, 'purchase_orders') => ['purchase.order'],
            default => ['account.move'],
        };
    }

    private function unlinkIds(Company $company, string $model, array $resIds): array
    {
        $deleted = [];
        $failed = [];
        foreach (array_chunk($resIds, 40) as $chunk) {
            try {
                $this->odoo->execute($company, $model, 'unlink', [$chunk]);
                $deleted = array_merge($deleted, $chunk);
            } catch (RuntimeException) {
                foreach ($chunk as $resId) {
                    try {
                        $this->odoo->execute($company, $model, 'unlink', [[$resId]]);
                        $deleted[] = $resId;
                    } catch (RuntimeException) {
                        $failed[] = $resId;
                    }
                }
            }
        }

        return [$deleted, $failed];
    }

    private function canArchive(Company $company, string $model): bool
    {
        try {
            $fields = $this->odoo->execute($company, $model, 'fields_get', [['active'], ['type']]);
        } catch (RuntimeException) {
            return false;
        }

        return is_array($fields) && isset($fields['active']);
    }

    private function confirmAssignedPricelist(Company $company, array $export): array
    {
        $connection = $this->gp->connect($company);
        try {
            $ids = $this->catalog->externalIds($connection, $export, 5);
        } finally {
            sqlsrv_close($connection);
        }

        if ($ids === []) {
            return [
                'ok' => false,
                'checked' => 0,
                'found' => 0,
                'message' => 'The file has rows, but this app could not read sample customers from Great Plains.',
            ];
        }

        $found = $this->odoo->findExternalIds($company, ['res.partner'], $ids);
        $foundCount = count(array_intersect($ids, array_keys($found)));
        if ($foundCount !== count($ids)) {
            return [
                'ok' => false,
                'checked' => count($ids),
                'found' => $foundCount,
                'message' => 'Odoo has '.$foundCount.' of '.count($ids).' sample customers. Import the customers first, then this file.',
            ];
        }

        $missing = 0;
        foreach ($found as $row) {
            $read = $this->odoo->execute($company, 'res.partner', 'read', [[(int) $row['res_id']]], [
                'fields' => ['specific_property_product_pricelist'],
            ]);
            $pricelist = is_array($read) ? ($read[0]['specific_property_product_pricelist'] ?? false) : false;
            if (! $pricelist) {
                $missing++;
            }
        }

        if ($missing > 0) {
            return [
                'ok' => false,
                'checked' => count($ids),
                'found' => count($ids) - $missing,
                'message' => 'Odoo has the customers, but '.$missing.' of the sample do not have an assigned pricelist. Import this file, then confirm again.',
            ];
        }

        return [
            'ok' => true,
            'checked' => count($ids),
            'found' => count($ids),
            'message' => 'Odoo has the assigned pricelist on all '.count($ids).' sample customers.',
        ];
    }

    private function lineField(string $key): ?string
    {
        if ($key === 'customer_price_lists') {
            return 'item_ids';
        }
        if (! str_ends_with($key, '_lines')) {
            return null;
        }
        if (str_contains($key, 'purchase_orders')) {
            return 'order_line';
        }
        if (str_contains($key, 'sales_orders') || str_contains($key, 'quotes')) {
            return 'order_line';
        }

        return 'invoice_line_ids';
    }

    private function check(string $name, bool $ok, string $detail, string $help): array
    {
        return compact('name', 'ok', 'detail', 'help');
    }
}
