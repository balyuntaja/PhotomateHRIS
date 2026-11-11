<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Capitalization Policy (SIMULATION / PROPOSAL)
    |--------------------------------------------------------------------------
    | Nilai default di bawah adalah simulasi Phase 2.2.4 dan belum menjadi
    | kebijakan final. Threshold, useful life, dan metode harus disetujui
    | pemilik bisnis sebelum depreciation engine diimplementasikan.
    */
    'capitalization' => [
        'minimum_amount' => (int) env('ACCOUNTING_CAPITALIZATION_MIN', 1_000_000),
        'threshold_inclusive' => (bool) env('ACCOUNTING_CAPITALIZATION_INCLUSIVE', true),
        'depreciation_method' => env('ACCOUNTING_DEPRECIATION_METHOD', 'straight_line'),
        'residual_value' => (int) env('ACCOUNTING_DEPRECIATION_RESIDUAL', 0),
        'depreciation_start' => env('ACCOUNTING_DEPRECIATION_START', 'month_after_in_service'),
        'pre_system_assets' => env('ACCOUNTING_PRE_SYSTEM_ASSETS', 'evidence_required'),

        'useful_life_years' => [
            'kamera' => 4,
            'printer' => 5,
            'lighting' => 3,
            'tripod' => 3,
            'booth' => 3,
            'leasehold_improvement' => 5,
            'background_reusable' => 3,
            'perlengkapan' => 0,
        ],

        'asset_categories' => [
            'peralatan_aset_tetap' => [
                'label' => 'Peralatan — Aset Tetap',
                'useful_life_key' => 'kamera',
                'coa_code' => '1500',
            ],
            'peralatan_perlengkapan' => [
                'label' => 'Peralatan — Perlengkapan',
                'useful_life_key' => 'perlengkapan',
                'coa_code' => '5100',
            ],
            'properti_aset_leasehold' => [
                'label' => 'Properti — Aset/Leasehold Improvement',
                'useful_life_key' => 'leasehold_improvement',
                'coa_code' => '1500',
            ],
            'background_aset_reusable' => [
                'label' => 'Background — Aset/Reusable',
                'useful_life_key' => 'background_reusable',
                'coa_code' => '1500',
            ],
        ],
    ],

    'cash_flow' => [
        /*
        |----------------------------------------------------------------------
        | Cash & Bank Accounts
        |----------------------------------------------------------------------
        | Akun ini menjadi dasar rekonsiliasi perubahan kas. COA dengan
        | cash_flow_category = cash juga otomatis dianggap akun kas.
        */
        'cash_coa_codes' => ['1001', '1002', '1003', '1004'],

        /*
        |----------------------------------------------------------------------
        | Interest Paid Policy
        |----------------------------------------------------------------------
        | Default operating. Jika kelak ada COA khusus bunga pinjaman,
        | tambahkan kodenya ke interest_coa_codes atau set cash_flow_category
        | pada COA tersebut. Jangan mengandalkan kode 5100 generik.
        */
        'interest_paid_category' => env('ACCOUNTING_INTEREST_CASH_FLOW', 'operating'),
        'interest_coa_codes' => [],
    ],
];
