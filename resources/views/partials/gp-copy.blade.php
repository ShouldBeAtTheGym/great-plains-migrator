<div
    id="gp-copy"
    class="card gp-copy {{ ($copy['status'] ?? '') === 'complete' ? 'is-done' : '' }} {{ ($copy['status'] ?? '') === 'failed' ? 'is-failed' : '' }} {{ ($copy['status'] ?? '') === 'running' ? 'is-running' : '' }}"
    data-status-url="{{ route('steps.copy', $exportKey) }}"
    data-start-url="{{ route('steps.copy.start', $exportKey) }}"
    data-reload="{{ !empty($reload) ? '1' : '0' }}"
    data-state="{{ $copy['status'] ?? 'missing' }}"
>
    <div class="gp-copy-top">
        <h3 id="gp-copy-heading">{{ ($copy['status'] ?? '') === 'complete' ? 'Copied into MySQL' : 'Copying '.$title.' from Great Plains' }}</h3>
        <div class="gp-copy-percent" id="gp-copy-percent">{{ ($copy['percent'] ?? null) === null ? '' : $copy['percent'].'%' }}</div>
    </div>
    <p class="muted" id="gp-copy-lead">The rows are saved in MySQL. The download reads that copy, so the file does not wait on Great Plains.</p>
    <div class="gp-copy-bar {{ ($copy['status'] ?? '') === 'running' && empty($copy['record_count']) ? 'is-waiting' : '' }}" id="gp-copy-bar">
        <span id="gp-copy-fill" style="width: {{ (int) ($copy['percent'] ?? 0) }}%"></span>
    </div>
    <div class="gp-copy-stats">
        <div>
            <span>Records</span>
            <strong id="gp-copy-total">{{ number_format($copy['record_count'] ?? 0) }}</strong>
        </div>
        <div>
            <span>Processed</span>
            <strong class="gp-copy-processed" id="gp-copy-done">{{ number_format($copy['processed_count'] ?? 0) }}</strong>
        </div>
        <div class="gp-copy-current">
            <span>Current record</span>
            <strong id="gp-copy-current">{{ ($copy['current_record'] ?? '') !== '' ? $copy['current_record'] : '—' }}</strong>
        </div>
    </div>
    <p id="gp-copy-note" class="muted" style="margin-bottom:0;">
        @if (($copy['status'] ?? '') === 'failed')
            {{ $copy['error'] ?: 'The copy from Great Plains failed.' }}
        @elseif (($copy['status'] ?? '') === 'complete')
            {{ number_format($copy['saved_count'] ?? 0) }} records are saved in MySQL. The download reads that copy.
        @endif
    </p>
    <p style="margin:.8rem 0 0;">
        <button class="btn btn-primary" type="button" data-retry @if (($copy['status'] ?? '') !== 'failed') hidden @endif>Copy again</button>
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
        var total = document.getElementById('gp-copy-total');
        var done = document.getElementById('gp-copy-done');
        var current = document.getElementById('gp-copy-current');
        var percent = document.getElementById('gp-copy-percent');
        var fill = document.getElementById('gp-copy-fill');
        var bar = document.getElementById('gp-copy-bar');
        var note = document.getElementById('gp-copy-note');
        var heading = document.getElementById('gp-copy-heading');
        var retry = root.querySelector('[data-retry]');
        var timer = null;

        function num(value) {
            return Number(value || 0).toLocaleString();
        }

        function paint(state) {
            var status = state.status || 'missing';
            root.classList.toggle('is-running', status === 'running' || status === 'pending');
            root.classList.toggle('is-done', status === 'complete');
            root.classList.toggle('is-failed', status === 'failed');
            total.textContent = num(state.record_count);
            done.textContent = num(state.processed_count);
            var label = state.current_record || (status === 'complete' ? 'Finished' : '—');
            if (current.textContent !== label) {
                current.textContent = label;
            }
            if (state.percent === null || state.percent === undefined) {
                percent.textContent = status === 'running' ? '' : (status === 'complete' ? '100%' : '');
                bar.classList.toggle('is-waiting', status === 'running' || status === 'pending');
                fill.style.width = status === 'complete' ? '100%' : '0%';
            } else {
                bar.classList.remove('is-waiting');
                percent.textContent = state.percent + '%';
                fill.style.width = state.percent + '%';
            }
            if (status === 'failed') {
                note.textContent = state.error || 'The copy from Great Plains failed.';
                retry.hidden = false;
            } else if (status === 'complete') {
                note.textContent = num(state.saved_count) + ' records are saved in MySQL. The download reads that copy.';
                retry.hidden = true;
                heading.textContent = 'Copied into MySQL';
            } else {
                note.textContent = '';
                retry.hidden = true;
            }
            var downloads = document.getElementById('step-downloads');
            if (downloads && status === 'complete') {
                downloads.hidden = false;
            }
            root.dataset.state = status;
        }

        function finish(state) {
            paint(state);
            if (state.status === 'complete' && reloadOnDone && root.dataset.reloaded !== '1') {
                root.dataset.reloaded = '1';
                window.location.reload();
            }
        }

        async function poll() {
            try {
                var response = await fetch(root.dataset.statusUrl, { headers: { 'Accept': 'application/json' } });
                var state = await response.json();
                if (state.status === 'complete' || state.status === 'failed') {
                    finish(state);
                    return;
                }
                paint(state);
            } catch (error) {
                note.textContent = 'The progress check paused. It will try again.';
            }
            timer = window.setTimeout(poll, 800);
        }

        async function start() {
            if (timer) {
                window.clearTimeout(timer);
            }
            retry.hidden = true;
            note.textContent = 'Starting the copy…';
            try {
                var response = await fetch(root.dataset.startUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.content : ''
                    }
                });
                var state = await response.json();
                if (state.status === 'complete' || state.status === 'failed') {
                    finish(state);
                    return;
                }
                paint(state);
            } catch (error) {
                note.textContent = 'The copy could not start. Try again.';
                retry.hidden = false;
                return;
            }
            timer = window.setTimeout(poll, 800);
        }

        root.addEventListener('click', function (event) {
            if (event.target.closest('[data-retry]')) {
                start();
            }
        });

        if (root.dataset.state === 'complete') {
            return;
        }
        if (root.dataset.state === 'failed') {
            return;
        }
        if (root.dataset.state === 'running') {
            poll();
            return;
        }
        start();
    })();
</script>
