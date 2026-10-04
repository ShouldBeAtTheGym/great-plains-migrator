<?php

namespace App\Services;

use App\Models\Company;
use RuntimeException;

class GreatPlainsTab
{
    public const PAGE = 'Great Plains';

    public const FIELDS = [
        'customer_class' => [
            'name' => 'x_gp_customer_class',
            'label' => 'Customer Class',
            'table' => 'spvCustomerClass',
            'column' => 'Customer_Class',
            'fallback' => 'spvCustomer',
        ],
        'shipping_method' => [
            'name' => 'x_gp_shipping_method',
            'label' => 'Shipping Method',
            'table' => 'spvShippingMethod',
            'column' => 'Shipping_Method',
            'fallback' => 'spvCustomer',
        ],
    ];

    public function __construct(private GpConnection $gp, private OdooClient $odoo) {}

    public function choices(Company $company): array
    {
        $connection = $this->gp->connect($company);
        try {
            $choices = [];
            foreach (self::FIELDS as $key => $field) {
                $choices[$key] = $this->distinct($connection, $field['table'], $field['column'], $field['fallback']);
            }

            return $choices;
        } finally {
            sqlsrv_close($connection);
        }
    }

    public function distinct($connection, string $table, string $column, string $fallback): array
    {
        try {
            $rows = $this->gp->rows($connection, $this->distinctSql($table, $column));
        } catch (RuntimeException) {
            $rows = $this->gp->rows($connection, $this->distinctSql($fallback, $column));
        }

        return $this->values($rows);
    }

    public function values(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '' || isset($values[$value])) {
                continue;
            }
            $values[$value] = $value;
        }

        $list = array_values($values);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    public function create(Company $company, array $choices): string
    {
        $modelId = $this->modelId($company);
        foreach (self::FIELDS as $key => $field) {
            $options = $choices[$key] ?? [];
            if ($options === []) {
                throw new RuntimeException($field['label'].' has no values in Great Plains.');
            }
            $this->saveField($company, $modelId, $field['name'], $field['label'], $options);
        }

        $this->savePlainField($company, $modelId, 'x_gp_customer_number', 'Customer Number', 'char', 64);
        $this->savePage($company);
        $this->saveAddressFields($company);

        return 'The '.self::PAGE.' tab is on the contact form. Customer Number is text. Customer Class has '.count($choices['customer_class']).' choices. Shipping Method has '.count($choices['shipping_method']).' choices. Address Code, Primary, Bill To, and Ship To are on the related contact form.';
    }

    public function relatedArch(): string
    {
        return '<data><xpath expr="//field[@name=\'child_ids\']/form//field[@name=\'comment\']" position="after">'
            .'<field name="x_gp_address_code"/>'
            .'<field name="x_gp_is_primary"/>'
            .'<field name="x_gp_is_bill_to"/>'
            .'<field name="x_gp_is_ship_to"/>'
            .'</xpath></data>';
    }

    public function pageArch(): string
    {
        $fields = '<field name="x_gp_customer_number"/>';
        foreach (self::FIELDS as $field) {
            $fields .= '<field name="'.htmlspecialchars($field['name'], ENT_QUOTES).'"/>';
        }

        return '<data><xpath expr="//page[@name=\'accounting_disabled\']" position="after">'
            .'<page string="'.self::PAGE.'" name="gp_great_plains"><group>'.$fields.'</group></page>'
            .'</xpath></data>';
    }

    private function distinctSql(string $table, string $column): string
    {
        return 'SELECT DISTINCT LTRIM(RTRIM('.$column.')) AS value FROM dbo.'.$table
            ." WHERE NULLIF(LTRIM(RTRIM(".$column.")), '') IS NOT NULL";
    }

    private function modelId(Company $company): int
    {
        $rows = $this->odoo->execute($company, 'ir.model', 'search_read', [[['model', '=', 'res.partner']]], [
            'fields' => ['id'],
            'limit' => 1,
        ]);
        $id = is_array($rows) ? (int) ($rows[0]['id'] ?? 0) : 0;
        if ($id === 0) {
            throw new RuntimeException('Odoo does not have the contact model.');
        }

        return $id;
    }

    private function saveField(Company $company, int $modelId, string $name, string $label, array $options): void
    {
        $commands = [];
        $sequence = 10;
        foreach ($options as $option) {
            $commands[] = [0, 0, ['value' => $option, 'name' => $option, 'sequence' => $sequence]];
            $sequence += 10;
        }

        $existing = $this->odoo->execute($company, 'ir.model.fields', 'search_read', [[
            ['model', '=', 'res.partner'],
            ['name', '=', $name],
        ]], ['fields' => ['id'], 'limit' => 1]);
        $id = is_array($existing) ? (int) ($existing[0]['id'] ?? 0) : 0;
        if ($id > 0) {
            $this->odoo->execute($company, 'ir.model.fields', 'write', [[$id], [
                'field_description' => $label,
                'selection_ids' => array_merge([[5, 0, 0]], $commands),
            ]]);

            return;
        }

        $this->odoo->execute($company, 'ir.model.fields', 'create', [[
            'name' => $name,
            'field_description' => $label,
            'model_id' => $modelId,
            'state' => 'manual',
            'ttype' => 'selection',
            'copied' => true,
            'selection_ids' => $commands,
        ]]);
    }

    private function saveAddressFields(Company $company): void
    {
        $modelId = $this->modelId($company);
        $this->savePlainField($company, $modelId, 'x_gp_address_code', 'Address Code', 'char', 64);
        $this->savePlainField($company, $modelId, 'x_gp_is_primary', 'Primary', 'boolean');
        $this->savePlainField($company, $modelId, 'x_gp_is_bill_to', 'Bill To', 'boolean');
        $this->savePlainField($company, $modelId, 'x_gp_is_ship_to', 'Ship To', 'boolean');
        $this->saveExtension($company, 'Great Plains Migrator related contact', $this->relatedArch());
    }

    private function savePlainField(Company $company, int $modelId, string $name, string $label, string $type, ?int $size = null): void
    {
        $values = [
            'field_description' => $label,
            'ttype' => $type,
        ];
        if ($size) {
            $values['size'] = $size;
        }

        $existing = $this->odoo->execute($company, 'ir.model.fields', 'search_read', [[
            ['model', '=', 'res.partner'],
            ['name', '=', $name],
        ]], ['fields' => ['id'], 'limit' => 1]);
        $id = is_array($existing) ? (int) ($existing[0]['id'] ?? 0) : 0;
        if ($id > 0) {
            $this->odoo->execute($company, 'ir.model.fields', 'write', [[$id], $values]);

            return;
        }

        $this->odoo->execute($company, 'ir.model.fields', 'create', [[
            'name' => $name,
            'model_id' => $modelId,
            'state' => 'manual',
            'copied' => true,
        ] + $values]);
    }

    private function savePage(Company $company): void
    {
        $this->saveExtension($company, 'Great Plains Migrator tab', $this->pageArch());
    }

    private function saveExtension(Company $company, string $name, string $arch): void
    {
        $existing = $this->odoo->execute($company, 'ir.ui.view', 'search_read', [[
            ['model', '=', 'res.partner'],
            ['type', '=', 'form'],
            ['name', '=', $name],
        ]], ['fields' => ['id'], 'limit' => 1]);
        $id = is_array($existing) ? (int) ($existing[0]['id'] ?? 0) : 0;
        if ($id > 0) {
            $this->odoo->execute($company, 'ir.ui.view', 'write', [[$id], ['arch_db' => $arch]]);

            return;
        }

        $primary = $this->odoo->execute($company, 'ir.ui.view', 'search_read', [[
            ['model', '=', 'res.partner'],
            ['type', '=', 'form'],
            ['mode', '=', 'primary'],
            ['name', '=', 'res.partner.form'],
        ]], ['fields' => ['id'], 'limit' => 1]);
        $parent = is_array($primary) ? (int) ($primary[0]['id'] ?? 0) : 0;
        if ($parent === 0) {
            throw new RuntimeException('Odoo has no contact form to add the Great Plains fields to.');
        }

        $this->odoo->execute($company, 'ir.ui.view', 'create', [[
            'name' => $name,
            'model' => 'res.partner',
            'inherit_id' => $parent,
            'mode' => 'extension',
            'type' => 'form',
            'arch_db' => $arch,
        ]]);
    }
}
