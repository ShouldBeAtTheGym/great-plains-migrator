<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Step two</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <div class="read-note">
                <p>{{ $notice }}</p>
                <p>This sync adds new Great Plains rows. It does not remove or change rows that are already in Odoo.</p>
            </div>

            @unless ($ready)
                <section class="card">
                    <h2>Finish Step One first</h2>
                    <p>Step two starts after every file on the checklist is confirmed. The first import is Step One. This page is the ongoing resync after that.</p>
                    <a class="btn btn-primary" href="{{ route('dashboard') }}">Back to the checklist</a>
                </section>
            @else
                <section class="card" style="margin-bottom:1rem;">
                    <h2>Ongoing resync</h2>
                    <p>Record the rows that Step One already imported. After that, refresh a file to count new rows. Download those rows in the same export shape, import them in Odoo, then verify. Verification compares the Great Plains count, the exported count, and the Odoo count.</p>
                    <p class="muted">SalesPad views are used when they exist. Every user field on that view, and every user field on the matching spx table, is saved here even when the Odoo file does not use it.</p>
                </section>

                @foreach ($groups as $group => $rows)
                    <h3 style="margin:1.2rem 0 .4rem;">{{ $group }}</h3>
                    <div class="export-wrap">
                        <table class="export-table">
                            <thead>
                                <tr>
                                    <th>Export</th>
                                    <th>Last export</th>
                                    <th class="count">Record count</th>
                                    <th class="count">New records</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php
                                        $sync = $row['sync'];
                                        $recorded = $sync && $sync->capture_done;
                                        $count = $sync && $sync->gp_count !== null ? $sync->gp_count : ($recorded ? $sync->record_count : null);
                                        $newCount = $recorded ? $sync->new_count : null;
                                    @endphp
                                    <tr>
                                        <td>
                                            <div style="font-weight:700;">{{ $row['export']['title'] }}</div>
                                            @if ($sync && $sync->verify_message)
                                                <div class="{{ $sync->verify_ok ? 'muted' : 'skip' }}">{{ $sync->verify_message }}</div>
                                            @endif
                                            @if ($sync && $sync->user_field_note)
                                                <div class="muted">{{ $sync->user_field_note }}</div>
                                            @endif
                                            @if ($sync && ! $sync->capture_done && $sync->capture_offset > 0)
                                                <div class="muted">Recorded {{ number_format($sync->capture_offset) }} of {{ $count === null ? '—' : number_format($count) }} so far.</div>
                                            @endif
                                        </td>
                                        <td>{{ $sync && $sync->last_export_at ? $sync->last_export_at->timezone('America/New_York')->format('M j, Y g:i A') : '—' }}</td>
                                        <td class="count">{{ $count === null ? '—' : number_format($count) }}</td>
                                        <td class="count">{{ $newCount === null ? '—' : number_format($newCount) }}</td>
                                        <td style="text-align:right;">
                                            <div class="step-actions" style="justify-content:flex-end;">
                                                <form method="POST" action="{{ route('sync.baseline', $row['export']['key']) }}">
                                                    @csrf
                                                    <button class="btn btn-ghost" type="submit">{{ $sync && $sync->capture_offset > 0 && ! $sync->capture_done ? 'Continue recording' : 'Record current' }}</button>
                                                </form>
                                                @if ($recorded)
                                                    <form method="POST" action="{{ route('sync.refresh', $row['export']['key']) }}">
                                                        @csrf
                                                        <button class="btn btn-ghost" type="submit">Refresh count</button>
                                                    </form>
                                                    @if ($newCount > 0 && $row['parts'] > 1)
                                                        @for ($part = 1; $part <= $row['parts']; $part++)
                                                            <a class="btn btn-primary" href="{{ route('sync.download', ['key' => $row['export']['key'], 'part' => $part]) }}">Part {{ $part }}</a>
                                                        @endfor
                                                    @elseif ($newCount > 0)
                                                        <a class="btn btn-primary" href="{{ route('sync.download', $row['export']['key']) }}">Download new</a>
                                                    @endif
                                                    <form method="POST" action="{{ route('sync.verify', $row['export']['key']) }}">
                                                        @csrf
                                                        <button class="btn start-live" type="submit">Verify</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @endunless
        </div>
    </div>
</x-app-layout>
