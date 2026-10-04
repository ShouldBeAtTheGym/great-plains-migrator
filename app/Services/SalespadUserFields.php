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
        $connection = $this->gp->connect($company);
        try {
            $columns = $this->gp->rows($connection, <<<'SQL'
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'spxCustomer'
SQL);
            $defined = $this->gp->rows($connection, <<<'SQL'
SELECT Field_Name, Label, Value_Type, Value_Options, Disabled, Display_Seq, Length
FROM dbo.spvUserField
WHERE Table_Name = 'spxCustomer'
SQL);
        } finally {
            sqlsrv_close($connection);
        }

        $byColumn = [];
        foreach ($columns as $column) {
            $name = trim((string) ($column['COLUMN_NAME'] ?? ''));
            if ($name !== '') {
                $byColumn[strtolower($name)] = $column;
            }
        }

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
            $suggestion = $this->suggest($sqlType, (string) ($row['Value_Type'] ?? ''), (string) ($row['Value_Options'] ?? ''), $disabled);

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
        $arch = '<data><xpath expr="//page[@name=\'accounting_disabled\']" position="after">'
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
