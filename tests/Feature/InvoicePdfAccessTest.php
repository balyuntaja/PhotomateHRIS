<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\DocumentSecurityTestHelpers;
use Tests\TestCase;

class InvoicePdfAccessTest extends TestCase
{
    use DocumentSecurityTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDocumentSecurityData();
    }

    public function test_guest_tanpa_signed_url_tidak_dapat_mengakses_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->get(route('invoice.pdf', $invoice))->assertForbidden();
    }

    public function test_user_tanpa_permission_tidak_dapat_mengakses_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $user = $this->makeKaryawan('K0002');

        $this->actingAs($user)
            ->get(route('invoice.pdf', $invoice))
            ->assertForbidden();
    }

    public function test_user_dengan_permission_view_invoice_dapat_mengakses_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $user = $this->makeKaryawan('K0003');
        $this->grantPermission($user, 'view_invoice');

        $this->assertPdfResponse(
            $this->actingAs($user)->get(route('invoice.pdf', $invoice))
        );
    }

    public function test_super_admin_dapat_mengakses_invoice(): void
    {
        $invoice = $this->makeInvoice();
        $user = $this->makeKaryawan('K0001', 'R01');

        $this->assertPdfResponse(
            $this->actingAs($user)->get(route('invoice.pdf', $invoice))
        );
    }

    public function test_guest_dapat_mengakses_invoice_melalui_temporary_signed_url(): void
    {
        $invoice = $this->makeInvoice();

        $url = URL::temporarySignedRoute(
            'invoice.pdf',
            now()->addMinutes(30),
            ['invoice' => $invoice->getKey()]
        );

        $this->assertPdfResponse($this->get($url));
    }

    public function test_signed_url_invoice_yang_kedaluwarsa_ditolak(): void
    {
        $invoice = $this->makeInvoice();

        $url = URL::temporarySignedRoute(
            'invoice.pdf',
            now()->subMinute(),
            ['invoice' => $invoice->getKey()]
        );

        $this->get($url)->assertForbidden();
    }

    public function test_signed_url_invoice_tidak_berlaku_untuk_invoice_lain(): void
    {
        $invoicePertama = $this->makeInvoice();
        $invoiceKedua = $this->makeInvoice();

        $url = URL::temporarySignedRoute(
            'invoice.pdf',
            now()->addMinutes(30),
            ['invoice' => $invoicePertama->getKey()]
        );

        $urlDimodifikasi = str_replace(
            '/invoice/' . $invoicePertama->getKey() . '/pdf',
            '/invoice/' . $invoiceKedua->getKey() . '/pdf',
            $url
        );

        $this->assertNotSame($url, $urlDimodifikasi);
        $this->get($urlDimodifikasi)->assertForbidden();
    }

    public function test_signed_url_invoice_dengan_signature_palsu_ditolak(): void
    {
        $invoice = $this->makeInvoice();

        $url = URL::temporarySignedRoute(
            'invoice.pdf',
            now()->addMinutes(30),
            ['invoice' => $invoice->getKey()]
        );

        $urlPalsu = preg_replace('/signature=[^&]+/', 'signature=palsu', $url);

        $this->assertNotSame($url, $urlPalsu);
        $this->get($urlPalsu)->assertForbidden();
    }
}
