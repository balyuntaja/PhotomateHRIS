<?php

namespace App\Observers;

use App\Models\FinancialTransaction;
use App\Services\LuminashImportService;
use App\Services\LuminashRekapService;
use App\Services\TransactionAccountingService;
use Illuminate\Support\Facades\Auth;

class FinancialTransactionObserver
{
    public function __construct(
        protected TransactionAccountingService $accountingService
    ) {}

    public function created(FinancialTransaction $transaction): void
    {
        $this->accountingService->syncFinancialTransaction($transaction, Auth::user());
    }

    public function updated(FinancialTransaction $transaction): void
    {
        if ($transaction->source_type === LuminashImportService::SOURCE) {
            if (! LuminashRekapService::isConsolidating()) {
                $this->accountingService->syncLuminashAfterComponentChange($transaction, Auth::user());
            }

            return;
        }

        $this->accountingService->syncFinancialTransaction($transaction, Auth::user());
    }

    public function deleted(FinancialTransaction $transaction): void
    {
        if ($transaction->source_type === LuminashImportService::SOURCE) {
            if (! LuminashRekapService::isConsolidating()) {
                $this->accountingService->syncLuminashAfterComponentChange($transaction, Auth::user());
            }

            return;
        }

        $this->accountingService->reverseFinancialTransaction(
            $transaction,
            "Transaksi operasional #{$transaction->id} dihapus",
            Auth::user()
        );
    }

    public function restored(FinancialTransaction $transaction): void
    {
        if ($transaction->source_type === LuminashImportService::SOURCE) {
            $this->accountingService->syncLuminashAfterComponentChange($transaction, Auth::user());

            return;
        }

        $this->accountingService->syncFinancialTransaction($transaction, Auth::user());
    }
}
