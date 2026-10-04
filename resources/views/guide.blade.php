<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Guide</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="card" style="margin-bottom:1rem;">
                <p class="lead">This app moves one Great Plains company into Odoo. You do not need to know the databases. Follow the four stages, then import one file at a time.</p>
                <ol class="steps">
                    <li><span><strong>Great Plains.</strong> Enter the company database and press Test connection. A good test means SalesPad is installed: the app can see spv views and spx user fields. Those views are what the files are built from.</span></li>
                    <li><span><strong>Odoo.</strong> Enter the Odoo address, database, your email, and an API key. The key is created in Odoo under your name → Preferences → Account Security → New API Key.</span></li>
                    <li><span><strong>Preflight.</strong> Press Run preflight. Every line should be green. That saves the company names and opens the checklist.</span></li>
                    <li><span><strong>Checklist.</strong> The list matches the old export page: payment terms and categories first, then customers, products, sales documents, and purchasing. The lit Start button is the only file to do next.</span></li>
                </ol>
            </div>
            <div class="card" style="margin-bottom:1rem;">
                <h3 style="margin-top:0">Each file</h3>
                <ol class="steps">
                    <li><span>Press Start and download the CSV.</span></li>
                    <li><span>In Odoo, open the screen named on that page. Use the gear menu and choose Import records.</span></li>
                    <li><span>Upload the file. Match columns. Set every gp_ column to Do not import. Press Test, then Import.</span></li>
                    <li><span>Return and press Confirm import. The app asks Odoo if the records exist. You will hear two notes and see the poppers, then the next Start button lights up.</span></li>
                </ol>
                @include('partials.import-pictures')
            </div>
            <div class="card">
                <h3 style="margin-top:0">For a technical helper</h3>
                <p class="muted">The same actions are available as an API. Sign in with <code>POST /api/login</code> and send the returned token as <code>Authorization: Bearer</code>.</p>
                <ul>
                    <li><code>GET /api/company</code> company and connection status, without passwords</li>
                    <li><code>POST /api/gp/test</code> and <code>POST /api/odoo/test</code></li>
                    <li><code>POST /api/preflight</code></li>
                    <li><code>GET /api/steps</code></li>
                    <li><code>POST /api/steps/{key}/confirm</code></li>
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
