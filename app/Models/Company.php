<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'gp_server',
        'gp_port',
        'gp_database',
        'gp_username',
        'gp_password',
        'gp_trust_cert',
        'gp_tested_at',
        'salespad_installed',
        'salespad_report',
        'gp_company_name',
        'gp_site_name',
        'gp_address',
        'gp_phone',
        'gp_version',
        'gp_currency',
        'odoo_url',
        'odoo_port',
        'odoo_database',
        'odoo_username',
        'odoo_api_key',
        'odoo_trust_tls',
        'odoo_tested_at',
        'odoo_company_name',
        'odoo_version',
        'odoo_company',
        'odoo_import_company_id',
        'odoo_import_company_name',
        'gp_tab_created_at',
        'preflight_passed_at',
        'preflight_report',
        'intro_open',
        'intro_acknowledged_at',
    ];

    protected $hidden = [
        'gp_password',
        'odoo_api_key',
    ];

    protected function casts(): array
    {
        return [
            'gp_password' => 'encrypted',
            'odoo_api_key' => 'encrypted',
            'gp_port' => 'integer',
            'odoo_port' => 'integer',
            'gp_trust_cert' => 'boolean',
            'odoo_trust_tls' => 'boolean',
            'salespad_installed' => 'boolean',
            'salespad_report' => 'array',
            'odoo_company' => 'array',
            'odoo_import_company_id' => 'integer',
            'preflight_report' => 'array',
            'gp_tested_at' => 'datetime',
            'odoo_tested_at' => 'datetime',
            'preflight_passed_at' => 'datetime',
            'gp_tab_created_at' => 'datetime',
            'intro_open' => 'boolean',
            'intro_acknowledged_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ConversionStep::class)->orderBy('sort');
    }

    public static function current(): self
    {
        $company = static::query()->first();
        if ($company) {
            return $company;
        }

        return static::query()->create(['name' => 'Company']);
    }

    public function prefillLegacyGp(): void
    {
        if (app()->environment('testing') || $this->gp_server) {
            return;
        }

        $path = '/home/sysadmin/.local/share/odooexport/app.sqlite';
        if (! is_file($path)) {
            return;
        }

        $sqlite = new \PDO('sqlite:'.$path);
        $settings = [];
        foreach ($sqlite->query('SELECT key, value FROM settings') as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $this->fill([
            'gp_server' => $settings['gp_server'] ?? null,
            'gp_database' => $settings['gp_database'] ?? null,
            'gp_username' => $settings['gp_user'] ?? null,
            'gp_password' => $settings['gp_password'] ?? null,
            'gp_trust_cert' => ($settings['gp_trust_cert'] ?? '1') === '1',
            'name' => $settings['company_label'] ?? $this->name,
        ]);
        $this->save();
    }

    public function setupPath(): string
    {
        if (! $this->gp_tested_at) {
            return route('setup.gp');
        }
        if (! $this->odoo_tested_at) {
            return route('setup.odoo');
        }

        return route('dashboard');
    }

    public function introAcknowledged(): bool
    {
        return $this->intro_acknowledged_at !== null
            || $this->gp_tested_at !== null
            || $this->preflight_passed_at !== null;
    }

    public function introClosed(): bool
    {
        if ($this->intro_open) {
            return false;
        }

        return User::query()->exists()
            && $this->gp_tested_at
            && $this->odoo_tested_at
            && $this->preflight_passed_at;
    }

    public function resumePath(): string
    {
        if ($this->introClosed()) {
            return auth()->check() ? route('dashboard') : route('login');
        }

        if ($this->intro_open || ! $this->introAcknowledged()) {
            return route('welcome');
        }

        if (! User::query()->exists()) {
            return route('start.account');
        }

        if (! auth()->check()) {
            return route('login');
        }

        if (! $this->gp_tested_at) {
            return route('setup.gp');
        }

        if (! $this->odoo_tested_at) {
            return route('setup.odoo');
        }

        return route('dashboard');
    }

    public function gpPort(): int
    {
        return $this->gp_port ?: 1433;
    }

    public function odooHost(): string
    {
        $parts = parse_url((string) $this->odoo_url);

        return $parts['host'] ?? '';
    }

    public function odooPort(): int
    {
        if ($this->odoo_port) {
            return (int) $this->odoo_port;
        }

        $parts = parse_url((string) $this->odoo_url);
        if (! empty($parts['port'])) {
            return (int) $parts['port'];
        }

        return 443;
    }

    public function hasSavedGpPassword(): bool
    {
        return filled($this->gp_password);
    }

    public function hasSavedOdooKey(): bool
    {
        return filled($this->odoo_api_key);
    }

    public function publicProfile(): array
    {
        return [
            'name' => $this->gp_company_name ?: $this->name,
            'site_name' => $this->gp_site_name,
            'address' => $this->gp_address,
            'phone' => $this->gp_phone,
            'gp' => [
                'server' => $this->gp_server,
                'database' => $this->gp_database,
                'username' => $this->gp_username,
                'version' => $this->gp_version,
                'currency' => $this->gp_currency,
                'tested_at' => $this->gp_tested_at?->toIso8601String(),
                'salespad_installed' => $this->salespad_installed,
            ],
            'odoo' => [
                'url' => $this->odoo_url,
                'database' => $this->odoo_database,
                'username' => $this->odoo_username,
                'company_name' => $this->odoo_company_name,
                'version' => $this->odoo_version,
                'tested_at' => $this->odoo_tested_at?->toIso8601String(),
            ],
            'preflight_passed_at' => $this->preflight_passed_at?->toIso8601String(),
            'next' => $this->setupPath(),
        ];
    }
}
