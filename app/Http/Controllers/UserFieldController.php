<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\SalespadUserFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class UserFieldController extends Controller
{
    public function show(string $model, SalespadUserFields $fields): View
    {
        $definition = $fields->model($model);
        $company = Company::current();
        $error = null;
        try {
            $fields->sync($company, $model);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return view('user-fields.customers', [
            'modelKey' => $model,
            'definition' => $definition,
            'fields' => $fields->chosen($company, $model),
            'types' => SalespadUserFields::TYPES,
            'loadError' => $error,
        ]);
    }

    public function update(Request $request, string $model, SalespadUserFields $fields): RedirectResponse
    {
        $fields->model($model);
        $company = Company::current();
        $request->validate([
            'fields' => ['nullable', 'array'],
            'fields.*.field_type' => ['required', 'in:text,selection,boolean,number'],
            'fields.*.options' => ['nullable', 'string', 'max:8000'],
            'fields.*.selected' => ['nullable'],
        ]);

        try {
            $fields->saveChoices($company, $model, $request->input('fields', []));
            if ($request->input('action') === 'create') {
                @set_time_limit(180);
                $message = $fields->createInOdoo($company, $model);

                return redirect()->route('user-fields.show', $model)->with('status', $message);
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('user-fields.show', $model)->with('error', $exception->getMessage());
        }

        return redirect()->route('user-fields.show', $model)->with('status', 'The user field list is saved. Nothing has been created in Odoo yet.');
    }
}
