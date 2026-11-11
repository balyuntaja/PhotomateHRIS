<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * COA awal Photomate. Kode akun menjadi identitas utama seed.
     */
    private const ACCOUNTS = [
        ['code' => '1001', 'name' => 'Kas/Bank Photomate Express', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 10],
        ['code' => '1002', 'name' => 'Kas/Bank Golio', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 20],
        ['code' => '1003', 'name' => 'Kas/Bank Janus', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 30],
        ['code' => '1004', 'name' => 'Kas/Bank Ekspansi', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 40],
        ['code' => '1100', 'name' => 'Piutang Usaha', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 50],
        ['code' => '1200', 'name' => 'Persediaan', 'type' => 'asset', 'report_group' => 'current_asset', 'sort_order' => 60],
        ['code' => '1500', 'name' => 'Aset Tetap', 'type' => 'asset', 'report_group' => 'fixed_asset', 'sort_order' => 70],
        ['code' => '2000', 'name' => 'Utang Usaha', 'type' => 'liability', 'report_group' => 'current_liability', 'sort_order' => 80],
        ['code' => '3000', 'name' => 'Modal Pemilik', 'type' => 'equity', 'report_group' => 'equity', 'sort_order' => 90],
        ['code' => '4000', 'name' => 'Pendapatan Booth', 'type' => 'revenue', 'report_group' => 'revenue', 'sort_order' => 100],
        ['code' => '5000', 'name' => 'HPP Kertas Foto', 'type' => 'cogs', 'report_group' => 'hpp', 'sort_order' => 110],
        ['code' => '5010', 'name' => 'HPP Tinta Printer', 'type' => 'cogs', 'report_group' => 'hpp', 'sort_order' => 120],
        ['code' => '5020', 'name' => 'HPP Consumables', 'type' => 'cogs', 'report_group' => 'hpp', 'sort_order' => 130],
        ['code' => '5030', 'name' => 'HPP Biaya Produksi Booth Lainnya', 'type' => 'cogs', 'report_group' => 'hpp', 'sort_order' => 140],
        ['code' => '5100', 'name' => 'Beban Operasional', 'type' => 'expense', 'report_group' => 'opex', 'sort_order' => 150],
    ];

    private const NORMAL_BALANCE = [
        'asset' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'revenue' => 'credit',
        'cogs' => 'debit',
        'expense' => 'debit',
    ];

    /**
     * Hubungkan akun kas/bank existing berdasarkan NAMA, bukan ID cabang.
     */
    private const FINANCIAL_ACCOUNT_MAP = [
        'Photomate Express' => 'Kas/Bank Photomate Express',
        'Golio' => 'Kas/Bank Golio',
        'Janus' => 'Kas/Bank Janus',
        'Ekspansi' => 'Kas/Bank Ekspansi',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ACCOUNTS as $account) {
            if (DB::table('chart_of_accounts')->where('code', $account['code'])->exists()) {
                continue;
            }

            $equivalent = DB::table('chart_of_accounts')
                ->where('name', $account['name'])
                ->where('type', $account['type'])
                ->first();

            if ($equivalent) {
                continue;
            }

            DB::table('chart_of_accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'normal_balance' => self::NORMAL_BALANCE[$account['type']],
                'parent_id' => null,
                'report_group' => $account['report_group'],
                'description' => null,
                'is_active' => true,
                'sort_order' => $account['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::FINANCIAL_ACCOUNT_MAP as $financialAccountName => $chartOfAccountName) {
            $chartOfAccountId = DB::table('chart_of_accounts')
                ->where('name', $chartOfAccountName)
                ->value('id');

            if ($chartOfAccountId === null) {
                continue;
            }

            DB::table('financial_accounts')
                ->where('name', $financialAccountName)
                ->whereNull('chart_of_account_id')
                ->update(['chart_of_account_id' => $chartOfAccountId]);
        }
    }

    public function down(): void
    {
        foreach (self::FINANCIAL_ACCOUNT_MAP as $financialAccountName => $chartOfAccountName) {
            $chartOfAccountId = DB::table('chart_of_accounts')
                ->where('name', $chartOfAccountName)
                ->value('id');

            if ($chartOfAccountId === null) {
                continue;
            }

            DB::table('financial_accounts')
                ->where('name', $financialAccountName)
                ->where('chart_of_account_id', $chartOfAccountId)
                ->update(['chart_of_account_id' => null]);
        }

        foreach (self::ACCOUNTS as $account) {
            $chartOfAccount = DB::table('chart_of_accounts')
                ->where('code', $account['code'])
                ->orWhere(function ($query) use ($account): void {
                    $query->where('name', $account['name'])->where('type', $account['type']);
                })
                ->first();

            if (! $chartOfAccount) {
                continue;
            }

            $isReferenced = DB::table('chart_of_accounts')->where('parent_id', $chartOfAccount->id)->exists()
                || DB::table('financial_accounts')->where('chart_of_account_id', $chartOfAccount->id)->exists()
                || DB::table('category_account_mappings')->where('debit_account_id', $chartOfAccount->id)->exists()
                || DB::table('category_account_mappings')->where('credit_account_id', $chartOfAccount->id)->exists()
                || DB::table('journal_lines')->where('chart_of_account_id', $chartOfAccount->id)->exists();

            if ($isReferenced) {
                continue;
            }

            DB::table('chart_of_accounts')->where('id', $chartOfAccount->id)->delete();
        }
    }
};
