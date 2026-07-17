@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <section class="overflow-hidden rounded-[2rem] border border-stone-200 bg-[#fffdf8]">
        <div class="grid gap-10 p-7 sm:p-10 lg:grid-cols-[1fr_0.72fr] lg:p-14">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.24em] text-teal-700">Foundation ready</p>
                <h1 class="mt-4 max-w-2xl font-display text-4xl leading-tight text-[#173b37] sm:text-5xl">
                    Welcome, {{ auth()->user()->name }}.
                </h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-stone-600">
                    Your verified Procura account is ready. Organization and market setup will be added in the next focused phase.
                </p>
            </div>

            <div class="rounded-2xl bg-[#173b37] p-7 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#e6b77d]">Account status</p>
                <dl class="mt-6 space-y-5 text-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-4">
                        <dt class="text-emerald-50/65">Email</dt>
                        <dd class="truncate font-medium">{{ auth()->user()->email }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-emerald-50/65">Verification</dt>
                        <dd class="font-medium text-emerald-200">Verified</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>
@endsection
