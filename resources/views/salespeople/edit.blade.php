<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Salespeople</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <div class="card" style="margin-bottom:1rem;">
                <h3 style="margin-top:0;">Create the salesperson in Odoo first</h3>
                <p>Odoo keeps a salesperson as a user. A Great Plains person id, such as 205, is not an Odoo user. Create the user before you import customers or sales orders. Changing the salesperson on every order later is a much larger job.</p>
                <ol class="steps">
                    <li><span>Download the user file. It has one row for each Great Plains id on an open order, including EDI and drop ship. Each row becomes an Odoo user. Nobody has to log in as that user for the order to keep the salesperson.</span></li>
                    <li><span>In Odoo, open <strong>Settings → Users &amp; Companies → Users</strong>. Use the gear menu and choose <strong>Import records</strong>.</span></li>
                    <li><span><strong>id</strong> is <strong>External ID</strong>. <strong>name</strong> is <strong>Name</strong>. <strong>login</strong> is <strong>Login</strong>. <strong>company_id</strong> is <strong>Company</strong>. <strong>notification_type</strong> is <strong>Notification</strong>. The value <strong>inbox</strong> means In Odoo. <strong>group_ids/id</strong> is <strong>Groups / External ID</strong>. That group is Sales, User: Own Documents Only.</span></li>
                    <li><span>Press Test, then Import.</span></li>
                    <li><span>Come back to this page. The new users are in the Odoo salesperson list. Choose each user next to the matching Great Plains id, then save.</span></li>
                    <li><span>Download the customer file and the sales files again. On <strong>user_id/id</strong>, choose <strong>Salesperson / External ID</strong>. On an invoice file the column is <strong>invoice_user_id/id</strong> and the field is the same.</span></li>
                </ol>
                @if ($people)
                    <p><a class="btn btn-primary" href="{{ route('salespeople.download') }}">Download user file</a></p>
                @endif
                <p>This list is only Great Plains ids that are on an open order. Posted history is left off.</p>
                <p>Leave a row on <strong>No salesperson</strong> when that id is a show, a house account, or EDI. Those orders import with no salesperson.</p>
                <p><a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a></p>
            </div>

            @if ($loadError)
                <div class="alert alert-bad">{{ $loadError }}</div>
            @endif

            @if ($people)
                <form method="post" action="{{ route('salespeople.update') }}">
                    @csrf
                    <p><strong>{{ $links->count() }}</strong> of {{ count($people) }} Great Plains ids are matched. {{ count($users) }} Odoo users can be chosen.</p>
                    <p><button class="btn btn-primary" type="submit">Save cross-reference</button></p>
                    <div class="export-wrap">
                        <table class="export-table">
                            <thead>
                                <tr>
                                    <th>Great Plains id</th>
                                    <th>Name</th>
                                    <th class="count">Open orders</th>
                                    <th>Odoo salesperson</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($people as $person)
                                    <tr>
                                        <td>{{ $person['id'] }}</td>
                                        <td>
                                            {{ $person['name'] }}
                                            @if ($person['inactive'])
                                                <span class="muted">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="count">{{ number_format($person['orders']) }}</td>
                                        <td>
                                            <select class="field" name="links[{{ $person['id'] }}]" style="margin:0; min-width:16rem;">
                                                <option value="">No salesperson</option>
                                                @foreach ($users as $user)
                                                    <option value="{{ $user['id'] }}" @selected((int) ($links[$person['id']]->odoo_user_id ?? 0) === (int) $user['id'])>
                                                        {{ $user['name'] }}@if ($user['login']) ({{ $user['login'] }})@endif
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p><button class="btn btn-primary" type="submit">Save cross-reference</button></p>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
