@if (session('status'))
    <div class="alert alert-ok">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-bad">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-bad">{{ $errors->first() }}</div>
@endif
