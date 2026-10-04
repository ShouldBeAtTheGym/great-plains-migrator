<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ConversionPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PreflightController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $company = Company::current();
        if (! $company->odoo_tested_at) {
            return redirect()->to($company->setupPath());
        }

        return view('setup.preflight', [
            'company' => $company,
            'report' => $company->preflight_report,
        ]);
    }

    public function run(ConversionPlanner $planner): RedirectResponse
    {
        $company = Company::current();
        if (! $company->odoo_tested_at) {
            return redirect()->to($company->setupPath());
        }

        $report = $planner->preflight($company);
        $company->preflight_report = $report;
        if ($report['passed']) {
            $company->preflight_passed_at = now();
            $company->save();
            $planner->syncSteps($company);

            return redirect()->route('dashboard')->with('status', 'Preflight passed. The checklist is ready.');
        }

        $company->preflight_passed_at = null;
        $company->save();

        return back()->with('error', 'Preflight found something to fix before the checklist can open.');
    }
}
