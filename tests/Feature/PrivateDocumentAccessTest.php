<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentSecurityTestHelpers;
use Tests\TestCase;

class PrivateDocumentAccessTest extends TestCase
{
    use DocumentSecurityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentSecurityData();
    }

    public function test_guest_tidak_dapat_mengakses_dokumen_privat(): void
    {
        $loginUrl = route('filament.admin.auth.login');

        $uris = [
            '/slip-gaji/cetak/2026/10',
            '/slip-gaji/cetak-individual/K0002/2026/10',
            '/laporan-keuangan/cetak/2026/10',
            '/laporan-kinerja/cetak/2026/10',
            '/rekapitulasi-absensi/cetak',
        ];

        foreach ($uris as $uri) {
            $this->get($uri)->assertRedirect($loginUrl);
        }

        $this->get('/keuangan/laporan/cetak')->assertForbidden();
    }

    public function test_user_tanpa_permission_ditolak_untuk_dokumen_privat(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->actingAs($user);

        $uris = [
            '/slip-gaji/cetak/2026/10',
            '/slip-gaji/cetak-individual/K0002/2026/10',
            '/laporan-keuangan/cetak/2026/10',
            '/laporan-kinerja/cetak/2026/10',
            '/rekapitulasi-absensi/cetak',
            '/keuangan/laporan/cetak',
        ];

        foreach ($uris as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_karyawan_dapat_mengakses_slip_gajinya_sendiri(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->makePenggajian('K0001');

        $this->assertPdfResponse(
            $this->actingAs($user)->get('/slip-gaji/cetak-individual/K0001/2026/10')
        );
    }

    public function test_karyawan_tidak_dapat_mengakses_slip_gaji_karyawan_lain(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->makeKaryawan('K0002');
        $this->makePenggajian('K0002');

        $this->actingAs($user)
            ->get('/slip-gaji/cetak-individual/K0002/2026/10')
            ->assertForbidden();
    }

    public function test_user_dengan_permission_penggajian_dapat_mengakses_slip_semua_dan_slip_karyawan_lain(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->makeKaryawan('K0002');
        $this->grantPermission($user, 'view_any_penggajian');

        $this->makePenggajian('K0001');
        $this->makePenggajian('K0002');

        $this->actingAs($user);

        $this->assertPdfResponse($this->get('/slip-gaji/cetak/2026/10'));
        $this->assertPdfResponse($this->get('/slip-gaji/cetak-individual/K0002/2026/10'));
    }

    public function test_user_dengan_permission_laporan_keuangan_dapat_mengakses_laporan_keuangan(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->grantPermission($user, 'view_laporan_keuangan');
        $this->makePenggajian('K0001');

        $this->assertPdfResponse(
            $this->actingAs($user)->get('/laporan-keuangan/cetak/2026/10')
        );
    }

    public function test_user_dengan_permission_laporan_kinerja_dapat_mengakses_laporan_kinerja(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->grantPermission($user, 'view_laporan_kinerja');

        $this->assertPdfResponse(
            $this->actingAs($user)->get('/laporan-kinerja/cetak/2026/10')
        );
    }
}
