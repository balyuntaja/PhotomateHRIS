<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DocumentSecurityTestHelpers;
use Tests\TestCase;

class SlipGajiApiAccessTest extends TestCase
{
    use DocumentSecurityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentSecurityData();
    }

    public function test_guest_tidak_dapat_mengakses_api_slip_gaji(): void
    {
        $this->getJson('/api/slip-gaji')->assertUnauthorized();
        $this->getJson('/api/slip-gaji/2026/10')->assertUnauthorized();
        $this->getJson('/api/slip-gaji/2026/10/download')->assertUnauthorized();
    }

    public function test_api_slip_gaji_hanya_mengembalikan_data_milik_user_login(): void
    {
        $user = $this->makeKaryawan('K0001');
        $this->makeKaryawan('K0002');

        $milikSendiri = $this->makePenggajian('K0001');
        $this->makePenggajian('K0002');

        Sanctum::actingAs($user);

        $this->getJson('/api/slip-gaji')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.penggajian_id', $milikSendiri->penggajian_id);

        $this->getJson('/api/slip-gaji/2026/10')
            ->assertOk()
            ->assertJsonPath('data.karyawan_id', 'K0001')
            ->assertJsonPath('data.detail_id', $milikSendiri->penggajian_id);
    }
}
