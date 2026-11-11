<?php

namespace App\Services;

use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\FinancialCategory;
use App\Models\Karyawan;
use RuntimeException;

class AccountingMasterDataService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data, ?Karyawan $actor = null, ?string $reason = null): FinancialCategory
    {
        $this->assertActorCan($actor, 'create_keuangan_category');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Pembuatan kategori melalui service terkontrol',
            fn (): FinancialCategory => FinancialCategory::create($data)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(FinancialCategory $category, array $data, ?Karyawan $actor = null, ?string $reason = null): FinancialCategory
    {
        $this->assertActorCan($actor, 'update_keuangan_category');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Perubahan kategori melalui service terkontrol',
            function () use ($category, $data): FinancialCategory {
                $category->fill($data)->save();

                return $category->refresh();
            }
        );
    }

    public function deactivateCategory(FinancialCategory $category, ?Karyawan $actor = null, ?string $reason = null): FinancialCategory
    {
        return $this->updateCategory($category, ['is_active' => false], $actor, $reason ?: 'Deaktivasi kategori');
    }

    public function deleteCategory(FinancialCategory $category, ?Karyawan $actor = null, ?string $reason = null): bool
    {
        $this->assertActorCan($actor, 'delete_keuangan_category');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Penghapusan kategori',
            fn (): bool => (bool) $category->delete()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMapping(array $data, ?Karyawan $actor = null, ?string $reason = null): CategoryAccountMapping
    {
        $this->assertActorCan($actor, 'create_category_account_mapping');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Pembuatan mapping melalui service terkontrol',
            fn (): CategoryAccountMapping => CategoryAccountMapping::create($data)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMapping(CategoryAccountMapping $mapping, array $data, ?Karyawan $actor = null, ?string $reason = null): CategoryAccountMapping
    {
        $this->assertActorCan($actor, 'update_category_account_mapping');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Perubahan mapping melalui service terkontrol',
            function () use ($mapping, $data): CategoryAccountMapping {
                $mapping->fill($data)->save();

                return $mapping->refresh();
            }
        );
    }

    public function deactivateMapping(CategoryAccountMapping $mapping, ?Karyawan $actor = null, ?string $reason = null): CategoryAccountMapping
    {
        return $this->updateMapping($mapping, ['is_active' => false], $actor, $reason ?: 'Deaktivasi mapping');
    }

    public function deleteMapping(CategoryAccountMapping $mapping, ?Karyawan $actor = null, ?string $reason = null): bool
    {
        $this->assertActorCan($actor, 'delete_category_account_mapping');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Penghapusan mapping',
            fn (): bool => (bool) $mapping->delete()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccount(array $data, ?Karyawan $actor = null, ?string $reason = null): ChartOfAccount
    {
        $this->assertActorCan($actor, 'create_chart_of_account');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Pembuatan COA melalui service terkontrol',
            fn (): ChartOfAccount => ChartOfAccount::create($data)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(ChartOfAccount $account, array $data, ?Karyawan $actor = null, ?string $reason = null): ChartOfAccount
    {
        $this->assertActorCan($actor, 'update_chart_of_account');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Perubahan COA melalui service terkontrol',
            function () use ($account, $data): ChartOfAccount {
                $account->fill($data)->save();

                return $account->refresh();
            }
        );
    }

    public function deactivateAccount(ChartOfAccount $account, ?Karyawan $actor = null, ?string $reason = null): ChartOfAccount
    {
        return $this->updateAccount($account, ['is_active' => false], $actor, $reason ?: 'Deaktivasi COA');
    }

    public function deleteAccount(ChartOfAccount $account, ?Karyawan $actor = null, ?string $reason = null): bool
    {
        $this->assertActorCan($actor, 'delete_chart_of_account');

        return AccountingAuditContext::run(
            $actor,
            $reason ?: 'Penghapusan COA',
            fn (): bool => (bool) $account->delete()
        );
    }

    private function assertActorCan(?Karyawan $actor, string $permission): void
    {
        if (! $actor) {
            throw new RuntimeException('Actor wajib diisi untuk perubahan master data akuntansi.');
        }

        if (! $actor->isSuperAdmin() && ! $actor->can($permission)) {
            throw new RuntimeException('Actor tidak memiliki izin untuk mengubah master data akuntansi.');
        }
    }
}
