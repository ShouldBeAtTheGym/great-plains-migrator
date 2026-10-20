<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SyncRecord;
use App\Services\ExportCatalog;
use App\Services\GpConnection;
use App\Services\GpSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncController extends Controller
{
    public function show(GpSync $sync): View
    {
        $company = Company::current();
        $ready = $sync->stepOneComplete($company);

        return view('sync.index', [
            'ready' => $ready,
            'groups' => $ready ? $sync->listing($company) : [],
            'notice' => GpSync::NOTICE,
        ]);
    }

    public function baseline(string $key, GpSync $sync): RedirectResponse
    {
        return $this->back($sync, fn () => $sync->baseline(Company::current(), $key));
    }

    public function refresh(string $key, GpSync $sync): RedirectResponse
    {
        return $this->back($sync, fn () => $sync->refresh(Company::current(), $key));
    }

    public function verify(string $key, GpSync $sync): RedirectResponse
    {
        return $this->back($sync, fn () => $sync->verify(Company::current(), $key));
    }

    public function download(string $key, GpSync $sync, ExportCatalog $catalog, GpConnection $gp, ?int $part = null): StreamedResponse|RedirectResponse
    {
        $company = Company::current();
        try {
            $export = $sync->prepareDownload($company, $key, $part);
        } catch (RuntimeException $exception) {
            return redirect()->route('sync.show')->with('error', $exception->getMessage());
        }

        $filename = $export['download_name'];

        try {
            $lookup = $gp->connect($company);
            try {
                $export = $catalog->attachPartnerResIds($company, $lookup, $export);
                $export = $catalog->assignCrmEventUsers($company, $lookup, $export);
            } finally {
                sqlsrv_close($lookup);
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('sync.show')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(function () use ($catalog, $gp, $sync, $company, $export) {
            @set_time_limit(0);
            $connection = $gp->connect($company);
            try {
                $known = $export['known_keys'];
                if (is_array($known)) {
                    $profile = $sync->profile($export['key']);
                    $skip = array_fill_keys($known, true);
                    $catalog->stream($connection, $export, function (array $row) use (&$skip, $sync, $profile, $company, $export) {
                        $recordKey = $sync->recordKey($row, $profile['identity']);
                        if ($recordKey === '' || isset($skip[$recordKey])) {
                            return false;
                        }
                        SyncRecord::query()->firstOrCreate(
                            [
                                'company_id' => $company->id,
                                'export_key' => $export['key'],
                                'record_key' => $recordKey,
                            ],
                            ['exported_at' => null],
                        );
                        $skip[$recordKey] = true;

                        return true;
                    });
                } else {
                    $catalog->stream($connection, $export);
                }
            } finally {
                if ($connection) {
                    sqlsrv_close($connection);
                }
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function back(GpSync $sync, callable $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (RuntimeException $exception) {
            return redirect()->route('sync.show')->with('error', $exception->getMessage());
        }

        return redirect()->route('sync.show')->with('status', $message);
    }
}
