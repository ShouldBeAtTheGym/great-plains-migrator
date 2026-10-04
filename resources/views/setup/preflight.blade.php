<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preflight</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.alerts')
            <div class="card" style="margin-bottom:1rem;">
                <p class="lead">Preflight checks the pieces the checklist needs: the MySQL tables on this server, Great Plains, SalesPad, and the Odoo sign-in. When every line is green, the checklist opens and the first Start button lights up.</p>
                <form method="post" action="{{ route('setup.preflight.run') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Run preflight</button>
                </form>
            </div>
            @if ($report)
                <div class="card">
                    @foreach ($report['checks'] as $check)
                        <div class="check">
                            <span class="dot {{ $check['ok'] ? 'ok' : 'bad' }}"></span>
                            <div>
                                <strong>{{ $check['name'] }}</strong>
                                <div>{{ $check['detail'] }}</div>
                                @if (! $check['ok'])
                                    <div class="muted">{{ $check['help'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
