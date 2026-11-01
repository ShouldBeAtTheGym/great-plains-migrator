@php
    $jobs = $uploads['jobs'] ?? [];
    $limited = (bool) ($uploads['limited'] ?? false);
@endphp
@if ($jobs !== [])
    <div class="card" id="api-uploads" data-url="{{ route('uploads') }}" style="margin:1rem 0;">
        <h3 style="margin-top:0">Server uploads</h3>
        <p class="muted" id="api-upload-limit" @if (! $limited) hidden @endif>Only two jobs can run at a time.</p>
        <div class="export-wrap">
            <table class="export-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th class="count">Loaded</th>
                        <th class="count">Total</th>
                        <th class="count">Remaining</th>
                        <th>Finishes</th>
                    </tr>
                </thead>
                <tbody id="api-upload-rows">
                    @foreach ($jobs as $job)
                        <tr>
                            <td>{{ $job['title'] }}</td>
                            <td class="count">{{ number_format($job['loaded']) }}</td>
                            <td class="count">{{ number_format($job['total']) }}</td>
                            <td class="count">{{ number_format($job['remaining']) }}</td>
                            <td>
                                @if ($job['state'] === 'waiting')
                                    Only two jobs can run at a time
                                @elseif ($job['state'] === 'retrying')
                                    {{ $job['note'] !== '' ? $job['note'] : 'Retrying' }}
                                @elseif (($job['finish'] ?? '') !== '')
                                    {{ $job['finish'] }}
                                @elseif ($job['state'] === 'running' && (int) ($job['loaded'] ?? 0) === 0)
                                    Waiting for the other job
                                @elseif ($job['state'] === 'running')
                                    Uploading
                                @else
                                    Stopped
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-bottom:0;">Remaining is the total minus the rows already copied into Odoo.</p>
    </div>
    <script>
        (function () {
            var card = document.getElementById('api-uploads');
            if (!card) return;
            var number = new Intl.NumberFormat();
            function cell(text) {
                var item = document.createElement('td');
                item.textContent = text;
                return item;
            }
            function paint(body) {
                var jobs = body.jobs || [];
                var limit = document.getElementById('api-upload-limit');
                var rows = document.getElementById('api-upload-rows');
                if (!jobs.length) {
                    card.hidden = true;
                    return;
                }
                card.hidden = false;
                if (limit) limit.hidden = !body.limited;
                rows.textContent = '';
                jobs.forEach(function (job) {
                    var row = document.createElement('tr');
                    var when = job.state === 'waiting'
                        ? 'Only two jobs can run at a time'
                        : (job.state === 'retrying'
                            ? (job.note || 'Retrying')
                            : (job.finish ? job.finish : (job.state === 'running' ? (job.loaded ? 'Uploading' : 'Waiting for the other job') : 'Stopped')));
                    [job.title, number.format(job.loaded || 0), number.format(job.total || 0), number.format(job.remaining || 0), when]
                        .forEach(function (value) { row.appendChild(cell(value)); });
                    rows.appendChild(row);
                });
            }
            async function refresh() {
                try {
                    var response = await fetch(card.dataset.url, { headers: { 'Accept': 'application/json' } });
                    if (!response.ok) return;
                    paint(await response.json());
                } catch (error) {}
            }
            setInterval(refresh, 8000);
        })();
    </script>
@endif
