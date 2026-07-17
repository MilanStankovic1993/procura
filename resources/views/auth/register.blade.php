@extends('layouts.auth')

@section('title', 'Create account')

@section('content')
    <x-auth-header title="Create your account" description="Start with a secure personal workspace. Team setup comes next." />
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
        @csrf

        <flux:input name="name" label="Full name" :value="old('name')" type="text" required autofocus autocomplete="name" />
        <flux:input name="email" label="Work email" :value="old('email')" type="email" required autocomplete="email" placeholder="you@company.com" />
        <flux:input name="password" label="Password" type="password" required autocomplete="new-password" viewable />
        <flux:input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" viewable />

        <p class="text-xs leading-5 text-stone-500">Use at least 12 characters with uppercase, lowercase, number, and symbol.</p>

        <flux:button variant="primary" type="submit" class="w-full">Create account</flux:button>
    </form>

    <p class="mt-7 text-center text-sm text-stone-600">
        Already have an account?
        <a href="{{ route('login') }}" class="font-semibold text-teal-700 hover:text-teal-900">Log in</a>
    </p>
@endsection
