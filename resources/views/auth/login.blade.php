@extends('layouts.auth')

@section('title', 'Log in')

@section('content')
    <x-auth-header title="Welcome back" description="Enter your credentials to continue to your Procura workspace." />
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login.store') }}" class="space-y-6">
        @csrf

        <flux:input
            name="email"
            label="Email address"
            :value="old('email')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="you@company.com"
        />

        <div class="relative">
            <flux:input
                name="password"
                label="Password"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />

            <a href="{{ route('password.request') }}" class="absolute right-0 top-0 text-sm font-semibold text-teal-700 hover:text-teal-900">
                Forgot password?
            </a>
        </div>

        <flux:checkbox name="remember" label="Remember me" :checked="old('remember')" />

        <flux:button variant="primary" type="submit" class="w-full">Log in</flux:button>
    </form>

    <p class="mt-7 text-center text-sm text-stone-600">
        New to Procura?
        <a href="{{ route('register') }}" class="font-semibold text-teal-700 hover:text-teal-900">Create an account</a>
    </p>
@endsection
