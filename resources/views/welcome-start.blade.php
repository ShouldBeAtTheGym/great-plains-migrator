<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Welcome</h2>
    </x-slot>
    <div class="py-10">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <div class="card welcome-hero">
                <p class="kicker">Great Plains Migrator</p>
                <h1>Move a Great Plains company into Odoo, one file at a time.</h1>
                <p class="lead">This application connects to Microsoft Dynamics GP, checks that SalesPad is installed, and prepares the files Odoo Enterprise needs. It then walks you through each import and asks Odoo to confirm the records arrived.</p>
                <ul class="plain">
                    <li>Payment terms, categories, customers, products, and open documents, in a safe order.</li>
                    <li>Plain instructions and pictures for each file.</li>
                    <li>A checklist that lights up the next file only after the current one is confirmed.</li>
                </ul>
                <div class="read-note" style="margin-top:1rem;">
                    <p>Great Plains and SalesPad have a complex structure. Moving to Odoo will require some configuration and some changes in how the business works. This app makes the move as easy as possible and spells out each change that may be needed. Every business is different, so some planning still has to take place. Always test the imports and address any errors or missing data. “Test and test again. Import.”</p>
                </div>
                <form method="post" action="{{ route('start') }}">
                    @csrf
                    <button class="btn-start" type="submit">Get started</button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
