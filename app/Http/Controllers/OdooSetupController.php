<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class OdooSetupController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $company = Company::current();
        if (! $company->gp_tested_at) {
            return redirect()->route('setup.gp');
        }

        return view('setup.odoo', ['company' => $company]);
    }

    public function test(Request $request, ConnectionTester $tester): RedirectResponse
    {
        $company = Company::current();
        if (! $company->gp_tested_at) {
            return redirect()->route('setup.gp');
        }

        $data = $request->validate([
            'odoo_host' => ['required_without:odoo_url', 'nullable', 'string', 'max:300'],
            'odoo_url' => ['required_without:odoo_host', 'nullable', 'string', 'max:300'],
            'odoo_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'odoo_database' => ['required', 'string', 'max:80'],
            'odoo_username' => ['required', 'string', 'max:120'],
            'odoo_password' => [$company->hasSavedOdooKey() ? 'nullable' : 'required_without:odoo_api_key', 'nullable', 'string', 'max:200'],
            'odoo_api_key' => ['nullable', 'string', 'max:200'],
            'odoo_trust_tls' => ['nullable', 'boolean'],
        ]);
        $data['odoo_trust_tls'] = $request->boolean('odoo_trust_tls');
        $data['odoo_api_key'] = $data['odoo_password'] ?? $data['odoo_api_key'] ?? '';

        try {
            $tester->testOdoo($company, $data);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Odoo answered. Press Next.');
    }
}
