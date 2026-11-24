@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'icon' => null,
    'iconPosition' => 'before',
    'loading' => false,
    'tag' => 'button',
    'href' => null,
])

@php
    $variantClass = match($variant) {
        'primary' => 'pm-btn-primary',
        'secondary', 'gray' => 'pm-btn-secondary',
        'success' => 'pm-btn-success',
        'danger' => 'pm-btn-danger',
        'warning' => 'pm-btn-warning',
        'outline' => 'pm-btn-outline',
        'outline-primary' => 'pm-btn-outline pm-btn-outline-primary',
        'outline-danger' => 'pm-btn-outline pm-btn-outline-danger',
        'ghost' => 'pm-btn-ghost',
        default => 'pm-btn-primary',
    };

    $sizeClass = match($size) {
        'xs' => 'pm-btn-xs',
        'sm' => 'pm-btn-sm',
        'md' => 'pm-btn-md',
        'lg' => 'pm-btn-lg',
        default => 'pm-btn-md',
    };

    $iconSizeClass = match($size) {
        'xs' => 'w-3.5 h-3.5',
        'sm' => 'w-4 h-4',
        'md' => 'w-5 h-5',
        'lg' => 'w-5 h-5',
        default => 'w-4 h-4',
    };

    $classes = "pm-btn {$variantClass} {$sizeClass}";
@endphp

@if($tag === 'a' || $href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if($icon && $iconPosition === 'before')
            <x-filament::icon :icon="$icon" class="{{ $iconSizeClass }} shrink-0" />
        @endif

        {{ $slot }}

        @if($icon && $iconPosition === 'after')
            <x-filament::icon :icon="$icon" class="{{ $iconSizeClass }} shrink-0" />
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $disabled ? 'disabled' : '' }}
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if($icon && $iconPosition === 'before')
            <x-filament::icon :icon="$icon" class="{{ $iconSizeClass }} shrink-0" />
        @endif

        {{ $slot }}

        @if($icon && $iconPosition === 'after')
            <x-filament::icon :icon="$icon" class="{{ $iconSizeClass }} shrink-0" />
        @endif
    </button>
@endif
