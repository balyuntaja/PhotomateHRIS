<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login as CustomLogin;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\InventoryStockPage;
use App\Filament\Pages\KeuanganInputTransaksi;
use App\Filament\Pages\RekapPerbulan;
use App\Filament\Resources\SessionReportResource\Pages\ListSessionReports;
use Illuminate\Support\Facades\Blade;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->brandName('Photomate')
            ->font('Poppins')
            ->authGuard('web')
            ->databaseNotifications(true, false)
            ->colors([
                'primary' => self::primaryPalette(),
                'gray' => \Filament\Support\Colors\Color::Slate,
                'danger' => \Filament\Support\Colors\Color::Red,
                'success' => \Filament\Support\Colors\Color::Emerald,
                'warning' => [
                    50 => '255, 251, 235',
                    100 => '254, 243, 199',
                    200 => '253, 230, 138',
                    300 => '252, 211, 77',
                    400 => '245, 158, 11',
                    500 => '217, 119, 6',   // Amber 600
                    600 => '180, 83, 9',    // Amber 700 (Dark Amber, WCAG AA compliant 4.62:1 contrast with #FFF)
                    700 => '146, 64, 14',   // Amber 800
                    800 => '120, 53, 15',
                    900 => '69, 26, 3',
                    950 => '45, 16, 2',
                ],
                'info' => \Filament\Support\Colors\Color::Sky,
            ])
            ->theme($this->versionedAsset('css/filament/admin/theme.css'))
            ->maxContentWidth(MaxWidth::ScreenTwoExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->collapsedSidebarWidth('4.75rem')
            ->collapsibleNavigationGroups()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->favicon(asset('favicon.ico'))
            ->globalSearch(false)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Session Management')->icon('heroicon-o-camera'),
                NavigationGroup::make('Sistem Antrean')->icon('heroicon-o-ticket'),
                NavigationGroup::make('Operasional')->icon('heroicon-o-briefcase'),
                NavigationGroup::make('Manajemen Absensi')->icon('heroicon-o-clipboard-document-check'),
                NavigationGroup::make('Manajemen Penggajian')->icon('heroicon-o-banknotes'),
                NavigationGroup::make('Keuangan')->icon('heroicon-o-wallet'),
                NavigationGroup::make('Inventory')->icon('heroicon-o-cube'),
                NavigationGroup::make('Laporan')->icon('heroicon-o-chart-bar'),
                NavigationGroup::make('Data Master')->icon('heroicon-o-circle-stack'),
                NavigationGroup::make('CMS')->icon('heroicon-o-globe-alt'),
                NavigationGroup::make('Invoice')->icon('heroicon-o-document-text'),
                NavigationGroup::make('Keamanan')->icon('heroicon-o-shield-check'),
            ])
            ->authenticatedRoutes(function (Panel $panel): void {
                // Modul Keuangan: Alamat /admin/keuangan diarahkan ke Rekap Perbulan sebagai halaman utama.
                Route::get('keuangan', function () use ($panel) {
                    abort_unless(RekapPerbulan::canAccess(), 403);

                    return redirect()->to(
                        RekapPerbulan::getUrl(panel: $panel->getId())
                    );
                })->name('keuangan.redirect');

                // Alamat lama Input Transaksi V2 diarahkan ke halaman Input Transaksi utama.
                Route::get('keuangan-input-transaksi-v2', fn () => redirect()->to(
                    KeuanganInputTransaksi::getUrl(panel: $panel->getId())
                ))->name('keuangan-input-transaksi-v2.redirect');

                // Alamat lama Stok per Cabang diarahkan ke tab terkait pada halaman Persediaan.
                Route::get('inventory-stok', function () use ($panel) {
                    abort_unless(InventoryStockPage::canAccess(), 403);

                    return redirect()->to(
                        InventoryStockPage::getUrl(['tab' => 'cabang'], panel: $panel->getId())
                    );
                })->name('inventory-stok.redirect');
            })
            ->renderHook(
                \Filament\View\PanelsRenderHook::PAGE_END,
                fn (): string => Blade::render('@livewire(\App\Livewire\EmployeeRevisionPopup::class)'),
                scopes: [
                    Dashboard::class,
                    ListSessionReports::class,
                ],
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
                fn (): string => view('filament.resources.session-reports.revision-alert-banner')->render(),
                scopes: [
                    ListSessionReports::class,
                ],
            )
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                    '<script src="'.asset('js/queue-poster.js').'"></script>'.
                    '<script src="'.asset('js/rupiah.js').'"></script>'.
                    '<script src="'.asset('js/evidence-upload.js').'?v='.filemtime(public_path('js/evidence-upload.js')).'"></script>'
                )
            );
    }

    /**
     * Tambahkan query string versi berbasis hash isi file agar cache browser/CDN
     * otomatis diperbarui ketika tema Filament dikompilasi ulang.
     */
    private function versionedAsset(string $path): string
    {
        $fullPath = public_path($path);
        $url = asset($path);

        if (! is_file($fullPath)) {
            return $url;
        }

        $hash = hash_file('md5', $fullPath) ?: (string) filemtime($fullPath);

        return $url.'?v='.substr($hash, 0, 12);
    }

    /**
     * Palette primary Photomate. Shade 600 adalah navy resmi #354B6D.
     *
     * @return array<int, string>
     */
    private static function primaryPalette(): array
    {
        return [
            50 => '244, 246, 249',
            100 => '232, 237, 243',
            200 => '207, 217, 229',
            300 => '170, 186, 207',
            400 => '119, 144, 174',
            500 => '64, 87, 124',
            600 => '53, 75, 109',
            700 => '43, 61, 89',
            800 => '35, 49, 72',
            900 => '27, 38, 55',
            950 => '15, 22, 33',
        ];
    }
}
