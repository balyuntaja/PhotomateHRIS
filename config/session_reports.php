<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone Batas Input Rekap Sesi
    |--------------------------------------------------------------------------
    |
    | Dipakai untuk menghitung batas waktu input/edit/delete rekap sesi
    | (tanggal rekap + 2 hari pukul 23:59:59) untuk role Karyawan.
    | Default WIB karena operasional Photomate berjalan di Indonesia.
    |
    */

    'timezone' => env('SESSION_REPORT_TIMEZONE', 'Asia/Jakarta'),

];
