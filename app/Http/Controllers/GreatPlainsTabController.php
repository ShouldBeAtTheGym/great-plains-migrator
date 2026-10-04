<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\GreatPlainsTab;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class GreatPlainsTabController extends Controller
{
    public function show(GreatPlainsTab $tab): View
    {
        $company = Company::current();
        $choices = ['customer_class' => [], 'shipping_method' => []];
        $loadError = null;
        try {
            $choices = $tab->choices($company);
        } catch (RuntimeException $exception) {
            $loadError = $exception->getMessage();
        }

        return view('setup.great-plains', [
            'company' => $company,
            'choices' => $choices,
            'loadError' => $loadError,
        ]);
    }

    public function store(GreatPlainsTab $tab): RedirectResponse
    {
        $company = Company::current();
        try {
            $choices = $tab->choices($company);
            $message = $tab->create($company, $choices);
        } catch (RuntimeException $exception) {
            return redirect()->route('setup.great-plains')->with('error', $exception->getMessage());
        }

        $company->gp_tab_created_at = now();
        $company->save();

        return redirect()->route('setup.great-plains')->with('status', $message);
    }
}
