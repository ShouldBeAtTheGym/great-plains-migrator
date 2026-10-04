<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preflight</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.alerts')
            <form method="post" action="{{ route('start.ready.store') }}" class="card">
                @csrf
                <p class="lead">Before connecting, confirm you have what this setup asks for. Every box needs a check.</p>
                <label class="ready-line">
                    <input type="checkbox" name="gp_ready" value="1" required>
                    <span>I have the Microsoft Great Plains database information: server address, company database name, username, and password.</span>
                </label>
                <label class="ready-line">
                    <input type="checkbox" name="odoo_ready" value="1" required>
                    <span>I have an Odoo Enterprise account, with a login email and password.</span>
                </label>
                <label class="ready-line">
                    <input type="checkbox" name="browser_ready" value="1" required>
                    <span>I can sign in to Odoo in a browser. That is where each file will be imported.</span>
                </label>
                <button class="btn btn-primary" type="submit">Continue</button>
            </form>
        </div>
    </div>
</x-app-layout>
