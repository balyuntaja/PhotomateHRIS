<?php

use App\Http\Controllers\LaporanKeuanganController;
use App\Http\Controllers\LaporanKinerjaController;
use App\Http\Controllers\RekapitulasiAbsensiController;
use App\Http\Controllers\SlipGajiController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    $cmsData = [
        'articles' => \App\Models\Article::where('is_published', true)->orderBy('published_at', 'desc')->get()->map(function($a) {
            return [
                'id' => $a->id,
                'title' => $a->title,
                'shortDescription' => $a->short_description,
                'date' => $a->published_at ? \Carbon\Carbon::parse($a->published_at)->translatedFormat('d F Y') : '',
                'image' => $a->image ? '/storage/' . $a->image : null,
                'body' => $a->body,
            ];
        }),
        'faqs' => \App\Models\Faq::where('is_active', true)->orderBy('order')->get(),
        'galleries' => \App\Models\Gallery::where('is_active', true)->get()->map(function($g) {
            return [
                'id' => $g->id,
                'title' => $g->title,
                'image' => '/storage/' . $g->image,
            ];
        }),
        'clients' => \App\Models\Client::where('is_active', true)->get()->map(function($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'logo' => '/storage/' . $c->logo,
            ];
        }),
        'schedules' => \App\Models\Schedule::all()->map(function($s) {
            return [
                'id' => $s->id,
                'title' => $s->title,
                'startDate' => $s->start_date->format('Y-m-d'),
                'endDate' => $s->end_date->format('Y-m-d'),
                'used' => $s->used,
                'capacity' => $s->capacity,
            ];
        }),
    ];
    return view('react.app', compact('cmsData'));
})->where('any', '^(?!admin|livewire|api|slip-gaji|laporan|rekapitulasi|invoice|storage).*');

Route::get('/slip-gaji/cetak/{tahun}/{bulan}', [SlipGajiController::class, 'cetakSemuaSlipGaji'])
    ->name('slip-gaji.cetak');

Route::get('/slip-gaji/cetak-individual/{karyawan_id}/{tahun}/{bulan}', [SlipGajiController::class, 'cetakSlipGajiIndividual'])
    ->name('slip-gaji.cetak-individual');

Route::get('/laporan-keuangan/cetak/{tahun}/{bulan}', [LaporanKeuanganController::class, 'cetak'])
    ->name('laporan-keuangan.cetak');

Route::get('/laporan-kinerja/cetak/{tahun}/{bulan}', [LaporanKinerjaController::class, 'cetak'])
    ->name('laporan-kinerja.cetak');

Route::get('/rekapitulasi-absensi/cetak', [RekapitulasiAbsensiController::class, 'cetak'])
    ->name('rekapitulasi-absensi.pdf');

Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'generatePdf'])
    ->name('invoice.pdf');
