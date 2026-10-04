<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $definition['title'] }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @include('partials.alerts')
            @if ($loadError)
                <div class="alert alert-bad">{{ $loadError }}</div>
            @endif
            <div class="card" style="margin-bottom:1rem;">
                <p class="lead">These are the SalesPad user fields on {{ $definition['table'] }}. Disabled fields start unchecked. Check the ones to move, choose the type, and for a selection type the choices separated by semicolons. Create in Odoo puts the checked fields on the {{ $definition['card'] }} card.</p>
                <p><a class="btn btn-ghost" href="{{ route('dashboard') }}">Back to checklist</a></p>
            </div>
            <form method="post" action="{{ route('user-fields.update', $modelKey) }}">
                @csrf
                <div class="export-wrap">
                    <table class="export-table">
                        <thead>
                            <tr>
                                <th>Move</th>
                                <th>SalesPad field</th>
                                <th>Type</th>
                                <th>Choices</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($fields as $field)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="fields[{{ $field->id }}][selected]" value="1" @checked(old('fields.'.$field->id.'.selected', $field->selected))>
                                    </td>
                                    <td>
                                        <strong>{{ $field->label }}</strong>
                                        <div class="muted">{{ $field->field_name }}@if ($field->disabled) · Disabled in SalesPad @endif @if ($field->odoo_field) · On the Odoo card @endif</div>
                                    </td>
                                    <td>
                                        <select class="field user-field-type" name="fields[{{ $field->id }}][field_type]" data-options="options-{{ $field->id }}" style="margin:0; min-width:9rem;">
                                            @foreach ($types as $value => $label)
                                                <option value="{{ $value }}" @selected(old('fields.'.$field->id.'.field_type', $field->field_type) === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            class="field user-field-options"
                                            id="options-{{ $field->id }}"
                                            name="fields[{{ $field->id }}][options]"
                                            value="{{ old('fields.'.$field->id.'.options', $field->options) }}"
                                            placeholder="Choice one; Choice two"
                                            style="margin:0;"
                                        >
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4">No SalesPad user fields were found on {{ $definition['table'] }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($fields->isNotEmpty())
                    <p style="margin-top:1rem;">
                        <button class="btn btn-ghost" type="submit" name="action" value="save">Save list</button>
                        <button class="btn btn-primary" type="submit" name="action" value="create">Create in Odoo</button>
                    </p>
                @endif
            </form>
        </div>
    </div>
    <script>
        function syncUserFieldOptions(select) {
            var box = document.getElementById(select.getAttribute('data-options'));
            if (!box) return;
            box.hidden = select.value !== 'selection';
        }
        document.querySelectorAll('.user-field-type').forEach(function (select) {
            syncUserFieldOptions(select);
            select.addEventListener('change', function () { syncUserFieldOptions(select); });
        });
    </script>
</x-app-layout>
