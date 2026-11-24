<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class KeuanganDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?int $navigationSort = 99;

    protected static ?string $title = 'Keuangan';

    protected static ?string $slug = 'keuangan';

    // Halaman ini extends Dashboard, sehingga path route diambil dari $routePath (bukan $slug).
    protected static string $routePath = '/keuangan';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin()
            || ($user?->can('view_keuangan_dashboard') ?? false)
            || ($user?->can('view_keuangan_report') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->redirect(RekapPerbulan::getUrl());
    }

    /**
     * Dashboard keuangan dinonaktifkan: halaman utama dialihkan ke Rekap Perbulan.
     *
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Keuangan';
    }
}
