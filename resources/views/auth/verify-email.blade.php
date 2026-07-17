@extends('layouts.auth')

@section('title', 'Verify email')

@section('content')
    <x-auth-header title="Check your inbox" description="Verify your email address before entering the workspace. This protects your account and future organization data." />

    @if (session('status') === 'verification-link-sent')
        <div class="mb-6 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-800">
            A new verification link has been sent.
        </div>
    @endif

    <div class="space-y-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <flux:button variant="primary" type="submit" class="w-full">Resend verification email</flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="w-full">Log out</flux:button>
        </form>
    </div>
@endsection
