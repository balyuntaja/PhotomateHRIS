<?php

namespace App\Filament\Resources\ChartOfAccountResource\Pages;

use App\Filament\Resources\ChartOfAccountResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Models\ChartOfAccount;
use App\Services\AccountingMasterDataService;
use Filament\Actions;
use Illuminate\Support\Facades\Auth;

class ListChartOfAccounts extends ListRecords
{
    protected static string $resource = ChartOfAccountResource::class;

    protected static ?string $title = 'Chart of Accounts';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Akun')
                ->icon('heroicon-o-plus')
                ->modalHeading('Tambah Akun COA')
                ->modalSubmitActionLabel('Simpan')
                ->createAnother(false)
                ->successNotificationTitle('Akun berhasil disimpan.')
                ->using(function (array $data): ChartOfAccount {
                    $reason = $data['change_reason'] ?? null;
                    unset($data['change_reason']);

                    return app(AccountingMasterDataService::class)->createAccount($data, Auth::user(), $reason);
                }),
        ];
    }
}
