<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Great Plains tab</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.alerts')
            <div class="card" style="margin-bottom:1rem;">
                <p class="lead">This step comes after Odoo is connected. Create adds these fields. The export files use the same names, so the import can match them.</p>
                <ol class="steps">
                    <li><span><strong>Customer file.</strong> <strong>x_gp_customer_number</strong> is Customer Number, a text box. The customer number goes there instead of Reference. The vendor file puts the vendor id in that same field. <strong>x_gp_customer_class</strong> is Customer Class, a selection. <strong>x_gp_shipping_method</strong> is Shipping Method, a selection. The choices are the distinct values below.</span></li>
                    <li><span><strong>Related contact form.</strong> <strong>x_gp_address_code</strong> is Address Code, a text box. <strong>x_gp_is_primary</strong> is Primary, a checkbox. <strong>x_gp_is_bill_to</strong> is Bill To, a checkbox. <strong>x_gp_is_ship_to</strong> is Ship To, a checkbox.</span></li>
                </ol>
                @if ($loadError)
                    <div class="alert alert-bad">{{ $loadError }}</div>
                @endif
                @if ($company->gp_tab_created_at)
                    <p class="alert alert-ok">The Great Plains tab was created {{ $company->gp_tab_created_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}. Create it again to refresh the choices.</p>
                @endif
                <form method="post" action="{{ route('setup.great-plains.store') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit" @if($loadError) disabled @endif>Create Great Plains tab</button>
                </form>
                @if ($company->introClosed())
                    <p style="margin-top:1rem;"><a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a></p>
                @else
                    <form method="post" action="{{ route('setup.odoo.next') }}" style="margin-top:1rem;">
                        @csrf
                        <button class="btn btn-ghost" type="submit">Continue</button>
                    </form>
                @endif
            </div>
            <div class="company-grid">
                <section class="card">
                    <h2>Customer Class</h2>
                    <p class="muted">{{ count($choices['customer_class']) }} distinct values</p>
                    <ul class="plain">
                        @forelse ($choices['customer_class'] as $value)
                            <li>{{ $value }}</li>
                        @empty
                            <li>No customer classes were found.</li>
                        @endforelse
                    </ul>
                </section>
                <section class="card">
                    <h2>Shipping Method</h2>
                    <p class="muted">{{ count($choices['shipping_method']) }} distinct values</p>
                    <ul class="plain">
                        @forelse ($choices['shipping_method'] as $value)
                            <li>{{ $value }}</li>
                        @empty
                            <li>No shipping methods were found.</li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
