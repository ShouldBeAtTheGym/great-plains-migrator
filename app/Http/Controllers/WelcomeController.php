<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\ConversionPlanner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $company = Company::current();
        if (! $company->intro_open && ($company->introAcknowledged() || $company->introClosed())) {
            return redirect()->to($company->resumePath());
        }

        return view('welcome-start');
    }

    public function begin(): RedirectResponse
    {
        $company = Company::current();
        if ($company->intro_open) {
            return redirect()->route('start.ready');
        }
        if ($company->introAcknowledged() || $company->introClosed()) {
            return redirect()->to($company->resumePath());
        }

        return redirect()->route('start.ready');
    }

    public function ready(): View
    {
        return view('start.ready');
    }

    public function readyStore(Request $request): RedirectResponse
    {
        $request->validate([
            'gp_ready' => ['accepted'],
            'odoo_ready' => ['accepted'],
            'browser_ready' => ['accepted'],
        ]);

        $request->session()->put('office.preflight', true);
        $company = Company::current();
        $company->intro_acknowledged_at = $company->intro_acknowledged_at ?? now();
        $company->save();

        return redirect()->route('start.overview');
    }

    public function account(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->to(Company::current()->resumePath());
        }

        return view('start.account');
    }

    public function accountStore(Request $request, ConversionPlanner $planner): RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->to(Company::current()->resumePath());
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        event(new Registered($user));
        Auth::login($user);

        $company = Company::current();
        $company->intro_acknowledged_at = $company->intro_acknowledged_at ?? now();
        if ($company->gp_tested_at && $company->odoo_tested_at) {
            $company->preflight_passed_at = $company->preflight_passed_at ?? now();
            $company->intro_open = false;
            $planner->syncSteps($company);
        }
        $company->save();

        return redirect()
            ->to($company->resumePath())
            ->with('status', 'Your login is saved. You can close this site and sign in again. The setup stays.');
    }

    public function overview(): View
    {
        return view('start.overview');
    }

    public function finish(ConversionPlanner $planner): RedirectResponse
    {
        $company = Company::current();
        if (! $company->odoo_tested_at) {
            return redirect()->route('setup.odoo')->with('error', 'Test the Odoo connection before continuing.');
        }

        if (! User::query()->exists()) {
            $company->intro_acknowledged_at = $company->intro_acknowledged_at ?? now();
            $company->save();

            return redirect()->route('start.account')->with('status', 'Create a login before the checklist. The connections you entered are already saved.');
        }

        $company->preflight_passed_at = now();
        $company->intro_open = false;
        $company->save();
        $planner->syncSteps($company);

        return redirect()->route('dashboard')->with('status', 'Both connections are ready. Start with the lit button.');
    }
}
