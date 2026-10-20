<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SalespersonLink;
use RuntimeException;

class ExportCatalog
{
    public const PART_ROWS = 30000;

    private bool $booted = false;

    public function __construct(private OdooClient $odoo) {}

    public function all(): array
    {
        $this->boot();

        return export_catalog();
    }

    public function find(string $key): ?array
    {
        $this->boot();

        return export_by_key($key);
    }

    public function prepare(Company $company, array $export): array
    {
        if (($export['key'] ?? '') === 'customers' && class_exists(\App\Models\SalespadUserField::class)) {
            $chosen = \App\Models\SalespadUserField::query()
                ->where('company_id', $company->id)
                ->where('model_key', 'customers')
                ->where('selected', true)
                ->whereNotNull('odoo_field')
                ->get();
            foreach ($chosen as $field) {
                $export['fields'][$field->odoo_field] = $field->label;
            }

        }

        $importCompanyName = trim((string) $company->odoo_import_company_name);
        if (in_array(($export['key'] ?? ''), ['customers', 'customer_price_lists'], true)
            && (int) $company->odoo_import_company_id > 0
            && $importCompanyName !== '') {
            $export['extra']['company_id'] = $importCompanyName;
            $export['fields']['company_id'] = 'Company';
        }

        $key = (string) ($export['key'] ?? '');
        $export = $this->applySalesperson($company, $export, $key);
        if (str_ends_with($key, '_headers') && (str_contains($key, 'sales_orders') || str_contains($key, 'quotes'))) {
            $warehouse = $this->warehouseStock($company);
            $export['extra']['warehouse_id'] = $warehouse['name'];
            $export['fields']['warehouse_id'] = 'Warehouse';
        }

        if ($key !== 'stock_locations') {
            $export = $this->matchImportedCategories($company, $this->bindItemUserFields($company, $export));
            if ($key === 'inventory_on_hand') {
                $this->ensureInventoryVariantIds($company);
            }

            return $export;
        }

        $parent = $this->warehouseStock($company);
        $export['extra'] = [
            'company_id/id' => $this->externalId($company, 'res.company', $parent['company_id'], 'company_'.$parent['company_id']),
        ];

        return $this->matchImportedCategories($company, $this->bindItemUserFields($company, $export));
    }

    public function matchImportedCategories(Company $company, array $export): array
    {
        if (($export['key'] ?? '') !== 'products') {
            return $export;
        }

        $rows = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
            ['model', '=', 'product.category'],
            ['module', '=', '__import__'],
            ['name', 'like', 'product_categ_%'],
        ]], [
            'fields' => ['name'],
            'limit' => 5000,
        ]);
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $map[strtolower($name)] = $name;
            }
        }
        $export['category_id_map'] = $map;

        return $export;
    }

    public function variantExternalName(string $templateName): ?string
    {
        if (! str_starts_with($templateName, 'product_')
            || str_starts_with($templateName, 'product_categ_')
            || str_starts_with($templateName, 'product_variant_')) {
            return null;
        }

        return 'product_variant_'.substr($templateName, strlen('product_'));
    }

    public function ensureInventoryVariantIds(Company $company): int
    {
        $rows = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
            ['model', '=', 'product.template'],
            ['module', '=', '__import__'],
            ['name', 'like', 'product_%'],
        ]], [
            'fields' => ['name', 'res_id'],
            'limit' => 10000,
            'context' => ['active_test' => false],
        ]);
        $templates = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->variantExternalName((string) ($row['name'] ?? ''));
            $templateId = (int) ($row['res_id'] ?? 0);
            if ($name !== null && $templateId > 0) {
                $templates[$templateId] = $name;
            }
        }
        if ($templates === []) {
            return 0;
        }

        $existing = [];
        foreach (array_chunk(array_values($templates), 200) as $chunk) {
            $found = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
                ['model', '=', 'product.product'],
                ['module', '=', '__import__'],
                ['name', 'in', $chunk],
            ]], [
                'fields' => ['name'],
                'limit' => count($chunk),
                'context' => ['active_test' => false],
            ]);
            foreach (is_array($found) ? $found : [] as $row) {
                if (is_array($row)) {
                    $existing[(string) ($row['name'] ?? '')] = true;
                }
            }
        }

        $missing = [];
        foreach ($templates as $templateId => $name) {
            if (! isset($existing[$name])) {
                $missing[$templateId] = $name;
            }
        }
        if ($missing === []) {
            return 0;
        }

        $created = 0;
        foreach (array_chunk(array_keys($missing), 200) as $templateIds) {
            $records = $this->odoo->execute($company, 'product.template', 'read', [$templateIds], [
                'fields' => ['product_variant_ids'],
                'context' => ['active_test' => false],
            ]);
            $batch = [];
            foreach (is_array($records) ? $records : [] as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $templateId = (int) ($record['id'] ?? 0);
                $variantIds = $record['product_variant_ids'] ?? [];
                $variantId = (int) (is_array($variantIds) ? ($variantIds[0] ?? 0) : 0);
                $name = $missing[$templateId] ?? null;
                if ($variantId === 0 || ! is_string($name)) {
                    continue;
                }
                $batch[] = [
                    'module' => '__import__',
                    'name' => $name,
                    'model' => 'product.product',
                    'res_id' => $variantId,
                    'noupdate' => true,
                ];
            }
            foreach (array_chunk($batch, 80) as $chunk) {
                $this->odoo->execute($company, 'ir.model.data', 'create', [$chunk]);
                $created += count($chunk);
            }
        }

        return $created;
    }

    public function bindItemUserFields(Company $company, array $export, $connection = null): array
    {
        $needs = str_contains((string) ($export['sql'] ?? ''), '[[')
            || str_contains((string) ($export['count_sql'] ?? ''), '[[');
        if (! $needs) {
            return $export;
        }

        $opened = false;
        if ($connection === null && str_contains((string) ($export['sql'] ?? ''), '[[customer_email]]')) {
            $report = $company->salespad_report ?? [];
            $savedEmail = $report['user_field_catalog']['standards']['spvCustomer']['customer_email'] ?? null;
            if (! is_string($savedEmail) || $savedEmail === '') {
                try {
                    $connection = app(GpConnection::class)->connect($company);
                    $opened = is_resource($connection);
                } catch (\Throwable) {
                    $connection = null;
                }
            }
        }

        try {
            $catalog = $this->userFieldCatalog($company, $connection);
            $finder = app(UserFieldCatalog::class);
            $columns = [
                'category' => $finder->find($catalog, 'spxItemMaster', ['category'], ['sub'])
                    ?? $finder->standard($catalog, 'spvItemMaster', 'Item_Generic_Description'),
                'subcategory' => $finder->find($catalog, 'spxItemMaster', ['subcategory']),
                'barcode' => $finder->find($catalog, 'spxItemMaster', ['barcode']),
                'brand' => $finder->find($catalog, 'spxItemMaster', ['brand', 'brands']),
                'customer_email' => $finder->standard($catalog, 'spvCustomer', 'customer_email')
                    ?? $this->firstExistingColumn($connection, 'spvCustomer', ['Primary_Email', 'Email_To', 'Email']),
            ];

            if (($export['key'] ?? '') === 'product_categories' && ($columns['category'] ?? null) === null) {
                throw new RuntimeException('This install has no Category field on the item user table (spxItemMaster).');
            }

            foreach (['sql', 'count_sql', 'problem_sql'] as $name) {
                if (! empty($export[$name]) && is_string($export[$name])) {
                    $export[$name] = $this->fillItemColumns($export[$name], $columns);
                }
            }

            return $export;
        } finally {
            if ($opened) {
                sqlsrv_close($connection);
            }
        }
    }

    public function open($connection, array $export)
    {
        $this->boot();
        $statement = export_query($connection, $export['sql']);
        if ($statement === false) {
            throw new RuntimeException(app(GpConnection::class)->errorText('The export query failed.'));
        }

        return $statement;
    }

    public function columns($connection, array $export): array
    {
        $statement = sqlsrv_query(
            $connection,
            'EXEC sp_describe_first_result_set @tsql = ?',
            [$export['sql']]
        );

        if ($statement === false) {
            return [];
        }

        $columns = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        foreach (array_keys($export['extra'] ?? []) as $name) {
            $columns[] = $name;
        }

        return $columns;
    }

    public function sample($connection, array $export, int $limit = 3): array
    {
        $this->boot();
        $sql = 'SELECT TOP '.$limit.' * FROM ('.$export['sql'].') AS preview_src';
        $statement = export_query($connection, $sql);
        if ($statement === false) {
            return [];
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $line = [];
            foreach ($row as $column => $value) {
                $line[$column] = $this->categoryExternalId($export, (string) $column, (string) csv_value($value, (string) $column));
            }
            foreach ($export['extra'] ?? [] as $column => $value) {
                $line[$column] = (string) csv_value($value, (string) $column);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    public function externalIds($connection, array $export, int $limit = 5): array
    {
        $this->boot();
        $sql = 'SELECT TOP '.$limit.' id FROM ('.$export['sql'].') AS id_src';
        $statement = export_query($connection, $sql);
        if ($statement === false) {
            return [];
        }

        $ids = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $id = (string) csv_value($row['id'] ?? '', 'id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function stream($connection, array $export, ?callable $keep = null, $statement = null): void
    {
        $this->boot();
        $statement ??= $this->open($connection, $export);

        $headers = array_values(array_filter(
            array_map(
                static fn ($field) => $field['Name'],
                sqlsrv_field_metadata($statement) ?: []
            ),
            static fn ($name) => $name !== 'export_part_row'
        ));
        foreach (array_keys($export['extra'] ?? []) as $name) {
            $headers[] = $name;
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);

        $written = 0;
        $resIdMap = is_array($export['res_id_map'] ?? null) ? $export['res_id_map'] : null;
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            if ($resIdMap !== null) {
                $externalId = preg_replace('/[^A-Za-z0-9._-]/', '_', trim((string) ($row['res_id'] ?? ''))) ?? '';
                if ($externalId === '' || ! isset($resIdMap[$externalId])) {
                    continue;
                }
                $row['res_id'] = $resIdMap[$externalId];
            }
            if ($keep !== null && $keep($row) !== true) {
                continue;
            }
            $line = [];
            foreach ($headers as $name) {
                $value = array_key_exists($name, $export['extra'] ?? [])
                    ? (string) csv_value($export['extra'][$name], $name)
                    : (string) csv_value($row[$name] ?? null, $name);
                $line[] = $this->categoryExternalId($export, $name, $value);
            }
            fputcsv($out, $line);
            if (++$written % 500 === 0) {
                fflush($out);
                flush();
            }
        }
        fclose($out);
    }

    public function categoryExternalId(array $export, string $column, string $value): string
    {
        $map = $export['category_id_map'] ?? null;
        if ($column !== 'categ_id/id' || ! is_array($map) || $value === '') {
            return $value;
        }

        return $map[strtolower($value)] ?? $value;
    }

    public function attachPartnerResIds(Company $company, $connection, array $export): array
    {
        if (($export['resolve_res_id'] ?? null) !== 'res.partner') {
            return $export;
        }

        $this->boot();
        $statement = export_query(
            $connection,
            "SELECT DISTINCT RTRIM(res_id) AS name FROM ({$export['sql']}) AS partner_ids WHERE RTRIM(ISNULL(res_id, '')) <> ''"
        );
        if ($statement === false) {
            throw new RuntimeException(app(GpConnection::class)->errorText('The customer lookup for CRM notes failed.'));
        }

        $names = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', trim((string) ($row['name'] ?? ''))) ?? '';
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        sqlsrv_free_stmt($statement);

        $map = [];
        foreach (array_chunk(array_values($names), 300) as $chunk) {
            $found = $this->odoo->findExternalIds($company, ['res.partner'], $chunk);
            foreach ($found as $name => $row) {
                $id = (int) ($row['res_id'] ?? 0);
                if ($id > 0) {
                    $map[$name] = $id;
                }
            }
        }

        $export['res_id_map'] = $map;

        return $export;
    }

    public function userExternalId(Company $company, int $userId): string
    {
        return $this->externalId($company, 'res.users', $userId, 'user_'.$userId);
    }

    public function missingPriceListIds(Company $company, $connection, array $export): array
    {
        $this->boot();
        $statement = export_query($connection, "SELECT DISTINCT id FROM (\n{$export['sql']}\n) AS price_ids");
        if ($statement === false) {
            throw new RuntimeException(app(GpConnection::class)->errorText('The price list lookup failed.'));
        }

        $ids = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $id = trim((string) ($row['id'] ?? ''));
            if (preg_match('/^pricelist_[A-Za-z0-9._-]+$/', $id)) {
                $ids[] = $id;
            }
        }
        sqlsrv_free_stmt($statement);

        $missing = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $found = $this->odoo->findExternalIds($company, ['product.pricelist'], $chunk);
            foreach ($chunk as $id) {
                if (! isset($found[$id])) {
                    $missing[] = $id;
                }
            }
        }

        return $missing;
    }

    public function onlyIds(string $sql, array $ids): string
    {
        $list = [];
        foreach ($ids as $id) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
                $list[] = "'".$id."'";
            }
        }
        if ($list === []) {
            return "SELECT * FROM (\n{$sql}\n) AS missing_prices WHERE 1 = 0";
        }

        return "SELECT * FROM (\n{$sql}\n) AS missing_prices WHERE id IN (".implode(', ', $list).')';
    }

    public function partCount(?int $count): int
    {
        if ($count === null || $count <= self::PART_ROWS) {
            return 1;
        }

        return (int) ceil($count / self::PART_ROWS);
    }

    public function partSql(string $sql, int $part): string
    {
        $part = max(1, $part);
        $start = ($part - 1) * self::PART_ROWS;
        $end = $start + self::PART_ROWS;
        $order = 'src.id';
        if (preg_match('/AS \[([^\]]+sequence)\]/', $sql, $match)) {
            $order .= ', src.['.$match[1].']';
        }

        return "SELECT * FROM (
    SELECT src.*, ROW_NUMBER() OVER (ORDER BY {$order}) AS export_part_row
    FROM (
{$sql}
    ) AS src
) AS export_part
WHERE export_part.export_part_row > {$start}
  AND export_part.export_part_row <= {$end}";
    }

    public function filename(array $export, ?int $part = null): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($export['file'], PATHINFO_FILENAME));
        $suffix = $part === null ? '' : '_part'.str_pad((string) $part, 2, '0', STR_PAD_LEFT);

        return $name.$suffix.'_'.date('Ymd').'.csv';
    }

    public function assignCrmEventUsers(Company $company, $connection, array $export): array
    {
        if (($export['key'] ?? '') !== 'customer_crm_events' || ! str_contains((string) ($export['sql'] ?? ''), '[[event_user_id]]')) {
            return $export;
        }

        $salespad = app(GpConnection::class)->rows($connection, <<<'SQL'
SELECT RTRIM(User_Name) AS login, RTRIM(Display_Name) AS display, RTRIM(sales_person_id) AS sp, RTRIM(Email) AS email
FROM dbo.spvSystemUser
SQL);
        $byLogin = [];
        foreach ($salespad as $row) {
            $login = strtoupper(trim((string) ($row['login'] ?? '')));
            if ($login !== '') {
                $byLogin[$login] = $row;
            }
        }

        $odooUsers = $this->odoo->execute($company, 'res.users', 'search_read', [[['share', '=', false]]], [
            'fields' => ['name', 'login', 'email', 'partner_id'],
            'limit' => 200,
        ]);
        if (! is_array($odooUsers)) {
            throw new RuntimeException('Odoo did not return its users, so the events cannot be assigned.');
        }

        $links = [];
        if ($company->exists && class_exists(SalespersonLink::class)) {
            foreach (SalespersonLink::query()->where('company_id', $company->id)->get() as $link) {
                $gpId = strtoupper(trim((string) $link->gp_id));
                if ($gpId === '') {
                    continue;
                }
                $match = collect($odooUsers)->first(fn ($user) => is_array($user) && (int) ($user['id'] ?? 0) === (int) $link->odoo_user_id);
                $partnerId = is_array($match) && is_array($match['partner_id'] ?? null) ? (int) $match['partner_id'][0] : 0;
                $links[$gpId] = [
                    'user_id' => (int) $link->odoo_user_id,
                    'partner_id' => $partnerId,
                    'name' => is_array($match) ? (string) ($match['name'] ?? '') : '',
                ];
            }
        }

        $assigned = app(GpConnection::class)->rows($connection, <<<'SQL'
SELECT DISTINCT UPPER(RTRIM(Assigned_To)) AS who
FROM dbo.spCRMEvent
WHERE RTRIM(ISNULL(Assigned_To, '')) <> ''
SQL);
        $userWhen = [];
        $partnerWhen = [];
        foreach ($assigned as $row) {
            $who = strtoupper(trim((string) ($row['who'] ?? '')));
            if ($who === '') {
                continue;
            }
            $match = $this->matchEventAssignee($who, $byLogin[$who] ?? null, $odooUsers, $links);
            if ($match === null) {
                continue;
            }
            $safe = str_replace("'", "''", $who);
            $userWhen[] = "WHEN '{$safe}' THEN '".$match['user_id']."'";
            if ($match['partner_id'] > 0) {
                $partnerWhen[] = "WHEN '{$safe}' THEN '".$match['partner_id']."'";
            }
        }

        $userSql = $userWhen === []
            ? "CAST('' AS varchar(20))"
            : 'CASE UPPER(RTRIM(ISNULL(e.Assigned_To, \'\'))) '.implode(' ', $userWhen)." ELSE '' END";
        $organizerPartner = $partnerWhen === []
            ? "CAST('' AS varchar(20))"
            : 'CASE UPPER(RTRIM(ISNULL(e.Assigned_To, \'\'))) '.implode(' ', $partnerWhen)." ELSE '' END";

        $customerKey = "CASE
            WHEN RTRIM(ISNULL(e.Customer_Num, '')) <> '' THEN CONCAT('partner_', RTRIM(e.Customer_Num))
            WHEN named.Customer_Num IS NOT NULL THEN CONCAT('partner_', named.Customer_Num)
            ELSE ''
        END";
        $customerIds = $this->eventCustomerIds($company, $connection);
        $customerWhen = [];
        foreach ($customerIds as $name => $id) {
            $safe = str_replace("'", "''", $name);
            $customerWhen[] = "WHEN '{$safe}' THEN '{$id}'";
        }
        $customerSql = $customerWhen === []
            ? "CAST('' AS varchar(20))"
            : "CASE {$customerKey} ".implode(' ', $customerWhen)." ELSE '' END";
        $attendeeSql = "CASE
            WHEN ({$organizerPartner}) <> '' AND ({$customerSql}) <> '' THEN CONCAT(({$organizerPartner}), ',', ({$customerSql}))
            WHEN ({$organizerPartner}) <> '' THEN ({$organizerPartner})
            ELSE ({$customerSql})
        END";

        $export['sql'] = str_replace(
            ['[[event_user_id]]', '[[event_attendees]]'],
            [$userSql, $attendeeSql],
            $export['sql']
        );

        return $export;
    }

    public function matchEventAssignee(string $assignedTo, ?array $salespadUser, array $odooUsers, array $links): ?array
    {
        $assignedTo = strtoupper(trim($assignedTo));
        if ($assignedTo === '') {
            return null;
        }

        $users = [];
        foreach ($odooUsers as $user) {
            if (! is_array($user) || (int) ($user['id'] ?? 0) === 0) {
                continue;
            }
            $partnerId = is_array($user['partner_id'] ?? null) ? (int) $user['partner_id'][0] : 0;
            $users[] = [
                'user_id' => (int) $user['id'],
                'partner_id' => $partnerId,
                'name' => trim((string) ($user['name'] ?? '')),
                'emails' => array_values(array_filter([
                    strtolower(trim((string) ($user['login'] ?? ''))),
                    strtolower(trim((string) ($user['email'] ?? ''))),
                ])),
            ];
        }

        if (is_array($salespadUser)) {
            $email = strtolower(trim((string) ($salespadUser['email'] ?? '')));
            if (str_contains($email, '@')) {
                $found = array_values(array_filter($users, fn (array $user) => in_array($email, $user['emails'], true)));
                if (count($found) === 1) {
                    return $this->eventPerson($found[0]);
                }
            }

            $display = $this->eventName((string) ($salespadUser['display'] ?? ''));
            if ($display !== '') {
                $found = array_values(array_filter($users, fn (array $user) => $this->eventName($user['name']) === $display));
                if (count($found) === 1) {
                    return $this->eventPerson($found[0]);
                }
            }

            $tokens = $this->eventTokens((string) ($salespadUser['login'] ?? $assignedTo));
            if (count($tokens) >= 2) {
                $found = array_values(array_filter($users, function (array $user) use ($tokens) {
                    $name = $this->eventName($user['name']);
                    foreach ($tokens as $token) {
                        if (! str_contains($name, $token)) {
                            return false;
                        }
                    }

                    return true;
                }));
                if (count($found) === 1) {
                    return $this->eventPerson($found[0]);
                }
            }
        }

        $link = $links[strtoupper(trim((string) (is_array($salespadUser) ? ($salespadUser['sp'] ?? '') : '')))] ?? null;
        if (! is_array($link) && isset($links[$assignedTo]) && ! is_array($salespadUser)) {
            $link = $links[$assignedTo];
        }
        if (is_array($link) && (int) ($link['user_id'] ?? 0) > 0 && $this->eventLinkAgrees($link, $salespadUser)) {
            return [
                'user_id' => (int) $link['user_id'],
                'partner_id' => (int) ($link['partner_id'] ?? 0),
            ];
        }

        return null;
    }

    private function eventCustomerIds(Company $company, $connection): array
    {
        $rows = app(GpConnection::class)->rows($connection, <<<'SQL'
SELECT DISTINCT
    CASE
        WHEN RTRIM(ISNULL(e.Customer_Num, '')) <> '' THEN CONCAT('partner_', RTRIM(e.Customer_Num))
        WHEN named.Customer_Num IS NOT NULL THEN CONCAT('partner_', named.Customer_Num)
        ELSE ''
    END AS name
FROM dbo.spCRMEvent AS e
OUTER APPLY (
    SELECT TOP 1 RTRIM(c.Customer_Num) AS Customer_Num
    FROM dbo.spvCustomer AS c
    WHERE RTRIM(ISNULL(e.Customer_Num, '')) = ''
      AND RTRIM(ISNULL(e.Subject, '')) <> ''
      AND RTRIM(c.Customer_Name) = RTRIM(e.Subject)
      AND (
          SELECT COUNT(*)
          FROM dbo.spvCustomer AS c2
          WHERE RTRIM(c2.Customer_Name) = RTRIM(e.Subject)
      ) = 1
) AS named
WHERE COALESCE(e.Starts_On, e.Created_On) IS NOT NULL
SQL);
        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        if ($names === []) {
            return [];
        }

        $found = [];
        foreach (array_chunk(array_values($names), 300) as $chunk) {
            foreach ($this->odoo->findExternalIds($company, ['res.partner'], $chunk) as $name => $row) {
                $id = (int) ($row['res_id'] ?? 0);
                if ($id > 0) {
                    $found[$name] = $id;
                }
            }
        }

        return $found;
    }

    private function eventLinkAgrees(array $link, ?array $salespadUser): bool
    {
        if (! is_array($salespadUser)) {
            return true;
        }

        $name = $this->eventName((string) ($link['name'] ?? ''));
        $display = $this->eventName((string) ($salespadUser['display'] ?? ''));
        if ($display !== '' && $name !== '' && ($display === $name || str_contains($name, $display) || str_contains($display, $name))) {
            return true;
        }

        $tokens = $this->eventTokens((string) ($salespadUser['login'] ?? ''));
        if ($name !== '' && count($tokens) >= 2) {
            foreach ($tokens as $token) {
                if (! str_contains($name, $token)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function eventPerson(array $user): array
    {
        return [
            'user_id' => (int) $user['user_id'],
            'partner_id' => (int) $user['partner_id'],
        ];
    }

    private function eventName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function eventTokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($value)) ?: [];

        return array_values(array_filter($parts, fn (string $part) => strlen($part) >= 3));
    }

    private function applySalesperson(Company $company, array $export, string $key): array
    {
        $sql = (string) ($export['sql'] ?? '');
        if (! preg_match('/RTRIM\((c\.Sales_Person_ID|h\.SLPRSNID)\) AS gp_salesperson/', $sql)) {
            return $export;
        }

        $column = str_contains($key, 'invoices') || str_contains($key, 'returns')
            ? 'invoice_user_id/id'
            : 'user_id/id';
        $export['sql'] = preg_replace_callback(
            '/RTRIM\((c\.Sales_Person_ID|h\.SLPRSNID)\) AS gp_salesperson/',
            fn (array $match) => $this->salespersonCase($company, $match[1]).' AS ['.$column.']',
            $sql
        );
        $export['fields'][$column] = 'Salesperson / External ID';

        return $export;
    }

    private function salespersonCase(Company $company, string $expr): string
    {
        if (! $company->exists) {
            return "''";
        }

        $links = SalespersonLink::query()
            ->where('company_id', $company->id)
            ->where('external_id', '!=', '')
            ->get();
        if ($links->isEmpty()) {
            return "''";
        }

        $sql = 'CASE RTRIM('.$expr.')';
        foreach ($links as $link) {
            $gpId = str_replace("'", "''", $link->gp_id);
            $externalId = str_replace("'", "''", $link->external_id);
            $sql .= " WHEN '{$gpId}' THEN '{$externalId}'";
        }

        return $sql." ELSE '' END";
    }

    private function warehouseStock(Company $company): array
    {
        $gpName = trim((string) $company->gp_company_name);
        if ($gpName === '') {
            throw new RuntimeException('Great Plains has no company name, so the warehouse locations file cannot pick an Odoo company.');
        }

        $companies = $this->odoo->execute($company, 'res.company', 'search_read', [[]], [
            'fields' => ['name'],
            'limit' => 50,
        ]);
        $match = $this->matchingOdooCompany($company, is_array($companies) ? $companies : [], $gpName);
        if (! is_array($match)) {
            throw new RuntimeException('Odoo has no company matching '.$gpName.'.');
        }

        $warehouses = $this->odoo->execute($company, 'stock.warehouse', 'search_read', [[
            ['company_id', '=', (int) $match['id']],
        ]], [
            'fields' => ['name', 'lot_stock_id'],
            'limit' => 1,
        ]);
        $warehouse = is_array($warehouses) ? ($warehouses[0] ?? null) : null;
        $locationId = (int) (is_array($warehouse['lot_stock_id'] ?? null) ? $warehouse['lot_stock_id'][0] : 0);
        if ($locationId === 0) {
            throw new RuntimeException('Odoo has no warehouse stock location for '.$match['name'].'.');
        }

        $name = trim((string) ($warehouse['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Odoo has no warehouse name for '.$match['name'].'.');
        }

        return [
            'company_id' => (int) $match['id'],
            'location_id' => $locationId,
            'name' => $name,
        ];
    }

    private function matchingOdooCompany(Company $company, array $companies, string $gpName): ?array
    {
        $importId = (int) $company->odoo_import_company_id;
        $connected = trim((string) $company->odoo_company_name);
        $fuzzy = null;
        foreach ($companies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($importId > 0 && (int) ($row['id'] ?? 0) === $importId) {
                return $row;
            }
            if ($connected !== '' && strcasecmp($connected, $name) === 0) {
                return $row;
            }
            if ($fuzzy === null && $this->sameOrganization($gpName, $name)) {
                $fuzzy = $row;
            }
        }

        return $fuzzy;
    }

    private function sameOrganization(string $left, string $right): bool
    {
        if (strcasecmp($left, $right) === 0) {
            return true;
        }
        $a = $this->organizationTokens($left);
        $b = $this->organizationTokens($right);
        if ($a === [] || $b === []) {
            return false;
        }
        $shared = array_intersect($a, $b);
        if (count($shared) >= 2) {
            return true;
        }

        return count($shared) === 1 && (count($a) === 1 || count($b) === 1);
    }

    private function organizationTokens(string $name): array
    {
        $name = str_replace(["'", '’', '`'], '', strtolower($name));
        $parts = preg_split('/[^a-z0-9]+/', $name) ?: [];
        $skip = ['inc', 'incorporated', 'llc', 'ltd', 'limited', 'co', 'company', 'corp', 'corporation', 'the', 'and'];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3 || in_array($part, $skip, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    private function externalId(Company $company, string $model, int $resId, string $name): string
    {
        $existing = $this->odoo->execute($company, 'ir.model.data', 'search_read', [[
            ['model', '=', $model],
            ['res_id', '=', $resId],
        ]], [
            'fields' => ['module', 'name'],
            'limit' => 1,
        ]);
        if (is_array($existing) && isset($existing[0]['module'], $existing[0]['name'])) {
            return $existing[0]['module'].'.'.$existing[0]['name'];
        }

        $this->odoo->execute($company, 'ir.model.data', 'create', [[
            'module' => 'odooexport',
            'name' => $name,
            'model' => $model,
            'res_id' => $resId,
            'noupdate' => true,
        ]]);

        return 'odooexport.'.$name;
    }

    private function userFieldCatalog(Company $company, $connection): array
    {
        $report = $company->salespad_report ?? [];
        $saved = $report['user_field_catalog'] ?? null;
        if (($report['user_fields_scanned'] ?? false) && is_array($saved)) {
            return $saved;
        }

        $close = $connection === null;
        $connection ??= app(GpConnection::class)->connect($company);
        try {
            return app(UserFieldCatalog::class)->remember($company, $connection);
        } finally {
            if ($close && is_resource($connection)) {
                sqlsrv_close($connection);
            }
        }
    }

    private function firstExistingColumn($connection, string $view, array $names): ?string
    {
        if (! is_resource($connection) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $view)) {
            return null;
        }

        $safe = [];
        foreach ($names as $name) {
            if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $safe[] = $name;
            }
        }
        if ($safe === []) {
            return null;
        }

        $list = implode(', ', array_map(static fn (string $name) => "'".$name."'", $safe));
        $rows = app(GpConnection::class)->rows(
            $connection,
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$view}' AND COLUMN_NAME IN ({$list})"
        );
        $found = [];
        foreach ($rows as $row) {
            $column = trim((string) ($row['COLUMN_NAME'] ?? ''));
            if ($column !== '') {
                $found[strtolower($column)] = $column;
            }
        }
        foreach ($safe as $name) {
            if (isset($found[strtolower($name)])) {
                return $found[strtolower($name)];
            }
        }

        return null;
    }

    private function fillItemColumns(string $sql, array $columns): string
    {
        foreach (array_keys($columns) as $role) {
            if (! is_string($role) || ! preg_match('/^[a-z_]+$/', $role)) {
                continue;
            }
            $name = $columns[$role] ?? null;
            if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $sql = str_replace('[['.$role.']]', $name, $sql);
                continue;
            }
            $sql = str_replace(
                ['i.[['.$role.']]', 'c.[['.$role.']]', '[['.$role.']]'],
                "CAST('' AS varchar(200))",
                $sql
            );
        }

        return $sql;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        require_once base_path('legacy/includes/exports.php');
        require_once base_path('legacy/includes/csv.php');
        $this->booted = true;
    }
}
