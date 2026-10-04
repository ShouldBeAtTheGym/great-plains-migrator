<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class GpSetupController extends Controller
{
    public function show(): View
    {
        return view('setup.gp', [
            'company' => Company::current(),
        ]);
    }

    public function test(Request $request, ConnectionTester $tester): RedirectResponse
    {
        $company = Company::current();
        $data = $request->validate([
            'gp_server' => ['required', 'string', 'max:200'],
            'gp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'gp_database' => ['required', 'string', 'max:80'],
            'gp_username' => ['required', 'string', 'max:80'],
            'gp_password' => [$company->hasSavedGpPassword() ? 'nullable' : 'required', 'string', 'max:200'],
            'gp_trust_cert' => ['nullable', 'boolean'],
        ]);
        $data['gp_trust_cert'] = $request->boolean('gp_trust_cert');

        try {
            $tester->testGp($company, $data);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Great Plains answered. Press Next.');
    }
}
