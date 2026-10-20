@php
    $active = $queue['active'] ?? null;
    $failed = $queue['failed'] ?? [];
    $done = (bool) ($queue['done'] ?? false);
    $copying = ($active['status'] ?? '') === 'running';
    $heading = $done
        ? 'Every file is saved in MySQL'
        : ($copying
            ? 'Copying '.$active['title'].' from Great Plains'
            : ($active ? 'Next file is '.$active['title'] : 'No file is copying right now'));
    $failedNote = collect($failed)->map(function ($item) {
        return $item['title'].' stopped at '.number_format($item['processed_count']).' of '.number_format($item['record_count']).'.';
    })->implode(' ');
    if ($failedNote !== '' && $copying) {
        $failedNote .= ' That file is not the one being copied. The service is copying '.$active['title'].'.';
    }
@endphp
<div
    id="gp-copy"
    class="card gp-copy {{ $done ? 'is-done' : '' }} {{ $copying ? 'is-running' : '' }}"
    data-status-url="{{ route('copies') }}"
    data-start-url="{{ route('copies.start') }}"
    data-reload="{{ !empty($reload) ? '1' : '0' }}"
    data-focus="{{ $focus ?? '' }}"
    data-focus-state="{{ collect($queue['steps'])->firstWhere('key', $focus ?? '')['status'] ?? '' }}"
    data-state="{{ $done ? 'complete' : ($active['status'] ?? 'missing') }}"
>
    <div class="gp-copy-top">
        <h3 id="gp-copy-heading">{{ $heading }}</h3>
        <div class="gp-copy-percent" id="gp-copy-percent">{{ $done ? '100%' : ((($active['percent'] ?? null) === null) ? '' : $active['percent'].'%') }}</div>
    </div>
    <p class="muted" id="gp-copy-lead">
        File <span id="gp-copy-place">{{ $done ? (int) ($queue['total'] ?? 0) : (int) ($queue['place'] ?? 0) }}</span> of {{ (int) ($queue['total'] ?? 0) }}.
        <span id="gp-copy-activity">{{ $copying ? 'This is the file the service is copying now.' : 'The service copies one file at a time.' }}</span>
        Closing this page does not stop it.
    </p>
    <div class="gp-copy-bar {{ ! $done && ($active['status'] ?? '') === 'running' && empty($active['record_count']) ? 'is-waiting' : '' }}" id="gp-copy-bar">
        <span id="gp-copy-fill" style="width: {{ $done ? 100 : (int) ($active['percent'] ?? 0) }}%"></span>
    </div>
    <div class="gp-copy-stats">
        <div>
            <span>Records</span>
            <strong id="gp-copy-total">{{ number_format($active['record_count'] ?? 0) }}</strong>
        </div>
        <div>
            <span>Processed</span>
            <strong class="gp-copy-processed" id="gp-copy-done">{{ number_format($active['processed_count'] ?? 0) }}</strong>
        </div>
        <div class="gp-copy-current">
            <span>Current record</span>
            <strong id="gp-copy-current">{{ ($active['current_record'] ?? '') !== '' ? $active['current_record'] : '—' }}</strong>
        </div>
    </div>
    <ul class="gp-copy-queue" id="gp-copy-queue">
        @foreach ($queue['steps'] as $item)
            <li data-key="{{ $item['key'] }}" class="{{ $item['status'] === 'complete' ? 'is-done' : '' }} {{ $item['status'] === 'running' ? 'is-active' : '' }} {{ $item['status'] === 'failed' ? 'is-failed' : '' }}">
                <span>{{ $item['title'] }}</span>
                <strong>
                    @if ($item['status'] === 'complete')
                        Saved {{ number_format($item['saved_count']) }}
                    @elseif ($item['status'] === 'failed')
                        Stopped
                    @elseif ($item['status'] === 'running')
                        Copying now
                    @else
                        Waiting
                    @endif
                </strong>
            </li>
        @endforeach
    </ul>
    <p id="gp-copy-note" class="muted" style="margin-bottom:0;">{{ $done ? 'The downloads read the MySQL copies.' : $failedNote }}</p>
    <p style="margin:.8rem 0 0;">
        <button class="btn btn-primary" type="button" data-retry @if ($failed === []) hidden @endif>Retry stopped files</button>
    </p>
</div>
<script>
    (function () {
        var root = document.getElementById('gp-copy');
        if (!root) {
            return;
        }
        var token = document.querySelector('meta[name="csrf-token"]');
        var reloadOnDone = root.dataset.reload === '1';
        var focus = root.dataset.focus || '';
        var total = document.getElementById('gp-copy-total');
        var done = document.getElementById('gp-copy-done');
        var current = document.getElementById('gp-copy-current');
        var percent = document.getElementById('gp-copy-percent');
        var fill = document.getElementById('gp-copy-fill');
        var bar = document.getElementById('gp-copy-bar');
        var note = document.getElementById('gp-copy-note');
        var heading = document.getElementById('gp-copy-heading');
        var place = document.getElementById('gp-copy-place');
        var list = document.getElementById('gp-copy-queue');
        var retry = root.querySelector('[data-retry]');
        var timer = null;

        function num(value) {
            return Number(value || 0).toLocaleString();
        }

        function paint(state) {
            var active = state.active || null;
            var finished = state.done;
            var copying = !!(active && active.status === 'running');
            root.classList.toggle('is-running', copying);
            root.classList.toggle('is-done', !!finished);
            root.classList.remove('is-failed');
            var shown = active || { record_count: 0, processed_count: 0, current_record: '', percent: 100, title: '' };
            total.textContent = num(finished ? 0 : shown.record_count);
            done.textContent = num(finished ? 0 : shown.processed_count);
            current.textContent = finished ? 'Finished' : (shown.current_record || '—');
            if (finished || shown.percent === null || shown.percent === undefined) {
                percent.textContent = finished ? '100%' : '';
                bar.classList.toggle('is-waiting', !finished && active && (active.status === 'running' || active.status === 'pending' || active.status === 'missing'));
                fill.style.width = finished ? '100%' : '0%';
            } else {
                bar.classList.remove('is-waiting');
                percent.textContent = shown.percent + '%';
                fill.style.width = shown.percent + '%';
            }
            place.textContent = String(finished ? state.total : (state.place || 0));
            var activity = document.getElementById('gp-copy-activity');
            if (finished) {
                heading.textContent = 'Every file is saved in MySQL';
                activity.textContent = 'The downloads read the MySQL copies.';
            } else if (copying) {
                heading.textContent = 'Copying ' + shown.title + ' from Great Plains';
                activity.textContent = 'This is the file the service is copying now.';
            } else if (active) {
                heading.textContent = 'Next file is ' + shown.title;
                activity.textContent = 'The service copies one file at a time.';
            } else {
                heading.textContent = 'No file is copying right now';
                activity.textContent = 'The service copies one file at a time.';
            }
            var failed = state.failed || [];
            if (failed.length) {
                note.textContent = failed.map(function (item) {
                    return item.title + ' stopped at ' + num(item.processed_count) + ' of ' + num(item.record_count) + '.';
                }).join(' ') + (copying ? ' That file is not the one being copied. The service is copying ' + shown.title + '.' : ' Press Retry stopped files to run it again.');
                retry.hidden = false;
            } else if (finished) {
                note.textContent = 'The downloads read the MySQL copies.';
                retry.hidden = true;
            } else {
                note.textContent = '';
                retry.hidden = true;
            }
            var rows = list.querySelectorAll('[data-key]');
            rows.forEach(function (row) {
                var key = row.getAttribute('data-key');
                var item = (state.steps || []).find(function (step) { return step.key === key; });
                if (!item) {
                    return;
                }
                row.classList.toggle('is-done', item.status === 'complete');
                row.classList.toggle('is-active', item.status === 'running');
                row.classList.toggle('is-failed', item.status === 'failed');
                if (item.status === 'running' && root.dataset.shown !== key) {
                    root.dataset.shown = key;
                    row.scrollIntoView({ block: 'nearest' });
                }
                var label = row.querySelector('strong');
                if (item.status === 'complete') {
                    label.textContent = 'Saved ' + num(item.saved_count);
                } else if (item.status === 'failed') {
                    label.textContent = 'Stopped';
                } else if (item.status === 'running') {
                    label.textContent = 'Copying now';
                } else {
                    label.textContent = 'Waiting';
                }
            });
            var downloads = document.getElementById('step-downloads');
            var focused = (state.steps || []).find(function (step) { return step.key === focus; });
            var previousFocus = root.dataset.focusState || '';
            if (downloads && focused && focused.status === 'complete') {
                downloads.hidden = false;
            }
            if (reloadOnDone && focused && focused.status === 'complete' && previousFocus !== '' && previousFocus !== 'complete' && root.dataset.reloaded !== '1') {
                root.dataset.reloaded = '1';
                window.location.reload();
            }
            root.dataset.focusState = focused ? focused.status : '';
            root.dataset.state = finished ? 'complete' : (active ? active.status : 'missing');
        }

        function finish(state) {
            paint(state);
        }

        async function poll() {
            try {
                var response = await fetch(root.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
                var state = await response.json();
                paint(state);
                if (state.done) {
                    return;
                }
                if (state.active && (state.active.status === 'missing' || state.active.status === 'pending')) {
                    start();
                    return;
                }
            } catch (error) {
                note.textContent = 'The progress check paused. It will try again.';
            }
            timer = window.setTimeout(poll, 800);
        }

        async function start() {
            if (timer) {
                window.clearTimeout(timer);
            }
            var retrying = root.dataset.retry === '1';
            if (retrying) {
                note.textContent = 'The stopped file will be tried again after the file that is copying now.';
            }
            try {
                var response = await fetch(root.dataset.startUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token ? token.content : ''
                    },
                    body: JSON.stringify({ retry: root.dataset.retry === '1' })
                });
                root.dataset.retry = '0';
                var state = await response.json();
                paint(state);
                if (state.done) {
                    return;
                }
            } catch (error) {
                note.textContent = 'The copy could not start. Try again.';
                retry.hidden = false;
                return;
            }
            timer = window.setTimeout(poll, 800);
        }

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-retry]')) {
                root.dataset.retry = '1';
                start();
            }
        });

        if (root.dataset.state === 'complete') {
            root.dataset.focusState = root.dataset.state;
            return;
        }
        if (root.dataset.state === 'running') {
            poll();
            return;
        }
        start();
    })();
</script>
