<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ConversionStep;
use App\Services\ApiUploadQueue;
use App\Services\ConversionPlanner;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use App\Services\GpCopyService;
use App\Services\OdooClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class DashboardController extends Controller
{
    public function __invoke(ExportCatalog $catalog, ConversionPlanner $planner, GpCopyService $copies, ApiUploadQueue $uploads, GpConnection $gp, OdooClient $odoo): View
    {
        $company = Company::current();
        $savedKeys = $company->steps()->pluck('export_key')->all();
        $catalogKeys = array_column($catalog->all(), 'key');
        if ($savedKeys === [] || array_diff($catalogKeys, $savedKeys) !== []) {
            $planner->syncSteps($company);
        }

        $current = ConversionStep::currentFor($company);
        $copies->kickoffCatalog($company);

        $counts = [];
        $countError = null;
        try {
            $counts = $planner->counts($company);
        } catch (RuntimeException $exception) {
            $countError = $exception->getMessage();
        }

        $steps = $company->steps()->get()->keyBy('export_key');
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
            'queue' => $copies->catalogQueue($company),
            'uploads' => $uploads->status(),
            'gpCompanies' => $gp->companyList($company),
            'odooCompanies' => $this->odooCompanies($company, $odoo),
        ]);
    }

    /**
     * @return list<array{name: string, parent: string}>
     */
    private function odooCompanies(Company $company, OdooClient $odoo): array
    {
        $fallback = $company->odoo_company_name
            ? [['name' => $company->odoo_company_name, 'parent' => '']]
            : [];
        try {
            $rows = $odoo->companies($company);
        } catch (RuntimeException) {
            return $fallback;
        }

        $list = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $parent = is_array($row['parent_id'] ?? null) ? trim((string) ($row['parent_id'][1] ?? '')) : '';
            $list[] = ['name' => $name, 'parent' => $parent];
        }
        usort($list, function (array $left, array $right): int {
            $leftBranch = $left['parent'] !== '' ? 1 : 0;
            $rightBranch = $right['parent'] !== '' ? 1 : 0;
            if ($leftBranch !== $rightBranch) {
                return $leftBranch <=> $rightBranch;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $list !== [] ? $list : $fallback;
    }

    public function uploads(ApiUploadQueue $uploads): JsonResponse
    {
        return response()->json($uploads->status());
    }

    public function copies(GpCopyService $copies): JsonResponse
    {
        return response()->json($copies->catalogQueue(Company::current()));
    }

    public function startCopies(Request $request, GpCopyService $copies): JsonResponse
    {
        $company = Company::current();
        if ($request->boolean('retry')) {
            $copies->retryFailed($company);
        }
        $copies->kickoffCatalog($company);

        return response()->json($copies->catalogQueue($company));
    }
}
