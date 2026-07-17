@extends('layouts.auth')

@section('title', 'Reset password')

@section('content')
    <x-auth-header title="Reset your password" description="We will send a secure reset link to the email connected to your account." />
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
        @csrf

        <flux:input name="email" label="Email address" :value="old('email')" type="email" required autofocus autocomplete="email" placeholder="you@company.com" />
        <flux:button variant="primary" type="submit" class="w-full">Email reset link</flux:button>
    </form>

    <p class="mt-7 text-center text-sm">
        <a href="{{ route('login') }}" class="font-semibold text-teal-700 hover:text-teal-900">Return to log in</a>
    </p>
@endsection
