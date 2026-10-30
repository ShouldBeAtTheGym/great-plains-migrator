<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyRoster;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyRosterController extends Controller
{
    public function departments(CompanyRoster $roster): StreamedResponse
    {
        $company = Company::current();
        [$column, $value] = $roster->companyExternalId($company);

        return $this->csv('odoo_departments_'.date('Ymd').'.csv', function ($out) use ($roster, $column, $value) {
            fputcsv($out, ['id', 'name', 'parent_id/id', $column]);
            foreach ($roster->departments() as $department) {
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
