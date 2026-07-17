<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Dashboard') | {{ config('app.name', 'Procura') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-[#f5f1e8] antialiased">
        <header class="border-b border-stone-200 bg-[#fffdf8]">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl bg-[#173b37] font-display text-xl text-white">P</span>
                    <span class="font-semibold tracking-[0.16em] text-[#173b37]">PROCURA</span>
                </a>

                <div class="flex items-center gap-4">
                    <span class="hidden text-sm text-stone-600 sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:button type="submit" variant="ghost" size="sm">Log out</flux:button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-5 py-12 sm:px-8">
            @yield('content')
        </main>

        @fluxScripts
    </body>
</html>
