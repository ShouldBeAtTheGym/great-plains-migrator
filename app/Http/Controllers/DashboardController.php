<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use Illuminate\View\View;
use RuntimeException;

class DashboardController extends Controller
{
    public function __invoke(ExportCatalog $catalog, ConversionPlanner $planner): View
    {
        $company = Company::current();
        if ($company->steps()->count() === 0) {
            $planner->syncSteps($company);
        }

        $counts = [];
        $countError = null;
        try {
            $counts = $planner->counts($company);
        } catch (RuntimeException $exception) {
            $countError = $exception->getMessage();
        }

        $steps = $company->steps()->get()->keyBy('export_key');
        $current = ConversionStep::currentFor($company);
        $groups = [];
        foreach ($catalog->all() as $export) {
            $step = $steps->get($export['key']);
            if (! $step) {
                continue;
            }
            $groups[$export['group']][] = [
                'export' => $export,
                'step' => $step,
                'count' => $counts[$export['key']] ?? null,
                'current' => $current && $current->id === $step->id,
                'screen' => $planner->guide($export['key'], $export['odoo'])['screen'],
            ];
        }

        $done = $company->steps()->where('status', 'complete')->count();
        $total = $company->steps()->count();
        $currentTitle = $current ? ($catalog->find($current->export_key)['title'] ?? $current->export_key) : null;

        return view('dashboard', [
            'company' => $company,
            'groups' => $groups,
            'current' => $current,
            'currentTitle' => $currentTitle,
            'done' => $done,
            'total' => $total,
            'countError' => $countError,
        ]);
    }
}
