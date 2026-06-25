<?php

use App\Http\Controllers\LaporanKeuanganController;
use App\Http\Controllers\LaporanKinerjaController;
use App\Http\Controllers\RekapitulasiAbsensiController;
use App\Http\Controllers\SlipGajiController;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    $bioSetting = \App\Models\BioSetting::first();

    $cmsData = [
        'bio_settings' => $bioSetting ? [
            'logo' => $bioSetting->logo ? '/storage/' . $bioSetting->logo : null,
            'title' => $bioSetting->title,
            'description' => $bioSetting->description,
            'instagram_url' => $bioSetting->instagram_url,
            'whatsapp_url' => $bioSetting->whatsapp_url,
            'tiktok_url' => $bioSetting->tiktok_url,
            'website_url' => $bioSetting->website_url,
        ] : null,
        'bio_photostrips' => \App\Models\BioPhotostrip::where('is_active', true)->orderBy('order')->get()->map(function($p) {
            return [
                'id' => $p->id,
                'title' => $p->title,
                'image' => '/storage/' . $p->image,
                'link' => $p->link,
            ];
        }),
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
                'category' => $g->category,
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

    // Determine SEO tags dynamically
    $path = request()->path();
    $seo = [
        'title' => 'Photomate.id - Jasa Sewa Photobooth Premium Malang & Jawa Timur',
        'description' => 'Photomate.id menyediakan jasa penyewaan photobooth premium di Malang, Jawa Timur. Cetak foto instan kecepatan tinggi dengan template kustom untuk pernikahan, corporate event, ulang tahun, dan wisuda.',
        'keywords' => 'photobooth malang, sewa photobooth malang, photobooth premium jawa timur, souvenir foto instan, cetak foto cepat, photomate, photobooth murah malang, photobooth pernikahan',
        'image' => asset('logophotomateblue.png'),
        'url' => request()->url(),
        'type' => 'website',
        'schema' => null,
    ];

    // Specific SEO routes
    if (str_starts_with($path, 'pricing/sewa')) {
        $seo['title'] = 'Paket Sewa Photobooth Premium - Photomate.id';
        $seo['description'] = 'Pilihan paket sewa photobooth terbaik dengan cetak foto instan unlimited, template desain kustom, lighting profesional, dan kru ramah untuk wilayah Malang dan Jawa Timur.';
        $seo['keywords'] = 'paket sewa photobooth malang, sewa photobooth murah, photobooth pernikahan malang, harga sewa photobooth';
    } elseif (str_starts_with($path, 'pricing/self-run')) {
        $seo['title'] = 'Paket Mandiri (Self-Run) Photobooth - Photomate.id';
        $seo['description'] = 'Mulai bisnis photobooth Anda sendiri dengan paket Self-Run dari Photomate.id. Kami menyediakan sistem software photobooth siap pakai, peralatan premium, dan pelatihan penuh.';
        $seo['keywords'] = 'bisnis photobooth, software photobooth indonesia, paket kemitraan photobooth, alat photobooth';
    } elseif (str_starts_with($path, 'pricing/sharing-profit')) {
        $seo['title'] = 'Kemitraan Sharing Profit / Bagi Hasil Photobooth - Photomate.id';
        $seo['description'] = 'Penempatan unit photobooth Photomate.id dengan sistem kerjasama bagi hasil (sharing profit) untuk tempat wisata, mall, kafe, dan area komersial Anda tanpa biaya sewa alat.';
        $seo['keywords'] = 'kerjasama photobooth, sharing profit photobooth malang, bagi hasil bisnis photobooth, investasi photobooth';
    } elseif (str_starts_with($path, 'availability')) {
        $seo['title'] = 'Cek Jadwal & Ketersediaan Event - Photomate.id';
        $seo['description'] = 'Periksa ketersediaan jadwal kosong untuk tanggal dan slot acara Anda dengan sistem booking kalender online Photomate.id.';
        $seo['keywords'] = 'cek ketersediaan photobooth, kalender booking photobooth, sewa photobooth online';
    } elseif (str_starts_with($path, 'blog/')) {
        $segments = explode('/', $path);
        $blogId = end($segments);
        $article = \App\Models\Article::find($blogId);
        if ($article && $article->is_published) {
            $seo['title'] = $article->title . ' - Blog Photomate.id';
            $seo['description'] = $article->short_description ?? strip_tags(substr($article->body, 0, 160));
            $seo['image'] = $article->image ? asset('storage/' . $article->image) : asset('logophotomateblue.png');
            $seo['type'] = 'article';
            $seo['schema'] = [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $article->title,
                'description' => $seo['description'],
                'image' => $seo['image'],
                'datePublished' => $article->published_at ? \Carbon\Carbon::parse($article->published_at)->toIso8601String() : $article->created_at->toIso8601String(),
                'dateModified' => $article->updated_at->toIso8601String(),
                'author' => [
                    '@type' => 'Organization',
                    'name' => 'Photomate.id'
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => 'Photomate.id',
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => asset('logophotomateblue.png')
                    ]
                ]
            ];
        }
    } elseif ($path === 'blog') {
        $seo['title'] = 'Artikel, Tips & Inspirasi Seputar Event & Photobooth - Blog Photomate.id';
        $seo['description'] = 'Temukan tips menarik seputar persiapan pernikahan, souvenir unik, tren photobooth, dekorasi acara terbaru, dan kisah seru event bersama Photomate.id.';
        $seo['keywords'] = 'tips event malang, inspirasi photobooth, souvenir pernikahan unik, ide souvenir cetak foto';
    } elseif ($path === 'bio') {
        $seo['title'] = 'Photomate.id Link Bio - Kontak, Whatsapp, & Informasi Resmi';
        $seo['description'] = 'Hubungi tim Photomate.id. Dapatkan tautan WhatsApp resmi admin, portofolio event terbaru, galeri sosial media, dan informasi lengkap paket photobooth dalam satu halaman.';
        $seo['keywords'] = 'contact photomate, whatsapp photomate, link bio photomate';
    }

    // Default LocalBusiness Schema if not set by article
    if (!$seo['schema']) {
        $seo['schema'] = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Photomate.id',
            'image' => asset('logophotomateblue.png'),
            'description' => $seo['description'],
            'url' => asset('/'),
            'telephone' => '+62-877-8740-5280',
            'email' => 'captureyourmate@gmail.com',
            'priceRange' => 'Rp',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Jl. Sumber, RT02/04, Tulakan, Panggungrejo, Kec. Kepanjen',
                'addressLocality' => 'Kepanjen, Kabupaten Malang',
                'addressRegion' => 'Jawa Timur',
                'postalCode' => '65163',
                'addressCountry' => 'ID'
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => -8.144603581565857,
                'longitude' => 112.55624367500079
            ],
            'sameAs' => [
                'https://www.instagram.com/photomateid_/',
                'https://www.tiktok.com/@photomate_id'
            ]
        ];
    }

    return view('react.app', compact('cmsData', 'seo'));
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
