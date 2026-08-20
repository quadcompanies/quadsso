@props([
    'text' => null,
    'unstyled' => false,
])

@php
    $label = $text ?: config('quadsso.ui.button_label', 'Login via SSO');

    $incomingClasses = (string) $attributes->get('class', '');

    // Structural only — nothing here fixes a colour, so callers can recolour
    // without fighting the defaults.
    $structure = 'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2.5'
        . ' text-sm font-semibold shadow-sm transition'
        . ' focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2';

    // Tailwind resolves conflicting utilities by stylesheet order, not by the
    // order they appear in the class attribute — so emitting both bg-indigo-600
    // and a caller's bg-emerald-600 would give an unpredictable winner. Drop the
    // default palette entirely as soon as the caller supplies a background.
    $bringsOwnPalette = (bool) preg_match('/(^|\s)(bg-|dark:bg-)/', $incomingClasses);

    $palette = $bringsOwnPalette
        ? ''
        : 'bg-indigo-600 text-white hover:bg-indigo-500 focus-visible:ring-indigo-600';

    $classes = $unstyled ? '' : trim($structure . ' ' . $palette);
@endphp

<a
    href="{{ route('sso.redirect') }}"
    {{ $attributes->merge($classes === '' ? [] : ['class' => $classes]) }}
>{{ $slot->isEmpty() ? $label : $slot }}</a>
