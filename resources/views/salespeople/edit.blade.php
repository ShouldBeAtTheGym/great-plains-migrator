<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Salespeople</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <div class="card" style="margin-bottom:1rem;">
                <h3 style="margin-top:0;">Create the salesperson in Odoo first</h3>
                <p>Each Great Plains person needs a contact, a user, and an employee. The three files use the same ids, so the employee’s work contact is that contact and the user’s related partner is that same contact. Import them in this order. Nobody has to log in as that user for an order to keep the salesperson.</p>
                <ol class="steps">
                    <li><span>Download the contact file. In Odoo, open <strong>Contacts</strong> and choose <strong>Import records</strong>. <strong>id</strong> is <strong>External ID</strong>. <strong>name</strong> is <strong>Name</strong>. <strong>email</strong> is <strong>Email</strong>. <strong>company_id/id</strong> is <strong>Company / External ID</strong>. Leave every other Company row on <strong>Do not import</strong>. A new contact is an individual.</span></li>
                    <li><span>Download the user file. Open <strong>Settings → Users &amp; Companies → Users</strong> and import it. <strong>partner_id/id</strong> is <strong>Related Partner / External ID</strong>. That is the contact from the first file. <strong>login</strong> is <strong>Login</strong>. <strong>notification_type</strong> is <strong>Notification</strong>. The value <strong>inbox</strong> means In Odoo. <strong>group_ids/id</strong> is <strong>Groups / External ID</strong>. That group is Sales, User: Own Documents Only. <strong>employee</strong> is <strong>Employee</strong>. Leave it at <strong>0</strong> so this import does not create a second employee.</span></li>
                    <li><span>The login is an email address. Odoo rejects a value such as <strong>gp.0004</strong>, and it also rejects an email that already belongs to a user. The file uses the Great Plains email when that address is free. Otherwise it uses a new address on your Odoo login domain. The contact email is that same address.</span></li>
                    <li><span>Download the employee file. Open <strong>Employees</strong> and import it. <strong>work_contact_id/id</strong> is <strong>Work Contact / External ID</strong>. <strong>user_id/id</strong> is <strong>User / External ID</strong>. Both point at the rows imported above.</span></li>
                    <li><span>Come back to this page. The new users are in the Odoo salesperson list. Choose each user next to the matching Great Plains id, then save.</span></li>
                    <li><span>Download the customer file and the sales files again. On <strong>user_id/id</strong>, choose <strong>Salesperson / External ID</strong>. On an invoice file the column is <strong>invoice_user_id/id</strong> and the field is the same.</span></li>
                </ol>
                @if ($people)
                    <div class="step-actions">
                        <a class="btn btn-primary" href="{{ route('salespeople.contacts') }}">Download contacts</a>
                        <a class="btn btn-primary" href="{{ route('salespeople.download') }}">Download users</a>
                        <a class="btn btn-primary" href="{{ route('salespeople.employees') }}">Download employees</a>
                    </div>
                @endif
                <p>This list is only Great Plains ids that are on an open order. Posted history is left off.</p>
                <p>Leave a row on <strong>No salesperson</strong> when that id is a show, a house account, or EDI. Those orders import with no salesperson.</p>
                <p><a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a></p>
            </div>

            <div class="card" style="margin-bottom:1rem;">
                <h3 style="margin-top:0;">Company employees</h3>
                <p>Upload the employee list for the company you are converting, then import the departments and the employees. Download the employee file again before importing. A person who already has an employee in Odoo is updated on that same record with job title, department, and manager. A person who does not is created. The user column keeps the login already linked to that employee, and fills it in only when that login does not have an employee yet.</p>
                <ol class="steps">
                    <li><span>Download the template. Fill in one row per person. <strong>external_id</strong> is the id you want in Odoo. <strong>manager_external_id</strong> is that person’s manager. <strong>department_id</strong>, <strong>department_name</strong>, and <strong>department_parent_id</strong> describe the department.</span></li>
                    <li><span>Upload the filled file on this page.</span></li>
                    <li><span>Download departments. In Odoo, open <strong>Employees → Configuration → Departments</strong> and choose <strong>Import records</strong>. <strong>id</strong> is <strong>External ID</strong>. <strong>name</strong> is <strong>Department Name</strong>. <strong>parent_id/id</strong> is <strong>Parent Department / External ID</strong>. <strong>company_id/id</strong> is <strong>Company / External ID</strong>.</span></li>
                    <li><span>Download employees. Open <strong>Employees</strong> and import that file. <strong>id</strong> is <strong>External ID</strong>. <strong>name</strong> is <strong>Employee Name</strong>. <strong>job_title</strong> is <strong>Job Title</strong>. <strong>work_email</strong> is <strong>Work Email</strong>. <strong>department_id/id</strong> is <strong>Department / External ID</strong>. <strong>parent_id/id</strong> is <strong>Manager / External ID</strong>. <strong>user_id/.id</strong> is <strong>User / Database ID</strong>.</span></li>
                </ol>
                <form method="post" action="{{ route('employees.store') }}" enctype="multipart/form-data" class="step-actions">
                    @csrf
                    <input type="file" name="roster" accept=".csv,text/csv" required>
                    @error('roster')<span>{{ $message }}</span>@enderror
                    <button class="btn btn-primary" type="submit">Upload employee list</button>
                    <a class="btn btn-ghost" href="{{ route('employees.template') }}">Download template</a>
                </form>
                <div class="step-actions">
                    <a class="btn btn-primary" href="{{ route('employees.departments') }}">Download departments</a>
                    <a class="btn btn-primary" href="{{ route('employees.download') }}">Download employees</a>
                </div>
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
