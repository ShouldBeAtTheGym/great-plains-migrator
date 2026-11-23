<?php

namespace App\Services;

use App\Models\Company;
use RuntimeException;

class CompanyMerge
{
    /** @var array<string, array{external: string, label: string, company: string, emit: bool}> */
    private array $customers = [];

    /** @var array<string, array{external: string, variant: string, company: string, emit: bool}> */
    private array $products = [];

    /** @var array<string, string>|null */
    private ?array $productCase = null;

    /** @var array<string, string>|null */
    private ?array $productSafe = null;

    /** @var array<string, array{external: string, company: string, emit: bool}> */
    private array $vendors = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, true> */
    private array $productBarcodes = [];

    private bool $loaded = false;

    public function __construct(private GpConnection $gp) {}

    /**
     * @return list<array{database: string, key: string, company: string, role: string}>
     */
    public function sources(): array
    {
        if (! function_exists('gp_company_sources')) {
            require_once base_path('legacy/includes/exports.php');
        }

        return gp_company_sources();
    }

    public function sourceForDatabase(string $database): array
    {
        foreach ($this->sources() as $source) {
            if (strcasecmp($source['database'], $database) === 0) {
                return $source;
            }
        }

        return ['database' => $database, 'key' => strtolower($database), 'company' => '', 'role' => 'branch'];
    }

    public function stamps(string $key): bool
    {
        if (in_array($key, ['payment_terms', 'units_of_measure', 'product_categories'], true)) {
            return false;
        }
        if (str_contains($key, 'crm_note') || str_contains($key, 'interaction') || str_contains($key, 'crm_event')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function keptCounts(): array
    {
        $counts = ['customers' => 0, 'products' => 0, 'vendors' => 0];
        foreach ($this->customers as $link) {
            if ($link['emit']) {
                $counts['customers']++;
            }
        }
        foreach ($this->products as $link) {
            if ($link['emit']) {
                $counts['products']++;
            }
        }
        foreach ($this->vendors as $link) {
            if ($link['emit']) {
                $counts['vendors']++;
            }
        }

        return $counts;
    }

    public function resetProductBarcodes(): void
    {
        $this->productBarcodes = [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function uniqueProductBarcode(array $row): array
    {
        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode === '') {
            return $row;
        }
        $candidate = $barcode;
        $suffix = 0;
        while (isset($this->productBarcodes[$candidate])) {
            $suffix++;
            $candidate = $barcode.'-'.$suffix;
        }
        $this->productBarcodes[$candidate] = true;
        $row['barcode'] = $candidate;

        return $row;
    }

    public function load(Company $company): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $customers = [];
        $products = [];
        $vendors = [];
        foreach ($this->sources() as $source) {
            try {
                $connection = $this->gp->connectDatabase($company, $source['database']);
            } catch (RuntimeException) {
                continue;
            }
            try {
                foreach ($this->gp->rows($connection, "SELECT RTRIM(CUSTNMBR) AS num, UPPER(LTRIM(RTRIM(CUSTNAME))) AS name, LEFT(REPLACE(REPLACE(UPPER(LTRIM(RTRIM(ZIP))), ' ', ''), '-', ''), 5) AS zip FROM dbo.RM00101") as $row) {
                    $customers[] = [
                        'database' => $source['key'],
                        'company' => $source['company'],
                        'number' => trim((string) ($row['num'] ?? '')),
                        'name' => trim((string) ($row['name'] ?? '')),
                        'zip' => trim((string) ($row['zip'] ?? '')),
                    ];
                }
                foreach ($this->gp->rows($connection, 'SELECT RTRIM(ITEMNMBR) AS num, UPPER(LTRIM(RTRIM(ITEMDESC))) AS name FROM dbo.IV00101') as $row) {
                    $products[] = [
                        'database' => $source['key'],
                        'company' => $source['company'],
                        'number' => trim((string) ($row['num'] ?? '')),
                        'name' => trim((string) ($row['name'] ?? '')),
                    ];
                }
                foreach ($this->gp->rows($connection, 'SELECT RTRIM(VENDORID) AS num, UPPER(LTRIM(RTRIM(VENDNAME))) AS name FROM dbo.PM00200') as $row) {
                    $vendors[] = [
                        'database' => $source['key'],
                        'company' => $source['company'],
                        'number' => trim((string) ($row['num'] ?? '')),
                        'name' => trim((string) ($row['name'] ?? '')),
                    ];
                }
            } catch (RuntimeException) {
                continue;
            } finally {
                if (is_resource($connection)) {
                    sqlsrv_close($connection);
                }
            }
        }
        $this->customers = $this->linksFromCustomers($customers);
        $this->products = $this->linksFromProducts($products);
        $this->productCase = null;
        $this->productSafe = null;
        $this->vendors = $this->linksFromVendors($vendors);
    }

    /**
     * @param  list<array{database: string, company: string, number: string, name: string, zip: string}>  $rows
     * @return array<string, array{external: string, label: string, company: string, emit: bool}>
     */
    public function linksFromCustomers(array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            if ($row['number'] !== '') {
                $present[$row['database']][$row['number']] = true;
            }
        }
        $buckets = [];
        foreach ($rows as $row) {
            if ($row['name'] === '' || strlen($row['zip']) < 5 || $row['number'] === '') {
                continue;
            }
            $buckets[$row['name'].'|'.$row['zip']][$row['database']][] = $row;
        }
        $paired = [];
        foreach ($buckets as $group) {
            $parent = $group['blu2'] ?? [];
            $branch = $group['blue'] ?? [];
            if (count($parent) !== 1 || count($branch) !== 1) {
                continue;
            }
            $left = $parent[0];
            $right = $branch[0];
            $external = 'partner_'.$this->safe($left['number']);
            $label = $left['number'].' / '.$right['number'];
            $paired[$left['database'].'|'.$left['number']] = ['external' => $external, 'label' => $label, 'company' => '', 'emit' => true];
            $paired[$right['database'].'|'.$right['number']] = ['external' => $external, 'label' => $label, 'company' => '', 'emit' => false];
        }
        $links = $paired;
        foreach ($rows as $row) {
            $key = $row['database'].'|'.$row['number'];
            if ($row['number'] === '' || isset($links[$key])) {
                continue;
            }
            $other = $row['database'] === 'blu2' ? 'blue' : 'blu2';
            $sharedNumber = isset($present[$other][$row['number']]);
            $links[$key] = [
                'external' => 'partner_'.($sharedNumber ? $row['database'].'_' : '').$this->safe($row['number']),
                'label' => $row['number'],
                'company' => $row['company'],
                'emit' => true,
            ];
        }

        return $links;
    }

    /**
     * @param  list<array{database: string, company: string, number: string, name: string}>  $rows
     * @return array<string, array{external: string, variant: string, company: string, emit: bool}>
     */
    public function linksFromProducts(array $rows): array
    {
        $byNumber = [];
        foreach ($rows as $row) {
            if ($row['number'] !== '') {
                $byNumber[$row['number']][$row['database']] = $row;
            }
        }
        $links = [];
        foreach ($rows as $row) {
            if ($row['number'] === '') {
                continue;
            }
            $parent = $byNumber[$row['number']]['blu2'] ?? null;
            $branch = $byNumber[$row['number']]['blue'] ?? null;
            $same = $parent && $branch && $parent['name'] !== '' && $parent['name'] === $branch['name'];
            if ($same) {
                $external = 'product_'.$this->safe($row['number']);
                $links['blu2|'.$row['number']] = ['external' => $external, 'variant' => 'product_variant_'.$this->safe($row['number']), 'company' => '', 'emit' => true];
                $links['blue|'.$row['number']] = ['external' => $external, 'variant' => 'product_variant_'.$this->safe($row['number']), 'company' => '', 'emit' => false];

                continue;
            }
            $token = ($parent && $branch ? $row['database'].'_' : '').$this->safe($row['number']);
            $links[$row['database'].'|'.$row['number']] = [
                'external' => 'product_'.$token,
                'variant' => 'product_variant_'.$token,
                'company' => $row['company'],
                'emit' => true,
            ];
        }

        return $links;
    }

    /**
     * @param  list<array{database: string, company: string, number: string, name: string}>  $rows
     * @return array<string, array{external: string, company: string, emit: bool}>
     */
    public function linksFromVendors(array $rows): array
    {
        $byNumber = [];
        foreach ($rows as $row) {
            if ($row['number'] !== '') {
                $byNumber[$row['number']][$row['database']] = $row;
            }
        }
        $links = [];
        foreach ($rows as $row) {
            if ($row['number'] === '') {
                continue;
            }
            $both = isset($byNumber[$row['number']]['blu2'], $byNumber[$row['number']]['blue']);
            if ($both) {
                $external = 'vendor_'.$this->safe($row['number']);
                $links['blu2|'.$row['number']] = ['external' => $external, 'company' => '', 'emit' => true];
                $links['blue|'.$row['number']] = ['external' => $external, 'company' => '', 'emit' => false];

                continue;
            }
            $links[$row['database'].'|'.$row['number']] = [
                'external' => 'vendor_'.$this->safe($row['number']),
                'company' => $row['company'],
                'emit' => true,
            ];
        }

        return $links;
    }

    public function canonicalPartnerExternal(array $source, string $name): string
    {
        $number = preg_replace('/^__import__\./', '', trim($name)) ?? '';
        $number = preg_replace('/^partner_/', '', $number) ?? '';

        return $this->partnerExternal($source, str_replace('_', ' ', $number));
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    public function apply(string $key, array $row, array $source): ?array
    {
        if ($key === 'customers') {
            $link = $this->customers[$source['key'].'|'.trim((string) ($row['x_gp_customer_number'] ?? ''))] ?? null;
            if ($link !== null && ! $link['emit']) {
                return null;
            }
            if ($link !== null) {
                $row['id'] = $link['external'];
                $row['x_gp_customer_number'] = $link['label'];
                $row['company_id'] = $link['company'];
            } else {
                $row['company_id'] = $source['company'];
            }

            return $row;
        }

        $companyId = $source['company'];
        if ($key === 'products') {
            $item = trim((string) preg_replace('/^product_/', '', (string) ($row['id'] ?? '')));
            $companyId = $this->products[$source['key'].'|'.$item]['company'] ?? $source['company'];
        }
        if ($key === 'vendors') {
            $item = trim((string) preg_replace('/^vendor_/', '', (string) ($row['id'] ?? '')));
            $companyId = $this->vendors[$source['key'].'|'.$item]['company'] ?? $source['company'];
        }

        foreach ($row as $column => $value) {
            if (is_string($value) || is_numeric($value)) {
                $row[$column] = $this->rewrite((string) $column, (string) $value, $source, $key);
            }
        }

        if ($this->duplicate($key, $row)) {
            return null;
        }
        if ($key === 'products') {
            $row = $this->uniqueProductBarcode($row);
        }

        if ($key === 'warehouses') {
            $row['code'] = $this->prefixSite($source, (string) ($row['code'] ?? ''));
        }
        if ($key === 'inventory_on_hand' && isset($row['gp_location'])) {
            $row['gp_location'] = $this->prefixLocationPath($source, (string) $row['gp_location']);
        }
        if (in_array($key, ['stock_locations', 'inventory_on_hand'], true) && isset($row['location_id'])) {
            $site = strstr((string) $row['location_id'], '/', true);
            if ($key === 'stock_locations' && is_string($site) && $site !== '' && str_starts_with((string) ($row['barcode'] ?? ''), $site.'-')) {
                $row['barcode'] = $this->prefixSite($source, $site).substr((string) $row['barcode'], strlen($site));
            }
            $row['location_id'] = $this->prefixLocationPath($source, (string) $row['location_id']);
        }

        if (in_array($key, ['warehouses', 'stock_locations'], true)) {
            $row['company_id/id'] = (string) ($source['company_xmlid'] ?? '');
            unset($row['company_id']);

            return $row;
        }
        if ($this->stamps($key)) {
            $row['company_id'] = in_array($key, ['customer_addresses', 'vendor_addresses'], true)
                ? $this->companyFor($key, $row, $source)
                : $companyId;
        }

        return $row;
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function rewrite(string $column, string $value, array $source, string $key): string
    {
        if ($value === '') {
            return $value;
        }
        $value = $this->rewriteWhole($value, '/^(__import__\.)?partner_(.+)$/', function (string $prefix, string $number) use ($source): string {
            $external = $this->partnerExternal($source, $number);

            return $prefix === '' ? $external : '__import__.'.$external;
        });
        $value = $this->rewriteWhole($value, '/^(__import__\.)?vendor_(.+)$/', function (string $prefix, string $number) use ($source): string {
            $external = $this->vendorExternal($source, $number);

            return $prefix === '' ? $external : '__import__.'.$external;
        });
        $value = $this->rewriteWhole($value, '/^(__import__\.)?product_variant_(.+)$/', function (string $prefix, string $number) use ($source): string {
            $external = $this->productVariant($source, $number);

            return $prefix === '' ? $external : '__import__.'.$external;
        });
        $value = $this->rewriteWhole($value, '/^(__import__\.)product_(?!variant_|categ_)(.+)$/', function (string $prefix, string $number) use ($source): string {
            return '__import__.'.$this->productExternal($source, $number);
        });
        $value = $this->rewriteWhole($value, '/^(__import__\.)pricelist_(.+)$/', function (string $prefix, string $number) use ($source): string {
            if (str_starts_with($number, $source['key'].'_')) {
                return '__import__.pricelist_'.$number;
            }

            return '__import__.pricelist_'.$source['key'].'_'.$number;
        });
        $value = $this->rewriteWhole($value, '/^(__import__\.)?stock_bin_(.+)$/', function (string $prefix, string $rest) use ($source): string {
            $external = 'stock_'.$source['key'].'_bin_'.$rest;

            return $prefix === '' ? $external : '__import__.'.$external;
        });
        if ($column === 'id' && $key === 'products') {
            return $this->productExternal($source, preg_replace('/^product_/', '', $value) ?? $value);
        }
        if ($column === 'id' && $key === 'vendors') {
            return $this->vendorExternal($source, preg_replace('/^vendor_/', '', $value) ?? $value);
        }
        if ($column === 'id' && ! $this->keepsId($key) && ! str_contains($value, '_'.$source['key'].'_')) {
            $pos = strpos($value, '_');
            $value = $pos === false
                ? $source['key'].'_'.$value
                : substr($value, 0, $pos + 1).$source['key'].'_'.substr($value, $pos + 1);
        }

        return $value;
    }

    /**
     * @param  array{code_prefix?: string}  $source
     */
    private function prefixSite(array $source, string $code): string
    {
        $prefix = (string) ($source['code_prefix'] ?? '');
        if ($prefix === '' || $code === '' || str_starts_with($code, $prefix)) {
            return $code;
        }

        return $prefix.$code;
    }

    /**
     * @param  array{code_prefix?: string}  $source
     */
    private function prefixLocationPath(array $source, string $path): string
    {
        $slash = strpos($path, '/');
        if ($slash === false) {
            return $this->prefixSite($source, $path);
        }

        return $this->prefixSite($source, substr($path, 0, $slash)).substr($path, $slash);
    }

    private function keepsId(string $key): bool
    {
        return in_array($key, ['customers', 'vendors', 'products', 'payment_terms', 'units_of_measure', 'product_categories', 'customer_addresses', 'vendor_addresses', 'customer_price_assign'], true);
    }

    private function duplicate(string $key, array $row): bool
    {
        if (in_array($key, ['vendors', 'products', 'payment_terms', 'units_of_measure', 'product_categories'], true)) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                return false;
            }
            if (isset($this->seen[$key][$id])) {
                return true;
            }
            $this->seen[$key][$id] = true;

            return false;
        }
        if (! in_array($key, ['customer_addresses', 'vendor_addresses'], true)) {
            return false;
        }
        $street = strtoupper(trim((string) ($row['street'] ?? '')));
        $zip = substr((string) preg_replace('/\D/', '', (string) ($row['zip'] ?? '')), 0, 5);
        if ($street === '') {
            return false;
        }
        $signature = trim((string) ($row['parent_id/id'] ?? '')).'|'.$street.'|'.$zip;
        if (isset($this->seen[$key][$signature])) {
            return true;
        }
        $this->seen[$key][$signature] = true;

        return false;
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function companyFor(string $key, array $row, array $source): string
    {
        if ($key === 'products') {
            $item = preg_replace('/^product_/', '', trim((string) ($row['id'] ?? ''))) ?? '';

            return $this->products[$source['key'].'|'.$item]['company'] ?? $source['company'];
        }
        if ($key === 'vendors') {
            $item = preg_replace('/^vendor_/', '', trim((string) ($row['id'] ?? ''))) ?? '';

            return $this->vendors[$source['key'].'|'.$item]['company'] ?? $source['company'];
        }
        if ($key === 'customer_addresses') {
            $parent = preg_replace('/^__import__\./', '', trim((string) ($row['parent_id/id'] ?? ''))) ?? '';
            foreach ($this->customers as $link) {
                if ($link['external'] === $parent) {
                    return $link['company'];
                }
            }
        }
        if ($key === 'vendor_addresses') {
            $parent = preg_replace('/^__import__\./', '', trim((string) ($row['parent_id/id'] ?? ''))) ?? '';
            foreach ($this->vendors as $link) {
                if ($link['external'] === $parent) {
                    return $link['company'];
                }
            }
        }

        return $source['company'];
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function partnerExternal(array $source, string $number): string
    {
        $number = trim($number);
        $link = $this->customers[$source['key'].'|'.$number] ?? $this->customers[$source['key'].'|'.str_replace('_', ' ', $number)] ?? null;
        if ($link === null) {
            $number = trim($number);

            return 'partner_'.$this->safe($number);
        }

        return $link['external'];
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function vendorExternal(array $source, string $number): string
    {
        $number = trim($number);

        return $this->vendors[$source['key'].'|'.$number]['external'] ?? ('vendor_'.$this->safe($number));
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function productExternal(array $source, string $number): string
    {
        return $this->productLink($source, $number)['external'] ?? ('product_'.$this->safe($number));
    }

    /**
     * @param  array{database: string, key: string, company: string, role: string}  $source
     */
    private function productVariant(array $source, string $number): string
    {
        return $this->productLink($source, $number)['variant'] ?? ('product_variant_'.$this->safe($number));
    }

    /**
     * Bin counts sometimes spell an item number in a different case than the product card.
     *
     * @param  array{database: string, key: string, company: string, role: string}  $source
     * @return array{external: string, variant: string, company: string, emit: bool}|null
     */
    private function productLink(array $source, string $number): ?array
    {
        $number = trim($number);
        $key = $source['key'].'|'.$number;
        if (isset($this->products[$key])) {
            return $this->products[$key];
        }
        $this->indexProducts();
        $actual = $this->productCase[strtolower($key)] ?? $this->productSafe[strtolower($source['key'].'|'.$this->safe($number))] ?? null;

        return $actual !== null ? $this->products[$actual] : null;
    }

    private function indexProducts(): void
    {
        if ($this->productCase !== null) {
            return;
        }
        $this->productCase = [];
        $this->productSafe = [];
        $collisions = [];
        foreach ($this->products as $mapKey => $link) {
            $this->productCase[strtolower($mapKey)] = $mapKey;
            $split = strpos($mapKey, '|');
            $item = substr($mapKey, $split + 1);
            $sourceKey = substr($mapKey, 0, $split);
            foreach (array_unique([$this->safe($item), str_replace('.', '_', $item)]) as $token) {
                $safeKey = strtolower($sourceKey.'|'.$token);
                if (isset($this->productSafe[$safeKey]) && $this->productSafe[$safeKey] !== $mapKey) {
                    $collisions[$safeKey] = true;
                }
                $this->productSafe[$safeKey] = $mapKey;
            }
        }
        foreach (array_keys($collisions) as $safeKey) {
            unset($this->productSafe[$safeKey]);
        }
    }

    private function rewriteWhole(string $value, string $pattern, callable $replace): string
    {
        if (! preg_match($pattern, $value, $match)) {
            return $value;
        }

        return $replace($match[1] ?? '', $match[2]);
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($value)) ?? '';

        return $value === '' ? 'blank' : $value;
    }
}
