<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Create your login</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @include('partials.progress')
            @include('partials.alerts')
            <form method="post" action="{{ route('start.account.store') }}" class="card">
                @csrf
                <p class="lead">Create a login now. If you close this site, sign in with this email and password and the setup continues. It does not start over.</p>
                <label class="label" for="name">Your name</label>
                <input class="field" id="name" name="name" value="{{ old('name') }}" required autofocus>
                <label class="label" for="email">Email</label>
                <input class="field" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
                <label class="label" for="password">Password</label>
                <input class="field" id="password" name="password" type="password" required autocomplete="new-password">
                <label class="label" for="password_confirmation">Confirm password</label>
                <input class="field" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                @if ($errors->any())
                    <div class="alert alert-bad">{{ $errors->first() }}</div>
                @endif
                <button class="btn btn-primary" type="submit">Create login</button>
            </form>
        </div>
    </div>
</x-app-layout>
