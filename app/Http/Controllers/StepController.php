<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StepController extends Controller
{
    public function show(string $key, ExportCatalog $catalog, ConversionPlanner $planner, GpConnection $gp, OdooClient $odoo): View
    {
        $company = Company::current();
        $export = $this->export($catalog, $key);
        $step = $this->step($company, $key);
        $current = ConversionStep::currentFor($company);
        $guide = $planner->guide($key, $export['odoo']);

        $columns = [];
        $sample = [];
        $count = null;
        $problemCount = null;
        $problems = [];
        $loadError = null;
        try {
            $export = $catalog->prepare($company, $export);
            $connection = $gp->connect($company);
            try {
                $columns = $catalog->columns($connection, $export);
                $countRows = $gp->rows($connection, $export['count_sql'], 1);
                $count = isset($countRows[0]['c']) ? (int) $countRows[0]['c'] : null;
                if ($count !== null && $count > 0 && $count < 5000) {
                    $sample = $catalog->sample($connection, $export, 3);
                }
                if (! empty($export['problem_count_sql'])) {
                    $problemRows = $gp->rows($connection, $export['problem_count_sql'], 1);
                    $problemCount = isset($problemRows[0]['c']) ? (int) $problemRows[0]['c'] : 0;
                    if ($problemCount > 0) {
                        $preview = 'SELECT TOP 50 * FROM ('.$export['problem_sql'].') AS problem_src ORDER BY document_date, document';
                        $problems = $this->problemRows($gp->rows($connection, $preview, 50));
                    }
                }
            } finally {
                sqlsrv_close($connection);
            }
        } catch (RuntimeException $exception) {
            $loadError = $exception->getMessage();
        }

        $odooCompanies = [];
        $companyLoadError = null;
        if ($key === 'customers') {
            try {
                $odooCompanies = $this->odooCompanies($company, $odoo);
            } catch (RuntimeException $exception) {
                $companyLoadError = $exception->getMessage();
            }
        }

        return view('steps.show', [
            'company' => $company,
            'export' => $export,
            'step' => $step,
            'current' => $current && $current->id === $step->id,
            'guide' => $guide,
            'columns' => $columns,
            'sample' => $sample,
            'count' => $count,
            'problemCount' => $problemCount,
            'problems' => $problems,
            'parts' => $catalog->partCount($count),
            'loadError' => $loadError,
            'odooCompanies' => $odooCompanies,
            'companyLoadError' => $companyLoadError,
        ]);
    }

    public function importCompany(Request $request, string $key, OdooClient $odoo): RedirectResponse
    {
        abort_unless($key === 'customers', 404);

        $company = Company::current();
        $chosen = (int) $request->input('odoo_import_company_id');
        $match = null;
        try {
            foreach ($this->odooCompanies($company, $odoo) as $row) {
                if ((int) $row['id'] === $chosen) {
                    $match = $row;
                    break;
                }
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('steps.show', $key)->with('error', $exception->getMessage());
        }

        if (! is_array($match)) {
            return redirect()->route('steps.show', $key)->with('error', 'Choose one of the companies listed in Odoo.');
        }

        $company->update([
            'odoo_import_company_id' => (int) $match['id'],
            'odoo_import_company_name' => $match['name'],
        ]);

        return redirect()
            ->route('steps.show', $key)
            ->with('status', 'Customers will import into '.$match['name'].'. Download the file again. The company_id column is '.$match['name'].'.');
    }

    public function download(string $key, ExportCatalog $catalog, GpConnection $gp, ?int $part = null): StreamedResponse|RedirectResponse
    {
        $company = Company::current();
        if ($key === 'customers' && ! $company->odoo_import_company_id) {
            return redirect()
                ->route('steps.show', $key)
                ->with('error', 'Choose the Odoo company for this import before downloading the file.');
        }

        $export = $this->export($catalog, $key);
        $step = $this->step($company, $key);
        $current = ConversionStep::currentFor($company);
        if (! $step->isComplete() && (! $current || $current->id !== $step->id)) {
            abort(403, 'This file opens when it is the current step.');
        }

        try {
            $export = $catalog->prepare($company, $export);
        } catch (RuntimeException $exception) {
            return redirect()->route('steps.show', $key)->with('error', $exception->getMessage());
        }

        $countRows = [];
        try {
            $connection = $gp->connect($company);
            try {
                $countRows = $gp->rows($connection, $export['count_sql'], 1);
            } finally {
                sqlsrv_close($connection);
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('steps.show', $key)->with('error', $exception->getMessage());
        }
        $count = isset($countRows[0]['c']) ? (int) $countRows[0]['c'] : 0;
        $parts = $catalog->partCount($count);
        if ($parts > 1 && $part === null) {
            return redirect()
                ->route('steps.show', $key)
                ->with('error', 'This file is too large for one Odoo upload. Download one part at a time.');
        }
        if ($part !== null && ($part < 1 || $part > $parts)) {
            abort(404);
        }
        if ($part !== null) {
            $export['sql'] = $catalog->partSql($export['sql'], $part);
        }

        $filename = $catalog->filename($export, $parts > 1 ? $part : null);

        return response()->streamDownload(function () use ($catalog, $gp, $company, $export) {
            @set_time_limit(0);
            $connection = $gp->connect($company);
            try {
                $catalog->stream($connection, $export);
            } finally {
                sqlsrv_close($connection);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function problems(string $key, ExportCatalog $catalog, GpConnection $gp): StreamedResponse
    {
        $company = Company::current();
        $export = $this->export($catalog, $key);
        $this->step($company, $key);
        abort_unless(! empty($export['problem_sql']), 404);

        $report = [
            'file' => $export['problem_file'],
            'sql' => $export['problem_sql'].' ORDER BY document_date, document',
            'extra' => [],
        ];

        return response()->streamDownload(function () use ($catalog, $gp, $company, $report) {
            @set_time_limit(0);
            $connection = $gp->connect($company);
            try {
                $catalog->stream($connection, $report);
            } finally {
                sqlsrv_close($connection);
            }
        }, $catalog->filename($report), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function confirm(string $key, ExportCatalog $catalog, ConversionPlanner $planner): JsonResponse
    {
        $company = Company::current();
        $export = $this->export($catalog, $key);
        $step = $this->step($company, $key);
        $current = ConversionStep::currentFor($company);

        if ($step->isComplete() || ! $current || $current->id !== $step->id) {
            return response()->json([
                'ok' => false,
                'message' => 'Finish the earlier steps first. Only the lit Start button can be confirmed.',
            ], 422);
        }

        try {
            $result = $planner->confirm($company, $export);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        if (! $result['ok']) {
            $step->update([
                'odoo_checked' => $result['checked'],
                'odoo_found' => $result['found'],
                'confirm_message' => $result['message'],
            ]);

            return response()->json($result, 422);
        }

        $step->update([
            'status' => 'complete',
            'completed_at' => $step->completed_at ?? now(),
            'odoo_checked' => $result['checked'],
            'odoo_found' => $result['found'],
            'confirm_message' => $result['message'],
        ]);

        return response()->json($result + [
            'redirect' => route('dashboard', ['celebrate' => 1, 'done' => $export['title']]),
        ]);
    }

    public function skip(string $key, ExportCatalog $catalog): RedirectResponse
    {
        $company = Company::current();
        $export = $this->export($catalog, $key);
        $step = $this->step($company, $key);
        $current = ConversionStep::currentFor($company);

        if ($step->isComplete() || ! $current || $current->id !== $step->id) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Only the lit Start step can be skipped.');
        }

        $step->update([
            'status' => 'complete',
            'completed_at' => $step->completed_at ?? now(),
            'confirm_message' => 'Skipped. This file was not confirmed in Odoo.',
        ]);

        return redirect()
            ->route('dashboard')
            ->with('status', $export['title'].' was skipped. The next Start button is ready. Nothing was changed in Odoo.');
    }

    public function restart(string $key, ExportCatalog $catalog, ConversionPlanner $planner): RedirectResponse
    {
        @set_time_limit(180);
        $company = Company::current();
        $export = $this->export($catalog, $key);
        $step = $this->step($company, $key);

        if (! $step->isComplete()) {
            return redirect()->route('steps.show', $key);
        }

        $cleanup = $planner->removeImported($company, $export);
        $step->update([
            'status' => 'pending',
            'completed_at' => null,
            'odoo_checked' => null,
            'odoo_found' => null,
            'confirm_message' => null,
        ]);

        return redirect()
            ->route('steps.show', $key)
            ->with('status', 'This step is back at the start. '.$cleanup['message']);
    }

    private function odooCompanies(Company $company, OdooClient $odoo): array
    {
        $rows = $odoo->execute($company, 'res.company', 'search_read', [[]], [
            'fields' => ['id', 'name'],
            'limit' => 50,
            'order' => 'name',
        ]);

        $companies = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $companies[] = ['id' => $id, 'name' => $name];
            }
        }

        return $companies;
    }

    private function problemRows(array $rows): array
    {
        $problems = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $column => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                $line[$column] = $value === null ? '' : (string) $value;
            }
            $problems[] = $line;
        }

        return $problems;
    }

    private function export(ExportCatalog $catalog, string $key): array
    {
        $export = $catalog->find($key);
        abort_if($export === null, 404);

        return $export;
    }

    private function step(Company $company, string $key): ConversionStep
    {
        $step = $company->steps()->where('export_key', $key)->first();
        abort_if($step === null, 404);

        return $step;
    }
}
