<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Connect Odoo</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.docs-note')
            @include('partials.alerts')
            <form method="post" action="{{ route('setup.odoo.test') }}" class="card" id="connect-form">
                @csrf
                <p class="lead">Enter the Odoo Enterprise address, username, and password. The port is already set to 443. An API key can be used in the password box. Press Test. When Odoo answers, the button changes to Next.</p>
                <label class="label" for="odoo_host">Address</label>
                <input class="field" id="odoo_host" name="odoo_host" value="{{ old('odoo_host', $company->odooHost()) }}" placeholder="yourcompany.odoo.com" required>
                <label class="label" for="odoo_port">Port</label>
                <input class="field" id="odoo_port" name="odoo_port" type="number" min="1" max="65535" value="{{ old('odoo_port', $company->odooPort()) }}" required>
                <label class="label" for="odoo_database">Database name</label>
                <input class="field" id="odoo_database" name="odoo_database" value="{{ old('odoo_database', $company->odoo_database) }}" required>
                <label class="label" for="odoo_username">Username</label>
                <input class="field" id="odoo_username" name="odoo_username" value="{{ old('odoo_username', $company->odoo_username) }}" required>
                <label class="label" for="odoo_password">Password</label>
                <input class="field" id="odoo_password" name="odoo_password" type="password" autocomplete="new-password" placeholder="{{ $company->hasSavedOdooKey() ? 'Leave blank to keep the saved password' : '' }}" {{ $company->hasSavedOdooKey() ? '' : 'required' }}>
                @if ($company->odoo_tested_at)
                    <p class="alert alert-ok">Connected to {{ $company->odoo_company_name ?: 'Odoo' }}{{ $company->odoo_version ? ' '.$company->odoo_version : '' }}.</p>
                @endif
                <button class="btn btn-primary" id="test-button" type="submit" @if($company->odoo_tested_at) hidden disabled @endif>Test connection</button>
                <a class="btn btn-primary" id="next-button" href="{{ route('setup.great-plains') }}" @unless($company->odoo_tested_at) hidden @endunless>Next</a>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('connect-form').addEventListener('input', function () {
            var test = document.getElementById('test-button');
            test.hidden = false;
            test.disabled = false;
            document.getElementById('next-button').hidden = true;
        });
    </script>
</x-app-layout>
