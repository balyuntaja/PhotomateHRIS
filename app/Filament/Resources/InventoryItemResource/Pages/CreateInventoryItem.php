<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected static ?string $title = 'Tambah Barang';

    protected function handleRecordCreation(array $data): Model
    {
        $cabangId = $data['cabang_id'] ?? null;
        $stokAwal = (int) ($data['stok_awal'] ?? 0);

        unset($data['cabang_id'], $data['stok_awal']);

        try {
            return DB::transaction(function () use ($data, $cabangId, $stokAwal): Model {
                $record = parent::handleRecordCreation($data);

                app(InventoryService::class)->registerBranchStock(
                    $record,
                    is_string($cabangId) ? $cabangId : null,
                    $stokAwal,
                    (int) $record->min_stok_default,
                );

                return $record;
            });
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
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
