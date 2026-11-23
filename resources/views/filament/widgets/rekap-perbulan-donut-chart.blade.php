@php
    use Filament\Support\Facades\FilamentView;

    $color = $this->getColor();
@endphp

<div class="w-full flex items-center justify-center">
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
        class="w-full flex items-center justify-center relative"
    >
        <div class="w-[190px] h-[190px] max-w-full aspect-square flex items-center justify-center relative">
            <canvas
                x-ref="canvas"
                style="max-height: 190px; max-width: 190px;"
            ></canvas>
        </div>

        <span x-ref="backgroundColorElement" class="hidden"></span>
        <span x-ref="borderColorElement" class="hidden"></span>
        <span x-ref="gridColorElement" class="hidden"></span>
        <span x-ref="textColorElement" class="hidden"></span>
    </div>
</div>
