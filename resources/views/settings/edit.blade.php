<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Settings</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <div class="card">
                <h3 class="font-semibold text-lg text-gray-800">Intro guide</h3>
                <p class="lead">Welcome, the preflight questions, the overview, and the Great Plains and Odoo connection pages are the intro guide. After setup is finished and an account exists, that guide stays closed.</p>
                <p>Reset intro opens the guide again. The saved connections and the checklist stay as they are.</p>
                <form method="post" action="{{ route('settings.intro.reset') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Reset intro</button>
                </form>
            </div>
            <div class="card" style="margin-top:1rem;">
                <h3 class="font-semibold text-lg text-gray-800">Bad copy report</h3>
                <p class="lead">When Odoo rejects a row during an upload, that row is listed in the bad copy report and the upload stays stopped.</p>
                @if (($badCopyCount ?? 0) > 0)
                    <p><strong>{{ number_format($badCopyCount) }}</strong> {{ $badCopyCount === 1 ? 'bad row' : 'bad rows' }}.</p>
                @else
                    <p>No bad rows yet.</p>
                @endif
                <a class="btn btn-primary" href="{{ route('settings.bad-copy') }}">Bad copy report</a>
            </div>
        </div>
    </div>
</x-app-layout>
