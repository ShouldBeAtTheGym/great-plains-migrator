<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Overview</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.docs-note')
            <div class="card">
                <p class="lead">Here is the whole job, in order. You do not need to memorize it.</p>
                <ol class="steps">
                    <li><span><strong>Create a login.</strong> This is how you come back. Closing the site does not erase the setup.</span></li>
                    <li><span><strong>Connect Great Plains.</strong> Enter the server address, username, and password. The port is already set to 1433. Press Test. When it succeeds, the button becomes Next.</span></li>
                    <li><span><strong>Connect Odoo.</strong> Do the same with the Odoo address, username, and password. The port is already set to 443, which is what Odoo Enterprise online uses.</span></li>
                    <li><span><strong>Great Plains fields.</strong> After Odoo is connected, create the Great Plains tab and the related contact fields. Customer Number is text. Customer Class and Shipping Method are selections. Address Code is text. Primary, Bill To, and Ship To are checkboxes. The export files use those same fields.</span></li>
                    <li><span><strong>Checklist.</strong> Import one file, come back, and confirm it. The next Start button lights up only after Odoo says the records are there.</span></li>
                </ol>
                @if (\App\Models\User::query()->exists())
                    <a class="btn btn-primary" href="{{ route('setup.gp') }}">Connect Great Plains</a>
                @else
                    <a class="btn btn-primary" href="{{ route('start.account') }}">Create your login</a>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
