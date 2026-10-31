<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\BadCopyReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public function edit(BadCopyReport $badCopy): View
    {
        return view('settings.edit', [
            'company' => Company::current(),
            'badCopyCount' => count($badCopy->rows()),
        ]);
    }

    public function badCopy(BadCopyReport $badCopy): View
    {
        return view('settings.bad-copy', [
            'rows' => $badCopy->rows(),
        ]);
    }

    public function badCopyDownload(BadCopyReport $badCopy): StreamedResponse
    {
        $rows = $badCopy->rows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['job', 'row', 'record', 'name', 'field', 'problem', 'batch_from', 'batch_to', 'when']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['job'] ?? '',
                    $row['row_no'] ?? '',
                    $row['record'] ?? '',
                    $row['name'] ?? '',
                    $row['field'] ?? '',
                    $row['message'] ?? '',
                    $row['batch_from'] ?? '',
                    $row['batch_to'] ?? '',
                    $row['at'] ?? '',
                ]);
            }
            fclose($out);
        }, 'bad-copy-report.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
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
