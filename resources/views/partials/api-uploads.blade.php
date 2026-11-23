@php
    $jobs = $uploads['jobs'] ?? [];
    $current = $uploads['current'] ?? null;
    $waiting = array_values(array_filter($jobs, static fn (array $job): bool => ($job['state'] ?? '') === 'waiting'));
    $showWaiting = $waiting !== [];
    $limited = (bool) ($uploads['limited'] ?? false);
    $stateLabel = function (string $state): string {
        return match ($state) {
            'running' => 'Running',
            'retrying' => 'Retrying',
            'waiting' => 'Waiting',
            'done' => 'Finished',
            'stopped' => 'Stopped',
            default => 'Not running',
        };
    };
@endphp
@if ($jobs !== [] || ($alwaysShowService ?? false))
    <div class="card {{ $current ? 'gp-copy' : '' }} {{ ($current['state'] ?? '') === 'running' ? 'is-running' : '' }}" id="api-uploads" data-url="{{ route('uploads') }}" style="margin:1rem 0;">
        <p class="muted" id="api-upload-limit" @if (! $limited) hidden @endif>Only two jobs can run at a time.</p>
        <div id="service-current">
            @if ($current)
                <div class="gp-copy-top">
                    <h3>{{ $current['title'] }}</h3>
                    <div class="gp-copy-percent">{{ (int) ($current['percent'] ?? 0) }}%</div>
                </div>
                <p class="muted" style="margin:0;">
                    <span class="service-unit">{{ $current['unit'] }}</span>
                    · {{ $stateLabel($current['state']) }}
                    @if (($current['when'] ?? '') !== '') · Updated {{ $current['when'] }} @endif
                </p>
                <div class="gp-copy-bar"><span style="width: {{ (int) ($current['percent'] ?? 0) }}%"></span></div>
                <div class="gp-copy-stats">
                    <div>
                        <span>Loaded</span>
                        <strong>{{ number_format($current['loaded'] ?? 0) }}</strong>
                    </div>
                    <div>
                        <span>Remaining</span>
                        <strong>{{ number_format($current['remaining'] ?? 0) }}</strong>
                    </div>
                    <div class="gp-copy-current">
                        <span>Finishes</span>
                        <strong>{{ ($current['finish'] ?? '') !== '' ? $current['finish'] : '—' }}</strong>
                    </div>
                </div>
                @if (($current['note'] ?? '') !== '')
                    <p class="muted" style="margin: .8rem 0 0;">{{ $current['note'] }}</p>
                @endif
            @else
                <h3 style="margin-top:0">Upload service</h3>
                <p class="muted" style="margin:0;">No upload is running.</p>
            @endif
        </div>
        <h3 id="service-waiting-heading" style="margin:1.2rem 0 0;" @unless ($showWaiting) hidden @endunless>Waiting</h3>
        <ul class="gp-copy-queue" id="service-waiting" @unless ($showWaiting) hidden @endunless>
            @foreach ($waiting as $job)
                <li>
                    <span>{{ $job['title'] ?? '' }} <span class="service-unit">{{ $job['unit'] ?? '' }}</span></span>
                    <strong>{{ 'Waiting'.(((int) ($job['total'] ?? 0)) > 0 ? ' · '.number_format($job['total']) : '') }}</strong>
                </li>
            @endforeach
        </ul>
    </div>
    <script>
        (function () {
            var card = document.getElementById('api-uploads');
            if (!card) return;
            var number = new Intl.NumberFormat();
            var labels = { running: 'Running', retrying: 'Retrying', waiting: 'Waiting', done: 'Finished', stopped: 'Stopped' };
            function label(state) { return labels[state] || 'Not running'; }
            function paintCurrent(current) {
                var box = document.getElementById('service-current');
                if (!box) return;
                card.classList.toggle('gp-copy', !!current);
                card.classList.toggle('is-running', !!(current && current.state === 'running'));
                box.textContent = '';
                if (!current) {
                    var heading = document.createElement('h3');
                    heading.style.marginTop = '0';
                    heading.textContent = 'Upload service';
                    var empty = document.createElement('p');
                    empty.className = 'muted';
                    empty.style.margin = '0';
                    empty.textContent = 'No upload is running.';
                    box.appendChild(heading);
                    box.appendChild(empty);
                    return;
                }
                var top = document.createElement('div');
                top.className = 'gp-copy-top';
                var title = document.createElement('h3');
                title.textContent = current.title || '';
                var percent = document.createElement('div');
                percent.className = 'gp-copy-percent';
                percent.textContent = (current.percent || 0) + '%';
                top.appendChild(title);
                top.appendChild(percent);
                var lead = document.createElement('p');
                lead.className = 'muted';
                lead.style.margin = '0';
                var unit = document.createElement('span');
                unit.className = 'service-unit';
                unit.textContent = current.unit || '';
                lead.appendChild(unit);
                lead.appendChild(document.createTextNode(' · ' + label(current.state) + (current.when ? ' · Updated ' + current.when : '')));
                var bar = document.createElement('div');
                bar.className = 'gp-copy-bar';
                var fill = document.createElement('span');
                fill.style.width = (current.percent || 0) + '%';
                bar.appendChild(fill);
                var stats = document.createElement('div');
                stats.className = 'gp-copy-stats';
                [['Loaded', number.format(current.loaded || 0), ''], ['Remaining', number.format(current.remaining || 0), ''], ['Finishes', current.finish || '—', 'gp-copy-current']].forEach(function (pair) {
                    var item = document.createElement('div');
                    if (pair[2]) item.className = pair[2];
                    var name = document.createElement('span');
                    name.textContent = pair[0];
                    var value = document.createElement('strong');
                    value.textContent = pair[1];
                    item.appendChild(name);
                    item.appendChild(value);
                    stats.appendChild(item);
                });
                box.appendChild(top);
                box.appendChild(lead);
                box.appendChild(bar);
                box.appendChild(stats);
                if (current.note) {
                    var note = document.createElement('p');
                    note.className = 'muted';
                    note.style.margin = '.8rem 0 0';
                    note.textContent = current.note;
                    box.appendChild(note);
                }
            }
            function paintWaiting(jobs) {
                var list = document.getElementById('service-waiting');
                var heading = document.getElementById('service-waiting-heading');
                if (!list) return;
                var waiting = (jobs || []).filter(function (job) { return job.state === 'waiting'; });
                list.textContent = '';
                var show = waiting.length > 0;
                list.hidden = !show;
                if (heading) heading.hidden = !show;
                waiting.forEach(function (job) {
                    var line = document.createElement('li');
                    var name = document.createElement('span');
                    name.textContent = job.title || '';
                    if (job.unit) {
                        var unit = document.createElement('span');
                        unit.className = 'service-unit';
                        unit.textContent = ' ' + job.unit;
                        name.appendChild(unit);
                    }
                    var state = document.createElement('strong');
                    state.textContent = 'Waiting' + (job.total ? ' · ' + number.format(job.total) : '');
                    line.appendChild(name);
                    line.appendChild(state);
                    list.appendChild(line);
                });
            }
            function paint(body) {
                var limit = document.getElementById('api-upload-limit');
                if (limit) limit.hidden = !body.limited;
                paintCurrent(body.current || null);
                paintWaiting(body.jobs || []);
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
