@php
    use Filament\Support\Facades\FilamentView;

    $color = $this->getColor();
@endphp

<div class="w-full">
    <div
        @if (FilamentView::hasSpaMode())
            x-load="visible"
        @else
            x-load
        @endif
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        wire:ignore
        x-data="chart({
            cachedData: @js($this->getCachedData()),
            options: @js($this->getOptions()),
            type: @js($this->getType()),
        })"
        class="w-full relative"
    >
        <canvas
            x-ref="canvas"
            style="max-height: 240px; height: 240px;"
        ></canvas>

        <span x-ref="backgroundColorElement" class="hidden"></span>
        <span x-ref="borderColorElement" class="hidden"></span>
        <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-700"></span>
        <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
    </div>
</div>
