<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $export['title'] }}
        </h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            <p class="lead">{{ $export['detail'] }} This file is for the Odoo screen <strong>{{ $export['odoo'] }}</strong>.</p>
            @if (($export['key'] ?? '') === 'stock_locations')
                <div class="card" style="margin-bottom:1rem;border:2px solid #0f766e;">
                    <h3 style="margin-top:0;">Upload the warehouses first</h3>
                    <p>The bins file does not create CA/Stock or US/Stock. Those locations appear when the warehouse file is imported. Open <strong>Inventory → Configuration → Warehouses</strong> and import that file. On <strong>name</strong>, choose <strong>Warehouse</strong>. On <strong>code</strong>, choose <strong>Short Name</strong>. Then come back and import these bins on <strong>Locations</strong>.</p>
                    <a class="btn btn-primary" href="{{ route('steps.download', 'warehouses') }}">Download warehouses CSV</a>
                    <a class="btn btn-ghost" href="{{ route('steps.show', 'warehouses') }}">Open the warehouse step</a>
                </div>
            @endif
            @if ($loadError)
                <div class="alert alert-bad">{{ $loadError }}</div>
            @endif

            @if (($export['key'] ?? '') === 'customers')
                <div class="card" style="margin-bottom:1rem;border:2px solid #0f766e;">
                    <h3 style="margin-top:0;">SalesPad customer user fields</h3>
                    <p>Some of these fields are unused or disabled in SalesPad. Check the ones to move, pick Text, Selection, Yes / No, or Number, and for a selection type the choices separated by semicolons.</p>
                    <a class="btn btn-primary" href="{{ route('user-fields.show', 'customers') }}">Choose SalesPad customer user fields</a>
                </div>
                <div class="card" style="margin-bottom:1rem;">
                    <h3 style="margin-top:0;">Odoo company</h3>
                    <p>Pick the company these customers belong to. After you save, the <strong>company_id</strong> column is that company name.</p>
                    @if ($companyLoadError)
                        <div class="alert alert-bad">{{ $companyLoadError }}</div>
                    @endif
                    <form method="post" action="{{ route('steps.import-company', 'customers') }}">
                        @csrf
                        <label for="odoo-import-company">Import into</label>
                        <select class="field" id="odoo-import-company" name="odoo_import_company_id" style="max-width:28rem;">
                            <option value="">Choose a company</option>
                            @foreach ($odooCompanies as $row)
                                <option value="{{ $row['id'] }}" @selected((int) $company->odoo_import_company_id === (int) $row['id'])>{{ $row['name'] }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-primary" type="submit">Save company</button>
                    </form>
                </div>
            @endif
            @if (!empty($export['before']))
                <div class="card" style="margin-bottom:1rem;">
                    <h3 style="margin-top:0">Do these before this file</h3>
                    <ol class="steps">
                        @foreach ($export['before'] as $line)
                            <li><span>{{ $line }}</span></li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if (($export['key'] ?? '') === 'customers' || in_array(($export['key'] ?? ''), ['prospects', 'prospect_contacts'], true) || str_starts_with($export['key'] ?? '', 'sales_'))
                <div class="card" style="margin-bottom:1rem;">
                    <h3 style="margin-top:0;">Salesperson</h3>
                    @if (in_array(($export['key'] ?? ''), ['prospects', 'prospect_contacts'], true))
                        <p>Match each Great Plains salesperson to an Odoo user before you download this file. A row whose salesperson is not matched is imported with no salesperson.</p>
                    @else
                        <p>Match each Great Plains person id to an Odoo user before you download this file. Orders imported without that match have no salesperson, and filling them in later means opening the orders one by one.</p>
                    @endif
                    @if (isset($export['fields']['user_id/id']) || isset($export['fields']['invoice_user_id/id']))
                        <p>On the salesperson column, choose <strong>Salesperson / External ID</strong>.</p>
                    @endif
                    <a class="btn btn-primary" href="{{ route('salespeople.show') }}">Match salespeople</a>
                </div>
            @endif

            @if ((str_starts_with($export['key'] ?? '', 'customer_crm_') || in_array(($export['key'] ?? ''), ['customer_interactions', 'prospect_interactions'], true)) && $problemCount !== null)
                <div class="card" style="margin-bottom:1rem;">
                    <h3 style="margin-top:0;">Records left out of this file</h3>
                    @if (($export['key'] ?? '') === 'prospect_interactions')
                        <p>An interaction whose opportunity is not in Odoo is left out. Import the prospect file on CRM first, then download this file again. The report lists the prospect id on each skipped row.</p>
                    @else
                        <p>A record whose customer is not in Odoo is left out. The rest of the file can still be imported. Download the report to see the customer number on each skipped row.</p>
                    @endif
                    @if ($problemCount === 0)
                        @if (($export['key'] ?? '') === 'prospect_interactions')
                            <p>None. Every interaction matches an opportunity in Odoo.</p>
                        @else
                            <p>None. Every record in this file matches a customer in Odoo.</p>
                        @endif
                    @elseif ($problemCount > 0)
                        <p><strong>{{ number_format($problemCount) }}</strong> {{ $problemCount === 1 ? 'record is' : 'records are' }} left out.</p>
                        <p><a class="btn btn-primary" href="{{ route('steps.problems', $export['key']) }}">Download problem report</a></p>
                        @if ($problems)
                            <div class="export-wrap">
                                <table class="export-table">
                                    <thead>
                                        <tr>
                                            @foreach (['document' => 'Record', 'document_date' => 'Date', 'customer_number' => (($export['key'] ?? '') === 'prospect_interactions' ? 'Prospect' : 'Customer number'), 'subject' => 'Subject', 'problem' => 'Problem'] as $column => $label)
                                                <th>{{ $label }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($problems as $line)
                                            <tr>
                                                @foreach (['document', 'document_date', 'customer_number', 'subject', 'problem'] as $column)
                                                    <td>{{ $line[$column] ?? '' }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($problemCount > count($problems))
                                <p>The table shows the first {{ count($problems) }}. The report has all {{ number_format($problemCount) }}.</p>
                            @endif
                        @endif
                    @endif
                </div>
            @endif

            @if (!empty($export['problem_sql']))
                <div class="card" style="margin-bottom:1rem;">
                    <h3 style="margin-top:0;">Orders left out of this file</h3>
                    <p>Documents with no customer number are left out of the CSV. Documents whose customer is not in Odoo yet are left out too. Import those customers on Contacts, then download this file again.</p>
                    @if (!empty($export['missing_customers']))
                        <p><a class="btn btn-primary" href="{{ route('steps.missing-customers', $export['key']) }}">Download customers not in Odoo</a></p>
                    @endif
                    @if ($problemCount === 0)
                        <p>None. Every document in this file has a customer that is already in Odoo.</p>
                    @elseif ($problemCount > 0)
                        <p><strong>{{ number_format($problemCount) }}</strong> {{ $problemCount === 1 ? 'document is' : 'documents are' }} left out.</p>
                        <p><a class="btn btn-primary" href="{{ route('steps.problems', $export['key']) }}">Download problem report</a></p>
                        @if ($problems)
                            <div class="export-wrap">
                                <table class="export-table">
                                    <thead>
                                        <tr>
                                            @foreach (['document' => 'Document', 'document_date' => 'Date', 'customer_number' => 'Customer number', 'customer_name' => 'Customer name', 'total' => 'Total', 'problem' => 'Problem'] as $column => $label)
                                                <th>{{ $label }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($problems as $line)
                                            <tr>
                                                @foreach (['document', 'document_date', 'customer_number', 'customer_name', 'total', 'problem'] as $column)
                                                    <td>{{ $line[$column] ?? '' }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($problemCount > count($problems))
                                <p>The table shows the first {{ count($problems) }}. The report has all {{ number_format($problemCount) }}.</p>
                            @endif
                        @endif
                    @endif
                </div>
            @endif

            <div class="card" style="margin-bottom:1rem;">
                <h3 style="margin-top:0">How to move this file into Odoo</h3>
                @php($serverUpload = ($count ?? 0) > \App\Services\ApiUploadQueue::ROWS)
                @include('partials.gp-copy-queue', ['queue' => $queue, 'focus' => $export['key'], 'reload' => true])
                @include('partials.api-uploads', ['uploads' => $uploads ?? ['jobs' => [], 'limited' => false]])
                <ol class="steps">
                    <li><span>@if ($serverUpload) This file has {{ number_format($count) }} rows. Download part 1 only. Test that file in Odoo, then upload the document. After you confirm, the server uploads part 2 onward. @else @if (!empty($export['problem_sql'])) Look at the orders left out, then download the CSV. @else Download the CSV from this page. @endif @if($count !== null) Great Plains currently has {{ number_format($count) }} rows in this file. @endif @if (($parts ?? 1) > 1) Odoo will not take the whole CSV in one upload. Import part 1, then part 2, and so on. Each part has {{ number_format(\App\Services\ExportCatalog::PART_ROWS) }} rows. @endif @endif</span></li>
                    <li><span>In Odoo, open <strong>{{ $guide['screen'] }}</strong>.@if (!empty($export['hint'])) @include('partials.hover-hint', ['label' => 'Do not see?', 'hint' => $export['hint'], 'callout' => true])@endif</span></li>
                    <li><span>On that list, open the gear menu and choose <strong>Import records</strong>. Upload the CSV.</span></li>
                    <li><span>Match each file column to the Odoo field in the column map. An orange “To import, select a field…” row is not a missing checklist step. Pick the field the map names, or <strong>Do not import</strong> when the map says so. Set every column that starts with <strong>gp_</strong> to <strong>Do not import</strong>, even if Odoo guessed a field.</span></li>
                    @if (($export['key'] ?? '') === 'customers' && count($odooCompanies ?? []) > 1)
                        <li><span>@include('partials.several-companies-note', ['chosenCompany' => $company->odoo_import_company_name])</span></li>
                    @elseif (($export['key'] ?? '') === 'customers')
                        <li><span>On the row <strong>x_gp_customer_number</strong>, choose <strong>Customer Number</strong>. Do not send the customer number to Reference. On the row <strong>x_gp_customer_class</strong>, choose <strong>Customer Class</strong>. On the row <strong>x_gp_shipping_method</strong>, choose <strong>Shipping Method</strong>. Those two lists are the distinct values from Great Plains.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'vendors')
                        <li><span>On the row <strong>x_gp_customer_number</strong>, choose <strong>Customer Number</strong>. Do not send the vendor id to Reference.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'customer_price_lists')
                        <li><span>Import customers and products first. On <strong>company_id</strong>, choose <strong>Company</strong>. On <strong>item_ids/applied_on</strong>, choose <strong>Do not import</strong>. Odoo sets Apply On to Product from the product column, and that field is not in the import list. On <strong>item_ids/product_tmpl_id/id</strong>, choose <strong>Pricelist Rules / Product / External ID</strong>. That is the product imported earlier, <strong>__import__.product_</strong> plus the item number. On <strong>item_ids/compute_price</strong>, choose <strong>Pricelist Rules / Compute Price</strong>. The value <strong>fixed</strong> means Fixed Price. On <strong>item_ids/fixed_price</strong>, choose <strong>Pricelist Rules / Fixed Price</strong>. Archived products are left out.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'customer_price_assign')
                        <li><span>If Odoo says a pricelist was not found, download the price lists that are not in Odoo yet. Import that small file on <strong>Sales → Products → Pricelists</strong>, using the same column map as the full price list file. Then come back to this file.</span></li>
                        <li><span>Open <strong>Contacts</strong>. Leave the Pricelists import. If the list says <strong>Pricelist Rules</strong>, you are still on Pricelists. On Contacts, <strong>id</strong> is <strong>External ID</strong>. On <strong>name</strong>, choose <strong>Name</strong>. On <strong>property_product_pricelist/id</strong>, choose <strong>Pricelist / External ID</strong>. Do not choose Pricelist Rules or Pricelist Name. Customer numbers that are not customers are left out of this file.</span></li>
                        <li><span><a class="btn btn-primary" href="{{ route('steps.price-lists.missing') }}">Download price lists not in Odoo</a></span></li>
                    @endif
                    @if (str_ends_with($export['key'] ?? '', '_headers') && (str_contains($export['key'] ?? '', 'sales_orders') || str_contains($export['key'] ?? '', 'quotes')))
                        <li><span>On <strong>warehouse_id</strong>, choose <strong>Warehouse</strong>. The value is the Odoo warehouse name. The Great Plains site code, such as GRR, is not a warehouse name.</span></li>
                    @endif
                    @if (str_ends_with($export['key'] ?? '', '_lines') && str_starts_with($export['key'] ?? '', 'sales_'))
                        <li><span>A kit, or an item that is no longer in the product file, has a blank product column. The description and price stay on the line. Kits were left out of the product import.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'warehouses')
                        <li><span>On <strong>name</strong>, choose <strong>Warehouse</strong>. That name is required. On <strong>code</strong>, choose <strong>Short Name</strong>. That is the Great Plains site, such as US or CA. On <strong>company_id/id</strong>, choose <strong>Company / External ID</strong>. If Company is not in the list, set <strong>company_id/id</strong> to <strong>Do not import</strong>. Odoo then creates the stock location for that warehouse, such as US/Stock.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'stock_locations')
                        <li><span>Import the warehouses first. On <strong>location_id</strong>, choose <strong>Parent Location</strong>. The value is the warehouse short name and /Stock, such as US/Stock. If <strong>Locations</strong> is not in the menu, go to <strong>Inventory → Configuration → Settings</strong>. Under <strong>Warehouse</strong>, check <strong>Storage Locations</strong> and click <strong>Save</strong>.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'inventory_on_hand')
                        <li><span>On <strong>product_id/id</strong>, choose <strong>Product / External ID</strong>. The value starts with <strong>__import__.product_variant_</strong>. On <strong>location_id</strong>, choose <strong>Do not import</strong>. That cell is the bin name, such as <strong>US/Stock/PICK ROOM</strong>. On <strong>location_id/id</strong>, choose <strong>Location / External ID</strong>. Odoo rejects the row when both the location name and the location external id are mapped. On <strong>gp_qty_allocated</strong>, choose <strong>Do not import</strong>. After the import, click <strong>Apply All</strong>. That writes the counted quantity into On Hand. Until then, On Hand stays at zero.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'products')
                        <li><span>Great Plains kits are left out. A kit is not stock. It points at the other products that go in the box. Odoo can do that later with a Kit bill of materials in Manufacturing, after the real products are imported. Do not add the kits as normal products.</span></li>
                        <li><span>When the same barcode is on more than one item, the first item keeps it. The next ones are numbered <strong>-1</strong>, <strong>-2</strong>, and so on.</span></li>
                    @endif
                    @if (($export['key'] ?? '') === 'customer_addresses')
                        <li><span>On the row <strong>x_gp_address_code</strong>, choose <strong>Address Code</strong>. On <strong>x_gp_is_primary</strong>, choose <strong>Primary</strong>. On <strong>x_gp_is_bill_to</strong>, choose <strong>Bill To</strong>. On <strong>x_gp_is_ship_to</strong>, choose <strong>Ship To</strong>. Address Code is text. The other three are checkboxes. <strong>gp_street3</strong> stays <strong>Do not import</strong>.</span></li>
                    @endif
                    <li><span>@if ($serverUpload) Press Test. When Odoo says the file looks valid, upload the document. @else Press Test. When Odoo says the file looks valid, press Import. @endif</span></li>
                    <li><span>@if ($serverUpload) Come back here and press <strong>Confirm part 1</strong>. This checks that part 1 uploaded. The server then uploads part 2 onward. @else Come back here and press <strong>Confirm import</strong>. This app asks Odoo whether the records exist. When they do, the checklist moves on. @endif</span></li>
                </ol>
                @if ($current)
                    <div class="step-actions">
                        <div id="step-downloads" class="step-downloads" @if (($copy['status'] ?? '') !== 'complete') hidden @endif>
                            @if (($parts ?? 1) > 1)
                                <div class="part-grid">
                                    @for ($part = 1; $part <= ($serverUpload ? 1 : $parts); $part++)
                                        <a class="btn btn-primary" href="{{ route('steps.download', ['key' => $export['key'], 'part' => $part]) }}">Part {{ $part }}</a>
                                    @endfor
                                </div>
                            @elseif (($export['key'] ?? '') === 'customers' && count($odooCompanies ?? []) > 1)
                                <button class="btn btn-primary" id="download-csv" type="button">Download CSV</button>
                            @else
                                <a class="btn btn-primary" href="{{ route('steps.download', $export['key']) }}">Download CSV</a>
                            @endif
                        </div>
                        <button class="btn start-live" id="confirm-import" type="button">{{ $serverUpload ? 'Confirm part 1' : 'Confirm import' }}</button>
                        @if (($export['key'] ?? '') === 'customers')
                            <a class="btn btn-primary" href="{{ route('user-fields.show', 'customers') }}">Choose SalesPad customer user fields</a>
                        @endif
                        <a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a>
                    </div>
                    <div id="confirm-message" class="muted"></div>
                    <div class="step-skip">
                        <form method="POST" action="{{ route('steps.skip', $export['key']) }}" onsubmit="return confirm('Skip this file? The checklist moves on. Nothing is changed in Odoo.');">
                            @csrf
                            <button class="btn btn-danger" type="submit">Skip this step</button>
                        </form>
                    </div>
                @elseif ($step->isComplete())
                    <div class="alert alert-ok">{{ $step->confirm_message }}</div>
                    <p>Recheck starts this file over. Download, import, and confirm happen again after the imported records are removed from Odoo.</p>
                    <p>
                        <button
                            class="btn btn-primary"
                            type="button"
                            data-recheck
                            data-title="{{ $export['title'] }}"
                            data-screen="{{ $guide['screen'] }}"
                            data-action="{{ route('steps.restart', $export['key']) }}"
                        >Recheck</button>
                        <a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a>
                    </p>
                @elseif (($copy['status'] ?? '') === 'complete')
                    <div class="step-actions">
                        <div id="step-downloads" class="step-downloads">
                            @if (($parts ?? 1) > 1)
                                <div class="part-grid">
                                    @for ($part = 1; $part <= ($serverUpload ? 1 : $parts); $part++)
                                        <a class="btn btn-primary" href="{{ route('steps.download', ['key' => $export['key'], 'part' => $part]) }}">Part {{ $part }}</a>
                                    @endfor
                                </div>
                            @else
                                <a class="btn btn-primary" href="{{ route('steps.download', $export['key']) }}">Download CSV</a>
                            @endif
                        </div>
                        <a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a>
                    </div>
                    <p class="muted">This file is saved and can be downloaded now. Confirm import stays on the lit Start step.</p>
                @else
                    <div class="alert alert-bad">This step is waiting. Finish the lit Start button on the checklist first. You can read these instructions now.</div>
                    <a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a>
                @endif
            </div>

            <h3>Pictures of the screens</h3>
            @include('partials.import-pictures')

            @if ($columns)
                <h3>Column map</h3>
                <div class="card">
                    <table class="map-table">
                        <thead><tr><th>File column</th><th>Set the Odoo field to</th></tr></thead>
                        <tbody>
                            @foreach ($columns as $column)
                                <tr>
                                    <td>{{ $column }}</td>
                                    @php($mapped = $export['fields'][$column] ?? (str_starts_with($column, 'gp_') ? 'Do not import' : $column))
                                    <td class="{{ $mapped === 'Do not import' ? 'skip' : '' }}">
                                        {{ $mapped }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($sample)
                <h3>First rows</h3>
                <div class="export-wrap">
                    <table class="export-table">
                        <thead>
                            <tr>
                                @foreach (array_keys($sample[0]) as $column)
                                    <th>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sample as $line)
                                <tr>
                                    @foreach ($line as $value)
                                        <td>{{ $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @if (($export['key'] ?? '') === 'customers' && count($odooCompanies ?? []) > 1 && $current)
        <div id="company-download-modal" class="office-modal" hidden>
            <div class="office-modal-backdrop" data-close-company-note></div>
            <div class="office-modal-card" role="dialog" aria-modal="true" aria-labelledby="company-download-heading">
                <h2 id="company-download-heading">Choose these fields</h2>
                <p>@include('partials.several-companies-note', ['chosenCompany' => $company->odoo_import_company_name])</p>
                @if ($company->odoo_import_company_name)
                    <a class="btn btn-primary" href="{{ route('steps.download', 'customers') }}">Download CSV</a>
                @endif
                <button class="btn btn-ghost" type="button" data-close-company-note>Close</button>
            </div>
        </div>
        <script>
            (function () {
                var modal = document.getElementById('company-download-modal');
                var open = document.getElementById('download-csv');
                if (!modal || !open) {
                    return;
                }
                open.addEventListener('click', function () {
                    modal.hidden = false;
                });
                modal.addEventListener('click', function (event) {
                    if (event.target.closest('[data-close-company-note]')) {
                        modal.hidden = true;
                    }
                });
            })();
        </script>
    @endif
    @if ($step->isComplete())
        @include('partials.recheck-modal')
    @endif
    @if ($current)
        <script>
            document.getElementById('confirm-import').addEventListener('click', async function () {
                var button = this;
                var message = document.getElementById('confirm-message');
                if (window.unlockCelebration) window.unlockCelebration();
                button.disabled = true;
                message.textContent = @json($serverUpload ? 'Asking Odoo if part 1 uploaded...' : 'Asking Odoo if this import is there...');
                try {
                    var response = await fetch(@json(route('steps.confirm', $export['key'])), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        }
                    });
                    var body = await response.json();
                    if (!response.ok || !body.ok) {
                        message.textContent = body.message || 'Odoo did not confirm this import.';
                        button.disabled = false;
                        return;
                    }
                    window.celebrate({
                        title: @json($export['title']).concat(' is in Odoo'),
                        copy: body.message,
                        redirect: body.redirect
                    });
                } catch (error) {
                    message.textContent = 'The confirmation could not finish. Try again.';
                    button.disabled = false;
                }
            });
        </script>
    @endif
</x-app-layout>
