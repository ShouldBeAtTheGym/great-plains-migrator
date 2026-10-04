<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Conversion checklist</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.docs-note')
            @include('partials.alerts')
            @if (request()->boolean('celebrate') && request('done'))
                <div class="alert alert-ok">{{ request('done') }} is confirmed in Odoo. The next Start button is ready.</div>
            @endif

            <div class="company-grid">
                <section class="card">
                    <h2>Great Plains</h2>
                    <strong>{{ $company->gp_site_name ?: $company->gp_company_name }}</strong>
                    <div class="muted">Database {{ $company->gp_database }} on {{ $company->gp_server }}</div>
                    @if ($company->gp_address)<div>{{ $company->gp_address }}</div>@endif
                    @if ($company->gp_phone)<div>{{ $company->gp_phone }}</div>@endif
                    <div class="muted">GP {{ $company->gp_version }} @if($company->gp_currency) · {{ $company->gp_currency }} @endif</div>
                    <div style="margin-top:.6rem;">SalesPad {{ $company->salespad_installed ? 'is installed' : 'was not detected' }}.
                        {{ $company->salespad_report['spv_count'] ?? 0 }} spv views,
                        {{ $company->salespad_report['spx_count'] ?? 0 }} spx user-field tables.
                    </div>
                </section>
                <section class="card">
                    <h2>Odoo</h2>
                    <strong>{{ $company->odoo_company_name ?: 'Odoo company' }}</strong>
                    <div class="muted">{{ $company->odoo_url }}</div>
                    <div>Database {{ $company->odoo_database }} · version {{ $company->odoo_version }}</div>
                    @if ($company->odoo_company)
                        <div>{{ $company->odoo_company['street'] ?? '' }} {{ $company->odoo_company['city'] ?? '' }} {{ $company->odoo_company['zip'] ?? '' }}</div>
                        <div class="muted">{{ $company->odoo_company['currency'] ?? '' }} {{ $company->odoo_company['country'] ?? '' }}</div>
                    @endif
                </section>
                <section class="card">
                    <h2>Progress</h2>
                    <strong>{{ $done }} of {{ $total }} files confirmed</strong>
                    <p class="muted">Work from the top. Only one Start button is lit. Recheck starts a finished file over after the imported records are removed from Odoo.</p>
                    @if ($current)
                        <a class="btn start-live" href="{{ route('steps.show', $current->export_key) }}">Start {{ $currentTitle }}</a>
                    @else
                        <p>Every file on this list is confirmed.</p>
                        <a class="btn start-live" href="{{ route('sync.show') }}">Open Step two</a>
                    @endif
                </section>
            </div>

            @if ($countError)
                <div class="alert alert-bad">{{ $countError }}</div>
            @endif

            @foreach ($groups as $group => $rows)
                <h3 style="margin:1.2rem 0 .4rem;">{{ $group }}</h3>
                @if ($group === 'Customers and vendors')
                    <p style="margin:0 0 .6rem;">
                        <a class="btn btn-primary" href="{{ route('setup.great-plains') }}">Great Plains tab</a>
                        <a class="btn btn-primary" href="{{ route('salespeople.show') }}">Match salespeople</a>
                        <a class="btn btn-primary" href="{{ route('user-fields.show', 'customers') }}">Choose SalesPad customer user fields</a>
                    </p>
                @endif
                <div class="export-wrap">
                    <table class="export-table">
                        <thead>
                            <tr>
                                <th>Export</th>
                                <th>Odoo</th>
                                <th class="count">Rows</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $count = $row['count'];
                                    $large = $count !== null && $count >= 100000;
                                @endphp
                                <tr class="{{ $row['current'] ? 'row-current' : ($row['step']->isComplete() ? 'row-done' : '') }}">
                                    <td>
                                        <div style="font-weight:700;">
                                            @if (!empty($row['export']['hint']))
                                                @include('partials.hover-hint', ['label' => $row['export']['title'], 'hint' => $row['export']['hint']])
                                            @else
                                                {{ $row['export']['title'] }}
                                            @endif
                                        </div>
                                        <div class="muted">{{ $row['export']['detail'] }}</div>
                                    </td>
                                    <td>{{ $row['export']['odoo'] }}</td>
                                    <td class="count">{{ $count === null ? '—' : number_format($count) }}</td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        @if ($row['current'])
                                            <a class="btn start-live" id="step-current" href="{{ route('steps.show', $row['export']['key']) }}">Start</a>
                                            <form method="POST" action="{{ route('steps.skip', $row['export']['key']) }}" style="display:inline;" onsubmit="return confirm('Skip this file? The checklist moves on. Nothing is changed in Odoo.');">
                                                @csrf
                                                <button class="btn btn-ghost" type="submit">Skip</button>
                                            </form>
                                            @if ($large)<div class="pill">Large file</div>@endif
                                        @elseif ($row['step']->isComplete())
                                            <button
                                                class="btn btn-ghost"
                                                type="button"
                                                data-recheck
                                                data-title="{{ $row['export']['title'] }}"
                                                data-screen="{{ $row['screen'] }}"
                                                data-action="{{ route('steps.restart', $row['export']['key']) }}"
                                            >Recheck</button>
                                        @else
                                            <span class="pill">Waiting</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            @include('partials.recheck-modal')
        </div>
    </div>
    @if (request()->boolean('celebrate'))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var button = document.querySelector('.row-current .start-live, #step-current');
                if (button) button.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (window.celebrate) {
                    window.celebrate({
                        title: @json((request('done') ?: 'Step').' is in Odoo'),
                        copy: 'The next Start button is lit.',
                        redirect: null
                    });
                }
            });
        </script>
    @endif
</x-app-layout>
