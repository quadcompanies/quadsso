@props([
    'text' => null,
    'unstyled' => false,
    'showErrors' => true,
    'errorClass' => null,
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

    // Failures redirect back here with a flashed message. Without somewhere to
    // render it the redirect is indistinguishable from a page refresh, so the
    // region lives with the button rather than relying on the host application
    // having wired up an error display.
    $ssoErrors = [];

    if ($showErrors && isset($errors) && $errors instanceof \Illuminate\Support\ViewErrorBag) {
        $ssoErrors = $errors->getBag((string) config('quadsso.ui.error_bag', 'quadsso'))->all();
    }

    $errorClasses = $errorClass
        ?? ($unstyled
            ? ''
            : 'mb-3 rounded-md bg-red-50 p-3 text-sm text-red-700'
                . ' dark:bg-red-950/50 dark:text-red-300');
@endphp

<div data-quadsso-login>
    @if ($showErrors)
        {{-- Always present so client-side code has somewhere to write, but only
             styled when populated, so an empty region stays invisible. --}}
        <div
            data-quadsso-login-error
            role="alert"
            aria-live="polite"
            @if ($ssoErrors !== [] && $errorClasses !== '') class="{{ $errorClasses }}" @endif
        >@foreach ($ssoErrors as $ssoError)<p>{{ $ssoError }}</p>@endforeach</div>
    @endif

    <a
        href="{{ route('sso.redirect') }}"
        {{ $attributes->merge($classes === '' ? [] : ['class' => $classes]) }}
    >{{ $slot->isEmpty() ? $label : $slot }}</a>
</div>
