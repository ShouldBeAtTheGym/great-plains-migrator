<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ConversionStep;
use App\Models\User;
use App\Services\ConnectionTester;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ConversionApiController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Those credentials do not match our records.'], 422);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function company(): JsonResponse
    {
        return response()->json(Company::current()->publicProfile());
    }

    public function testGp(Request $request, ConnectionTester $tester): JsonResponse
    {
        $company = Company::current();
        $data = $request->validate([
            'gp_server' => ['required', 'string', 'max:200'],
            'gp_database' => ['required', 'string', 'max:80'],
            'gp_username' => ['required', 'string', 'max:80'],
            'gp_password' => [$company->hasSavedGpPassword() ? 'nullable' : 'required', 'string', 'max:200'],
            'gp_trust_cert' => ['nullable', 'boolean'],
        ]);

        try {
            $report = $tester->testGp($company, $data);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'company' => $company->refresh()->publicProfile()], 422);
        }

        return response()->json([
            'message' => 'Great Plains and SalesPad are ready.',
            'salespad' => $report,
            'company' => $company->refresh()->publicProfile(),
        ]);
    }

    public function testOdoo(Request $request, ConnectionTester $tester): JsonResponse
    {
        $company = Company::current();
        $data = $request->validate([
            'odoo_url' => ['required', 'string', 'max:300'],
            'odoo_database' => ['required', 'string', 'max:80'],
            'odoo_username' => ['required', 'string', 'max:120'],
            'odoo_api_key' => [$company->hasSavedOdooKey() ? 'nullable' : 'required', 'string', 'max:200'],
            'odoo_trust_tls' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $tester->testOdoo($company, $data);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Odoo is connected.',
            'odoo' => $result,
            'company' => $company->refresh()->publicProfile(),
        ]);
    }

    public function preflight(ConversionPlanner $planner): JsonResponse
    {
        $company = Company::current();
        $report = $planner->preflight($company);
        $company->preflight_report = $report;
        $company->preflight_passed_at = $report['passed'] ? now() : null;
        $company->save();
        if ($report['passed']) {
            $planner->syncSteps($company);
        }

        return response()->json($report, $report['passed'] ? 200 : 422);
    }

    public function steps(ExportCatalog $catalog): JsonResponse
    {
        $company = Company::current();
        $current = ConversionStep::currentFor($company);
        $exports = collect($catalog->all())->keyBy('key');

        $steps = $company->steps->map(function (ConversionStep $step) use ($exports, $current) {
            $export = $exports->get($step->export_key, []);

            return [
                'key' => $step->export_key,
                'group' => $export['group'] ?? '',
                'title' => $export['title'] ?? $step->export_key,
                'odoo' => $export['odoo'] ?? '',
                'status' => $step->status,
                'current' => $current && $current->id === $step->id,
                'completed_at' => $step->completed_at?->toIso8601String(),
            ];
        });

        return response()->json(['steps' => $steps]);
    }

    public function confirm(string $key, ExportCatalog $catalog, ConversionPlanner $planner): JsonResponse
    {
        return app(\App\Http\Controllers\StepController::class)->confirm($key, $catalog, $planner);
    }
}
