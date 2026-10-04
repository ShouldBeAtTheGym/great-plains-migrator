<x-product-layout>
    @include('partials.alerts')
    <div class="card welcome-hero" style="align-items:stretch; text-align:left;">
        <h1>Move a Great Plains company into Odoo.</h1>
        <p class="lead">This application reads one Microsoft Dynamics GP company, with SalesPad, and prepares the files Odoo needs. It then walks through each import. Great Plains is only read. Nothing in that database is changed.</p>

        <h2>What it is for</h2>
        <p>A business that is leaving Microsoft Dynamics GP and SalesPad for Odoo Enterprise. It is for one company database at a time. The person doing the move downloads a file, imports it on the matching Odoo screen, and comes back to confirm it or skip it.</p>

        <h2>What it does</h2>
        <ul class="plain">
            <li>Connects to Great Plains and to Odoo, and checks that the SalesPad views are present.</li>
            <li>Builds the import files in a safe order: payment terms, categories, units, locations, customers, vendors, products, prices, inventory, sales documents, then purchasing.</li>
            <li>Leaves out rows that would stop an import, such as a document with no customer, and gives you a report of those rows.</li>
            <li>Splits a very large file into parts that Odoo can upload.</li>
            <li>Asks Odoo whether the imported records exist before the next file is unlocked. You can also skip a file and come back to it.</li>
            <li>After the first import, Step two counts new Great Plains rows so you can download just those and import them.</li>
        </ul>
        <div class="read-note">
            <p>Since every system, ongoing remove and update cannot be done via sync. Step two adds new rows. It does not remove or change rows that are already in Odoo.</p>
        </div>

        <h2>How to install</h2>
        <ol class="plain">
            <li>Install PHP 8.3 or newer, Composer, MySQL 8, and the PHP drivers for MySQL and Microsoft SQL Server.</li>
            <li>Download this application and run <strong>composer install</strong> in its folder.</li>
            <li>Copy <strong>.env.example</strong> to <strong>.env</strong>.</li>
            <li>Point the web server at the <strong>public</strong> folder.</li>
            <li>Open the site. On a new install, enter the MySQL connection. The application creates the tables in that database.</li>
            <li>Create your login, then connect Great Plains and Odoo. Work from the lit Start button.</li>
        </ol>
        <p class="muted">If you would rather create the tables yourself, make an empty MySQL database and run <strong>database/mysql/great_plains_migrator.sql</strong> inside it. That file is only for an empty database. Then put the connection in the .env file.</p>

        @if ($installed)
            <p>The MySQL tables are already in place.</p>
            @if ($hasLogin)
                <a class="btn btn-primary" href="{{ route('login') }}">Sign in</a>
            @else
                <a class="btn-start" href="{{ route('welcome') }}">Get started</a>
            @endif
        @else
            <h2>MySQL connection</h2>
            <p>Enter the server that will hold this application's own tables. This is not the Great Plains server. After the connection works, the tables are created.</p>
            <form method="POST" action="{{ route('install.store') }}">
                @csrf
                <label class="label" for="host">Host</label>
                <input class="field" id="host" name="host" value="{{ old('host', '127.0.0.1') }}" required>
                <label class="label" for="port">Port</label>
                <input class="field" id="port" name="port" value="{{ old('port', '3306') }}" required>
                <label class="label" for="database">Database</label>
                <input class="field" id="database" name="database" value="{{ old('database', 'great_plains_migrator') }}" required>
                <label class="label" for="username">User</label>
                <input class="field" id="username" name="username" value="{{ old('username') }}" required>
                <label class="label" for="password">Password</label>
                <input class="field" id="password" name="password" type="password" value="" autocomplete="new-password">
                <button class="btn-start" type="submit">Create the tables</button>
            </form>
        @endif
    </div>
</x-product-layout>
