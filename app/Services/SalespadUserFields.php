<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SalespadUserField;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use RuntimeException;

class SalespadUserFields
{
    public const TYPES = [
        'text' => 'Text',
        'selection' => 'Selection',
        'boolean' => 'Yes / No',
        'number' => 'Number',
    ];

    public function __construct(
        private GpConnection $gp,
        private OdooClient $odoo,
    ) {}

    public function models(): array
    {
        return [
            'customers' => [
                'title' => 'Customer user fields',
                'table' => 'spxCustomer',
                'odoo_model' => 'res.partner',
                'card' => 'User Fields',
                'skip' => ['Customer_Num'],
                'page_xpath' => "//page[@name='accounting_disabled']",
                'page_position' => 'after',
            ],
            'products' => [
                'title' => 'Product user fields',
                'table' => 'spxItemMaster',
                'odoo_model' => 'product.template',
                'card' => 'User Fields',
                'skip' => ['Item_Number', 'xBarcode', 'xCategory'],
                'page_xpath' => '//notebook',
                'page_position' => 'inside',
            ],
        ];
    }

    public function model(string $key): array
    {
        $model = $this->models()[$key] ?? null;
        if (! $model) {
            abort(404);
        }

        return $model;
    }

    public function sync(Company $company, string $key): void
    {
        $model = $this->model($key);
        $table = str_replace("'", "''", $model['table']);
        $columns = [];
        $defined = [];
        $failure = null;
        foreach (app(CompanyMerge::class)->sources() as $source) {
            try {
                $connection = $this->gp->connectDatabase($company, $source['database']);
            } catch (RuntimeException $exception) {
                $failure = $exception;
                continue;
            }
            try {
                foreach ($this->gp->rows($connection, "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table}'") as $column) {
                    $name = strtolower(trim((string) ($column['COLUMN_NAME'] ?? '')));
                    if ($name !== '' && ! isset($columns[$name])) {
                        $columns[$name] = $column;
                    }
                }
                foreach ($this->gp->rows($connection, "SELECT Field_Name, Label, Value_Type, CAST(Value_Options AS varchar(4000)) AS Value_Options, Disabled, Display_Seq, Length FROM dbo.spvUserField WHERE Table_Name = '{$table}'") as $row) {
                    $name = strtolower(trim((string) ($row['Field_Name'] ?? '')));
                    if ($name !== '' && ! isset($defined[$name])) {
                        $defined[$name] = $row;
                    }
                }
            } catch (RuntimeException $exception) {
                $failure = $exception;
            } finally {
                if (is_resource($connection)) {
                    sqlsrv_close($connection);
                }
            }
        }
        if ($columns === [] && $failure) {
            throw $failure;
        }

        $byColumn = $columns;

        $sort = 0;
        foreach ($defined as $row) {
            $fieldName = trim((string) ($row['Field_Name'] ?? ''));
            $column = $byColumn[strtolower($fieldName)] ?? null;
            if ($fieldName === '' || ! $column || in_array($fieldName, $model['skip'], true)) {
                continue;
            }

            $label = trim((string) ($row['Label'] ?? ''));
            $label = rtrim($label, " \t:");
            if ($label === '') {
                $label = str_replace('_', ' ', $fieldName);
            }
            $disabled = $this->truthy($row['Disabled'] ?? false);
            $sqlType = strtolower(trim((string) ($column['DATA_TYPE'] ?? '')));
            $length = (int) ($row['Length'] ?? 0);
            if ($length <= 0) {
                $length = (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            }
            $rawOptions = str_replace(["\r\n", "\r", "\n"], ';', (string) ($row['Value_Options'] ?? ''));
            $suggestion = $this->suggest($sqlType, (string) ($row['Value_Type'] ?? ''), $rawOptions, $disabled);

            $existing = SalespadUserField::query()->firstOrNew([
                'company_id' => $company->id,
                'model_key' => $key,
                'field_name' => $fieldName,
            ]);
            $existing->source_table = $model['table'];
            $existing->label = $label;
            $existing->disabled = $disabled;
            $existing->sql_type = $sqlType;
            $existing->length = $length > 0 ? $length : null;
            $existing->sort = $sort;
            if (! $existing->exists) {
                $existing->selected = false;
                $existing->field_type = $suggestion['field_type'];
                $existing->options = $suggestion['options'];
            } elseif ($existing->field_type === 'selection' && trim((string) $existing->options) === '') {
                $existing->options = $suggestion['options'];
            }
            $existing->save();
            $sort++;
        }
    }

    public function suggest(string $sqlType, string $valueType, string $options, bool $disabled): array
    {
        $sqlType = strtolower($sqlType);
        $valueType = strtolower($valueType);
        $list = $this->optionList($options);

        if (str_contains($valueType, 'boolean') || $sqlType === 'bit') {
            $type = 'boolean';
            $list = [];
        } elseif ($list !== []) {
            $type = 'selection';
        } elseif (in_array($sqlType, ['int', 'bigint', 'smallint', 'tinyint', 'numeric', 'decimal', 'float', 'money', 'real'], true)) {
            $type = 'number';
        } else {
            $type = 'text';
        }

        return [
            'field_type' => $type,
            'options' => $type === 'selection' ? implode('; ', $list) : '',
            'disabled' => $disabled,
        ];
    }

    public function optionList(?string $raw): array
    {
        $parts = preg_split('/\s*;\s*/', trim((string) $raw)) ?: [];
        $options = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $options, true)) {
                $options[] = $part;
            }
        }

        return $options;
    }

    public function saveChoices(Company $company, string $key, array $rows): void
    {
        $known = SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->get()
            ->keyBy('id');

        foreach ($rows as $id => $row) {
            $field = $known->get((int) $id);
            if (! $field || ! is_array($row)) {
                continue;
            }
            $type = (string) ($row['field_type'] ?? 'text');
            if (! array_key_exists($type, self::TYPES)) {
                $type = 'text';
            }
            $selected = filter_var($row['selected'] ?? false, FILTER_VALIDATE_BOOL);
            $options = trim((string) ($row['options'] ?? ''));
            if ($selected && $type === 'selection' && $this->optionList($options) === []) {
                throw new RuntimeException($field->label.' is a selection and needs at least one choice, separated by semicolons.');
            }
            $field->selected = $selected;
            $field->field_type = $type;
            $field->options = $this->optionList($options) === [] ? null : implode('; ', $this->optionList($options));
            $field->save();
        }
    }

    public function createInOdoo(Company $company, string $key): string
    {
        $model = $this->model($key);
        $chosen = SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->where('selected', true)
            ->orderBy('sort')
            ->get();

        if ($chosen->isEmpty()) {
            throw new RuntimeException('Check at least one user field to move.');
        }

        $modelId = $this->odooModelId($company, $model['odoo_model']);
        $created = 0;
        $kept = 0;
        foreach ($chosen as $field) {
            if ($field->odoo_field) {
                $kept++;
                continue;
            }
            $name = $this->technicalName($field->field_name);
            $existing = $this->odoo->execute($company, 'ir.model.fields', 'search_read', [[
                ['model', '=', $model['odoo_model']],
                ['name', '=', $name],
            ]], ['fields' => ['id'], 'limit' => 1]);
            if (! is_array($existing) || $existing === []) {
                $this->odoo->execute($company, 'ir.model.fields', 'create', [$this->fieldValues($field, $name, $modelId)]);
                $created++;
            } else {
                $kept++;
            }
            $field->odoo_field = $name;
            $field->odoo_created_at = $field->odoo_created_at ?? now();
            $field->save();
        }

        $ready = SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->where('selected', true)
            ->whereNotNull('odoo_field')
            ->orderBy('sort')
            ->get();
        $this->placeOnCard($company, $model, $ready->pluck('odoo_field')->all());

        return 'Created '.$created.' user field'.($created === 1 ? '' : 's').' on the '.$model['card'].' card. '
            .$ready->count().' checked field'.($ready->count() === 1 ? ' is' : 's are').' on that card.';
    }

    /**
     * @return array<string, int>
     */
    public function expandSelections(Company $company, string $key): array
    {
        $fields = SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->where('selected', true)
            ->where('field_type', 'selection')
            ->whereNotNull('odoo_field')
            ->get();
        $found = [];
        foreach ($fields as $field) {
            $found[$field->id] = $this->optionList($field->options);
        }
        foreach (app(CompanyMerge::class)->sources() as $source) {
            try {
                $connection = $this->gp->connectDatabase($company, $source['database']);
            } catch (RuntimeException) {
                continue;
            }
            try {
                $columns = [];
                $table = $this->model($key)['table'];
                foreach ($this->gp->rows($connection, "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('dbo.{$table}')") as $row) {
                    $columns[strtolower(trim((string) ($row['name'] ?? '')))] = true;
                }
                foreach ($fields as $field) {
                    if (! isset($columns[strtolower($field->field_name)])) {
                        continue;
                    }
                    $expression = gp_text_sql('['.$field->field_name.']');
                    $rows = $this->gp->rows($connection, 'SELECT DISTINCT '.$expression.' AS value FROM dbo.'.$table.' WHERE NULLIF('.$expression.", '') IS NOT NULL");
                    foreach ($this->cleanValues($rows) as $value) {
                        if (! in_array($value, $found[$field->id], true)) {
                            $found[$field->id][] = $value;
                        }
                    }
                }
            } finally {
                if (is_resource($connection)) {
                    sqlsrv_close($connection);
                }
            }
        }

        $added = [];
        foreach ($fields as $field) {
            $options = $found[$field->id];
            sort($options, SORT_NATURAL | SORT_FLAG_CASE);
            $field->options = implode('; ', $options);
            $field->save();
            $added[$field->label] = $this->odoo->addSelectionValues($company, $this->model($key)['odoo_model'], (string) $field->odoo_field, $options);
        }

        return $added;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function cleanValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function sqlColumns(Company $company, string $key): string
    {
        $fields = SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->where('selected', true)
            ->whereNotNull('odoo_field')
            ->orderBy('sort')
            ->get();

        $lines = [];
        foreach ($fields as $field) {
            $column = 'u.['.$field->field_name.']';
            $alias = $field->odoo_field;
            if ($field->field_type === 'boolean') {
                $lines[] = "CASE WHEN {$column} = 1 THEN 1 ELSE 0 END AS {$alias}";
            } elseif ($field->field_type === 'number') {
                $lines[] = "{$column} AS {$alias}";
            } else {
                $lines[] = gp_text_sql($column).' AS '.$alias;
            }
        }

        return $lines === [] ? '' : ",\n    ".implode(",\n    ", $lines);
    }

    public function mergeCard(string $arch, array $fieldNames, string $card = 'User Fields'): ?string
    {
        $arch = trim($arch);
        if ($arch === '') {
            return null;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        if (! @$dom->loadXML($arch)) {
            return null;
        }

        $page = null;
        foreach ((new DOMXPath($dom))->query('//page') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $title = strtolower($node->getAttribute('string'));
            if (str_contains($title, 'user field') || str_contains($title, 'user feild')) {
                $page = $node;
                break;
            }
        }
        if (! $page) {
            return null;
        }

        $page->setAttribute('string', $card);
        while ($page->firstChild) {
            $page->removeChild($page->firstChild);
        }
        $page->appendChild($this->fieldGroup($dom, $fieldNames));

        return $dom->saveXML($dom->documentElement) ?: null;
    }

    private function fieldValues(SalespadUserField $field, string $name, int $modelId): array
    {
        $values = [
            'name' => $name,
            'field_description' => $field->label,
            'model_id' => $modelId,
            'state' => 'manual',
            'copied' => true,
        ];

        if ($field->field_type === 'boolean') {
            $values['ttype'] = 'boolean';
        } elseif ($field->field_type === 'number') {
            $values['ttype'] = in_array($field->sql_type, ['numeric', 'decimal', 'float', 'money', 'real'], true) ? 'float' : 'integer';
        } elseif ($field->field_type === 'selection') {
            $values['ttype'] = 'selection';
            $ids = [];
            $sequence = 10;
            foreach ($this->optionList($field->options) as $option) {
                $ids[] = [0, 0, ['value' => $option, 'name' => $option, 'sequence' => $sequence]];
                $sequence += 10;
            }
            $values['selection_ids'] = $ids;
        } elseif (($field->length ?? 0) > 255) {
            $values['ttype'] = 'text';
        } else {
            $values['ttype'] = 'char';
            $values['size'] = max(1, min((int) ($field->length ?: 255), 255));
        }

        return $values;
    }

    private function placeOnCard(Company $company, array $model, array $fieldNames): void
    {
        $views = $this->odoo->execute($company, 'ir.ui.view', 'search_read', [[
            ['model', '=', $model['odoo_model']],
            ['type', '=', 'form'],
        ]], [
            'fields' => ['id', 'arch_db', 'mode'],
            'limit' => 40,
        ]);

        if (! is_array($views)) {
            throw new RuntimeException('Odoo did not return the contact form.');
        }

        foreach ($views as $view) {
            $arch = $this->archText($view['arch_db'] ?? '');
            $updated = $this->mergeCard($arch, $fieldNames, $model['card']);
            if ($updated) {
                $this->odoo->execute($company, 'ir.ui.view', 'write', [[$view['id']], ['arch_db' => $updated]]);

                return;
            }
        }

        $baseId = null;
        foreach ($views as $view) {
            if (($view['mode'] ?? '') === 'primary') {
                $baseId = (int) $view['id'];
                break;
            }
        }
        if (! $baseId) {
            throw new RuntimeException('Odoo has no contact form to add the User Fields card to.');
        }

        $group = '';
        foreach ($fieldNames as $name) {
            $group .= '<field name="'.htmlspecialchars($name, ENT_QUOTES).'"/>';
        }
        $xpath = $model['page_xpath'] ?? "//page[@name='accounting_disabled']";
        $position = $model['page_position'] ?? 'after';
        $arch = '<data><xpath expr="'.htmlspecialchars($xpath, ENT_QUOTES).'" position="'.htmlspecialchars($position, ENT_QUOTES).'">'
            .'<page string="'.htmlspecialchars($model['card'], ENT_QUOTES).'" name="gp_user_fields"><group>'.$group.'</group></page>'
            .'</xpath></data>';
        $this->odoo->execute($company, 'ir.ui.view', 'create', [[
            'name' => 'Great Plains Migrator '.$model['card'],
            'model' => $model['odoo_model'],
            'inherit_id' => $baseId,
            'mode' => 'extension',
            'type' => 'form',
            'arch_db' => $arch,
        ]]);
    }

    private function fieldGroup(DOMDocument $dom, array $fieldNames): DOMElement
    {
        $group = $dom->createElement('group');
        $group->setAttribute('name', 'gp_user_fields');
        foreach ($fieldNames as $name) {
            $node = $dom->createElement('field');
            $node->setAttribute('name', $name);
            $group->appendChild($node);
        }

        return $group;
    }

    private function odooModelId(Company $company, string $model): int
    {
        $rows = $this->odoo->execute($company, 'ir.model', 'search_read', [[['model', '=', $model]]], [
            'fields' => ['id'],
            'limit' => 1,
        ]);
        $id = is_array($rows) ? (int) ($rows[0]['id'] ?? 0) : 0;
        if ($id === 0) {
            throw new RuntimeException('Odoo does not have the contact model.');
        }

        return $id;
    }

    private function technicalName(string $column): string
    {
        $name = strtolower($column);
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '';
        $name = trim($name, '_');

        return 'x_spx_'.substr($name, 0, 50);
    }

    private function archText(mixed $arch): string
    {
        if (is_string($arch)) {
            return $arch;
        }
        if (is_array($arch)) {
            foreach ($arch as $value) {
                if (is_string($value) && str_contains($value, '<')) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'yes', 'y'], true);
    }

    public function chosen(Company $company, string $key): Collection
    {
        return SalespadUserField::query()
            ->where('company_id', $company->id)
            ->where('model_key', $key)
            ->orderBy('sort')
            ->orderBy('label')
            ->get();
    }
}
