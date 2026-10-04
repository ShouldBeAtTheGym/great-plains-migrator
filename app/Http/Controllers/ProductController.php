<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DatabaseInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProductController extends Controller
{
    public function show(DatabaseInstaller $installer): View
    {
        $installed = $installer->ready();

        return view('product.show', [
            'installed' => $installed,
            'hasLogin' => $installed && User::query()->exists(),
        ]);
    }

    public function install(Request $request, DatabaseInstaller $installer): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $installer->install($data);
        } catch (RuntimeException $exception) {
            return redirect()->route('product')->withInput($request->except('password'))->with('error', $exception->getMessage());
        }

        return redirect()->route('welcome')->with('status', 'The MySQL tables are created. Start by connecting Great Plains and Odoo.');
    }
}
