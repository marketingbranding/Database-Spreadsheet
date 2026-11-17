<?php

return [
    'import_conflicts_enabled' => env('MARISON_IMPORT_CONFLICTS_ENABLED', false),

    'sheets' => [
        'credentials' => env('GOOGLE_SHEETS_CREDENTIALS', storage_path('app/google/service-account.json')),
        'ca_bundle' => env('GOOGLE_SHEETS_CA_BUNDLE'),
        'timeout' => (int) env('GOOGLE_SHEETS_TIMEOUT', 30),
        'token_cache' => storage_path('framework/cache/google-sheets-token.json'),
    ],

    'import' => [
        'tabs' => [
            'Data Kav',
            'Data Konsumen',
            'PSJB',
            'BI Checking',
            'Pemberkasan',
            'Proses Bank',
            'PPJB Developer',
            'Akad',
            'BAST',
        ],
        'stage_tabs' => [
            'PSJB', 'BI Checking', 'Pemberkasan', 'Proses Bank',
            'PPJB Developer', 'Akad', 'BAST',
        ],
        'hq_export_tab' => 'hq_report_export',
    ],

    'hq_export_metrics' => [
        'TOTAL_RUMAH', 'BELUM_TERJUAL', 'SUDAH_TERJUAL', 'SEDANG_DIPROSES',
        'PEMBERKASAN', 'PROSES_BANK', 'PPJB', 'AKAD', 'SERAH_TERIMA',
        'BELUM_TERPETAKAN', 'TOTAL_POSISI', 'SELISIH_POSISI', 'BI_CHECKING',
    ],
];
