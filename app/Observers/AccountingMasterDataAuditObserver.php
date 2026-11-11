<?php

namespace App\Observers;

use App\Models\AccountingMasterDataAuditLog;
use App\Services\AccountingAuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class AccountingMasterDataAuditObserver
{
    private const IGNORED_ATTRIBUTES = ['created_at', 'updated_at'];

    public function created(Model $model): void
    {
        $this->write($model, AccountingMasterDataAuditLog::ACTION_CREATE, null, $this->snapshot($model));
    }

    public function updated(Model $model): void
    {
        $dirty = Arr::except($model->getDirty(), self::IGNORED_ATTRIBUTES);

        if ($dirty === []) {
            return;
        }

        $action = AccountingMasterDataAuditLog::ACTION_UPDATE;

        if ($model->wasChanged('is_active')) {
            $action = $model->is_active
                ? AccountingMasterDataAuditLog::ACTION_ACTIVATE
                : AccountingMasterDataAuditLog::ACTION_DEACTIVATE;
        }

        $oldValues = Arr::only($model->getOriginal(), array_keys($dirty));

        $this->write($model, $action, $oldValues, $dirty);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, AccountingMasterDataAuditLog::ACTION_DELETE, $this->snapshot($model), null);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function write(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $actorId = AccountingAuditContext::actorId()
            ?? (Auth::check() ? Auth::id() : null);

        $reason = AccountingAuditContext::reason()
            ?? (Auth::check() ? 'Perubahan melalui aplikasi/model' : 'Perubahan melalui sistem/CLI');

        AccountingMasterDataAuditLog::create([
            'table_name' => $model->getTable(),
            'record_id' => (string) $model->getKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'actor_id' => $actorId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        return Arr::except($model->getAttributes(), self::IGNORED_ATTRIBUTES);
    }
}
