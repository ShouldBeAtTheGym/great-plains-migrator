<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', [
            'company' => Company::current(),
        ]);
    }

    public function resetIntro(): RedirectResponse
    {
        $company = Company::current();
        $company->intro_open = true;
        $company->save();

        return redirect()
            ->route('welcome')
            ->with('status', 'The intro guide is open again. Saved connections and the checklist were kept.');
    }
}
