<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bad copy report</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="card">
                <p class="lead">Rows Odoo rejected while an upload was running. The upload stays stopped on that row.</p>
                @if ($rows === [])
                    <p>No bad rows.</p>
                @else
                    <p><a class="btn btn-primary" href="{{ route('settings.bad-copy.download') }}">Download CSV</a></p>
                    <div class="export-wrap">
                        <table class="export-table">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Row</th>
                                    <th>Record</th>
                                    <th>Name</th>
                                    <th>Field</th>
                                    <th>Problem</th>
                                    <th>When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td>{{ $row['job'] ?? '' }}</td>
                                        <td>{{ $row['row_no'] ?? '' }}</td>
                                        <td>{{ $row['record'] ?? '' }}</td>
                                        <td>{{ $row['name'] ?? '' }}</td>
                                        <td>{{ $row['field'] ?? '' }}</td>
                                        <td>{{ $row['message'] ?? '' }}</td>
                                        <td>{{ $row['at'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p style="margin-top:1rem;"><a href="{{ route('settings.edit') }}">Back to settings</a></p>
            </div>
        </div>
    </div>
</x-app-layout>
