<?php

namespace App\Filament\Resources\InventoryTransactionResource\Pages;

use App\Filament\Resources\InventoryTransactionResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Cabang;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateInventoryTransaction extends CreateRecord
{
    protected static string $resource = InventoryTransactionResource::class;

    protected static ?string $title = 'Catat Transaksi Stok';

    public function mount(): void
    {
        parent::mount();

        $itemId = request()->integer('item');
        $cabangId = request()->query('cabang');
        $cabangId = is_string($cabangId) ? $cabangId : '';

        $this->form->fill([
            'jenis_transaksi' => InventoryTransaction::TYPE_IN,
            'tanggal_transaksi' => now()->toDateString(),
            'inventory_item_id' => $itemId > 0 && InventoryItem::query()->active()->whereKey($itemId)->exists()
                ? $itemId
                : null,
            'cabang_id' => $cabangId !== '' && Cabang::query()->whereKey($cabangId)->exists()
                ? $cabangId
                : null,
            'idempotency_key' => Str::uuid()->toString(),
        ]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Simpan Transaksi')
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi Transaksi Stok')
            ->modalDescription('Pastikan jenis transaksi, barang, cabang, dan jumlah sudah benar. Perubahan stok akan langsung dicatat pada riwayat dan tidak dapat diubah atau dihapus.')
            ->modalSubmitActionLabel('Ya, simpan');
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InventoryService::class)->record($data);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Transaksi stok berhasil dicatat';
    }
}
