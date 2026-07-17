@extends('layouts.auth')

@section('title', 'Choose new password')

@section('content')
    <x-auth-header title="Choose a new password" description="Set a strong password that you do not use for another service." />
    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <flux:input name="email" label="Email address" value="{{ request('email') }}" type="email" required autocomplete="email" />
        <flux:input name="password" label="New password" type="password" required autofocus autocomplete="new-password" viewable />
        <flux:input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" viewable />

        <p class="text-xs leading-5 text-stone-500">Use at least 12 characters with uppercase, lowercase, number, and symbol.</p>

        <flux:button variant="primary" type="submit" class="w-full">Reset password</flux:button>
    </form>
@endsection
