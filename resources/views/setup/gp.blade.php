<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Connect Microsoft Great Plains</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.docs-note')
            @include('partials.alerts')
            <form method="post" action="{{ route('setup.gp.test') }}" class="card" id="connect-form">
                @csrf
                <div class="read-note">
                    <p>No data is ever written to this Great Plains database. It is only read. All data this application stores is saved in the local MySQL database.</p>
                    <p>Once this setup and the preflight checks are finished, and an account exists, this intro is not shown again. Open Settings and choose Reset intro to see it again.</p>
                </div>
                <p class="lead">Enter the Great Plains server. The port is already set to 1433, which is the SQL Server port. Press Test. When the connection succeeds, the button changes to Next.</p>
                <label class="label" for="gp_server">Address</label>
                <input class="field" id="gp_server" name="gp_server" value="{{ old('gp_server', $company->gp_server) }}" required>
                <label class="label" for="gp_port">Port</label>
                <input class="field" id="gp_port" name="gp_port" type="number" min="1" max="65535" value="{{ old('gp_port', $company->gpPort()) }}" required>
                <label class="label" for="gp_database">Company database</label>
                <input class="field" id="gp_database" name="gp_database" value="{{ old('gp_database', $company->gp_database) }}" required>
                <label class="label" for="gp_username">Username</label>
                <input class="field" id="gp_username" name="gp_username" value="{{ old('gp_username', $company->gp_username) }}" required>
                <label class="label" for="gp_password">Password</label>
                <input class="field" id="gp_password" name="gp_password" type="password" autocomplete="new-password" placeholder="{{ $company->hasSavedGpPassword() ? 'Leave blank to keep the saved password' : '' }}" {{ $company->hasSavedGpPassword() ? '' : 'required' }}>
                <label style="display:flex; gap:.5rem; align-items:center; margin-bottom:1rem;">
                    <input type="checkbox" name="gp_trust_cert" value="1" {{ old('gp_trust_cert', $company->gp_trust_cert) ? 'checked' : '' }}>
                    Trust the SQL Server certificate
                </label>
                @if ($company->salespad_installed)
                    <p class="alert alert-ok">SalesPad is installed. {{ $company->salespad_report['spv_count'] ?? 0 }} spv views and {{ $company->salespad_report['spx_count'] ?? 0 }} spx user-field tables.</p>
                @endif
                <button class="btn btn-primary" id="test-button" type="submit" @if($company->gp_tested_at) hidden disabled @endif>Test connection</button>
                <a class="btn btn-primary" id="next-button" href="{{ route('setup.odoo') }}" @unless($company->gp_tested_at) hidden @endunless>Next</a>
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
