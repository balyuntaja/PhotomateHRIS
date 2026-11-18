<?php

namespace App\Providers;

use App\Utils\RupiahHelper;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // Paksa semua URL asset dan routing menggunakan HTTPS jika diakses lewat proxy Railway
        if (
            $this->app->environment('production') &&
            request()->header('X-Forwarded-Proto') === 'https'
        ) {
            URL::forceScheme('https');
        }

        \Illuminate\Support\Facades\Gate::policy(\App\Models\SessionReport::class, \App\Policies\SessionReportPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(\App\Models\InventoryItem::class, \App\Policies\InventoryItemPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(\App\Models\InventoryStock::class, \App\Policies\InventoryStockPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(\App\Models\InventoryTransaction::class, \App\Policies\InventoryTransactionPolicy::class);

        \App\Models\Invoice::observe(\App\Observers\InvoiceObserver::class);

        DateTimePicker::configureUsing(fn(DateTimePicker $component) => $component->native(false));

        TextInput::macro('rupiah', function (?string $prefix = 'Rp', bool $allowNegative = false): static {
            /** @var TextInput $this */
            if ($prefix !== null) {
                $this->prefix($prefix);
            }

            $maskJs = $allowNegative
                ? "\$input.startsWith('-') ? '-' + \$money(\$input.slice(1), ',', '.', 0) : \$money(\$input, ',', '.', 0)"
                : "\$money(\$input, ',', '.', 0)";

            return $this
                ->type('text')
                ->inputMode('numeric')
                ->mask(RawJs::make($maskJs))
                ->stripCharacters(['.', ','])
                ->formatStateUsing(fn ($state): ?string => filled($state) ? RupiahHelper::number($state) : (is_string($state) ? $state : null))
                ->mutateStateForValidationUsing(function ($state) use ($allowNegative) {
                    if (blank($state)) {
                        return null;
                    }
                    $clean = RupiahHelper::toFloat($state);
                    return $allowNegative ? (int) round($clean) : max(0, (int) round($clean));
                })
                ->dehydrateStateUsing(function ($state) use ($allowNegative) {
                    if (blank($state)) {
                        return null;
                    }
                    $clean = RupiahHelper::toFloat($state);
                    return $allowNegative ? (int) round($clean) : max(0, (int) round($clean));
                });
        });

        FilamentColor::register([
            // Default colors
            'danger' => Color::Red,
            'gray' => Color::Zinc,
            'info' => Color::Blue,
            'primary' => Color::hex('#364e71'),
            'success' => Color::Green,
            'warning' => Color::Amber,

            // Extended color palette
            'purple' => Color::Purple,
            'pink' => Color::Pink,
            'teal' => Color::Teal,
            'orange' => Color::Orange,
            'lime' => Color::Lime,
            'indigo' => Color::Indigo,
            'yellow' => Color::Yellow,
            'emerald' => Color::Emerald,
            'sky' => Color::Sky,
            'red' => Color::Red,
            'blue' => Color::Blue,
            'green' => Color::Green,
            'amber' => Color::Amber,
            'zinc' => Color::Zinc,
            'cyan' => Color::Cyan,

            // Additional colors
            'slate' => Color::Slate,
            'stone' => Color::Stone,
            'neutral' => Color::Neutral,
            'rose' => Color::Rose,
            'fuchsia' => Color::Fuchsia,
            'violet' => Color::Violet,
            'lightBlue' => Color::Sky,
            'coolGray' => Color::Gray,
            'warmGray' => Color::Stone,
            'trueGray' => Color::Neutral,
            'blueGray' => Color::Slate,

            // Custom semantic colors
            'brand' => Color::hex('#364e71'),
            'secondary' => Color::Slate,
            'accent' => Color::Purple,
            'muted' => Color::Gray,
            'destructive' => Color::Red,
            'constructive' => Color::Green,
            'informative' => Color::Blue,
            'cautionary' => Color::Amber,

            // Status colors
            'active' => Color::Green,
            'inactive' => Color::Gray,
            'pending' => Color::Yellow,
            'approved' => Color::Emerald,
            'rejected' => Color::Red,
            'draft' => Color::Slate,
            'published' => Color::Blue,
            'archived' => Color::Zinc,

            // Role-based colors (for HR system)
            'admin' => Color::Purple,
            'manager' => Color::Indigo,
            'hr' => Color::Teal,
            'finance' => Color::Emerald,
            'ceo' => Color::Amber,
            'karyawan' => Color::Blue,

            // Priority colors
            'low' => Color::Green,
            'medium' => Color::Yellow,
            'high' => Color::Orange,
            'urgent' => Color::Red,
            'critical' => Color::Rose,
        ]);
    }
}
