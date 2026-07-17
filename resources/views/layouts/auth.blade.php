<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Secure access') | {{ config('app.name', 'Procura') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen antialiased">
        <main class="auth-shell auth-grid min-h-screen px-4 py-6 sm:px-8 lg:px-12">
            <div class="mx-auto grid min-h-[calc(100vh-3rem)] max-w-7xl overflow-hidden rounded-[2rem] border border-stone-200/80 bg-[#fffdf8]/85 backdrop-blur lg:grid-cols-[1.05fr_0.95fr]">
                <section class="relative hidden overflow-hidden bg-[#173b37] p-12 text-white lg:flex lg:flex-col lg:justify-between">
                    <div class="absolute -right-28 -top-24 size-80 rounded-full border border-white/10"></div>
                    <div class="absolute -bottom-32 -left-20 size-96 rounded-full bg-[#d59a5b]/15 blur-2xl"></div>

                    <a href="{{ route('home') }}" class="relative flex items-center gap-3" aria-label="Procura home">
                        <span class="grid size-10 place-items-center rounded-xl border border-white/20 bg-white/10 font-display text-xl">P</span>
                        <span class="text-lg font-semibold tracking-[0.16em]">PROCURA</span>
                    </a>

                    <div class="relative max-w-xl">
                        <p class="mb-5 text-xs font-bold uppercase tracking-[0.28em] text-[#e6b77d]">Global market intelligence</p>
                        <h1 class="font-display text-5xl leading-[1.08] text-balance">Make every market decision with evidence.</h1>
                        <p class="mt-6 max-w-lg text-base leading-7 text-emerald-50/75">
                            Procura turns fragmented listing data into clear, explainable signals for professional buyers and sellers.
                        </p>
                    </div>

                    <div class="relative grid grid-cols-3 gap-3 text-xs text-emerald-50/70">
                        <div class="border-t border-white/15 pt-4"><strong class="block text-sm text-white">Global</strong>Country-aware</div>
                        <div class="border-t border-white/15 pt-4"><strong class="block text-sm text-white">Traceable</strong>Evidence first</div>
                        <div class="border-t border-white/15 pt-4"><strong class="block text-sm text-white">Secure</strong>Tenant ready</div>
                    </div>
                </section>

                <section class="flex items-center justify-center px-6 py-10 sm:px-12 lg:px-16">
                    <div class="auth-card w-full max-w-md">
                        <a href="{{ route('home') }}" class="mb-10 inline-flex items-center gap-3 lg:hidden" aria-label="Procura home">
                            <span class="grid size-10 place-items-center rounded-xl bg-[#173b37] font-display text-xl text-white">P</span>
                            <span class="font-semibold tracking-[0.16em] text-[#173b37]">PROCURA</span>
                        </a>

                        @yield('content')
                    </div>
                </section>
            </div>
        </main>

        @fluxScripts
    </body>
</html>
