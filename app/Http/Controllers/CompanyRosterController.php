<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyRoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyRosterController extends Controller
{
    public function template(): StreamedResponse
    {
        return $this->csv('employee_roster_template.csv', function ($out) {
            fputcsv($out, ['external_id', 'name', 'job_title', 'email', 'manager_external_id', 'department_id', 'department_name', 'department_parent_id']);
            fputcsv($out, ['__import__.emp_ada_example_com', 'Ada Example', 'Sales Director', 'ada@example.com', '', 'department_sales', 'Sales', 'department_executive']);
            fputcsv($out, ['__import__.emp_max_example_com', 'Max Example', 'Owner', 'max@example.com', '', 'department_executive', 'Executive', '']);
        });
    }

    public function store(Request $request, CompanyRoster $roster): RedirectResponse
    {
        $request->validate([
            'roster' => ['required', 'file', 'extensions:csv,txt', 'max:2048'],
        ]);
        $contents = (string) file_get_contents($request->file('roster')->getRealPath());
        $first = preg_split('/\R/', $contents, 2)[0] ?? '';
        $columns = array_map(static fn ($column) => strtolower(trim((string) $column)), str_getcsv((string) $first));
        foreach (['external_id', 'name', 'email', 'department_id'] as $required) {
            if (! in_array($required, $columns, true)) {
                return back()->with('error', 'The employee file needs columns for external id, name, email, and department.');
            }
        }
        $company = Company::current();
        $path = $roster->path($company);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $contents);

        return back()->with('status', 'The employee list is saved. Download the departments, then the employees.');
    }

    public function departments(CompanyRoster $roster): StreamedResponse
    {
        $company = Company::current();
        [$column, $value] = $roster->companyExternalId($company);

        return $this->csv('odoo_departments_'.date('Ymd').'.csv', function ($out) use ($roster, $company, $column, $value) {
            fputcsv($out, ['id', 'name', 'parent_id/id', $column]);
            foreach ($roster->departments($company) as $department) {
                fputcsv($out, [$department['id'], $department['name'], $department['parent_id'], $value]);
            }
        });
    }

    public function employees(CompanyRoster $roster): StreamedResponse
    {
        $company = Company::current();
        [$column, $value] = $roster->companyExternalId($company);
        $rows = $roster->employees($company);

        return $this->csv('odoo_employees_'.date('Ymd').'.csv', function ($out) use ($rows, $column, $value) {
            fputcsv($out, ['id', 'name', 'job_title', 'work_email', 'department_id/id', 'parent_id/id', 'user_id/.id', $column]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['name'],
                    $row['job_title'],
                    $row['work_email'],
                    $row['department_id'],
                    $row['parent_id'],
                    $row['user_id'],
                    $value,
                ]);
            }
        });
    }

    private function csv(string $filename, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
