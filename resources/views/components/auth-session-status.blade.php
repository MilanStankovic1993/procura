@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'mb-6 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-800']) }}>
        {{ $status }}
    </div>
@endif
