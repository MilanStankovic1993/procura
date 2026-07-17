@extends('layouts.auth')

@section('title', 'Confirm password')

@section('content')
    <x-auth-header title="Confirm it is you" description="This protected action requires your current password." />

    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-6">
        @csrf

        <flux:input name="password" label="Current password" type="password" required autofocus autocomplete="current-password" viewable />
        <flux:button variant="primary" type="submit" class="w-full">Confirm password</flux:button>
    </form>
@endsection
