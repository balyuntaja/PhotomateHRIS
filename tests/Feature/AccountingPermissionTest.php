<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryAccountMappingResource;
use App\Filament\Resources\ChartOfAccountResource;
use App\Filament\Resources\JournalEntryResource;
use App\Models\CategoryAccountMapping;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccountingTestHelpers;
use Tests\TestCase;

class AccountingPermissionTest extends TestCase
{
    use AccountingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccountingData();
    }

    public function test_permission_baru_akuntansi_tersedia(): void
    {
        foreach ([
            'view_any_chart_of_account',
            'create_chart_of_account',
            'view_any_category_account_mapping',
            'create_category_account_mapping',
            'view_any_journal_entry',
            'post_journal_entry',
            'reverse_journal_entry',
        ] as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_user_tanpa_permission_ditolak(): void
    {
        $user = $this->makeKaryawan('K0001');
        $draft = $this->makeDraftJournal();

        $this->assertTrue(Gate::forUser($user)->denies('viewAny', ChartOfAccount::class));
        $this->assertTrue(Gate::forUser($user)->denies('viewAny', CategoryAccountMapping::class));
        $this->assertTrue(Gate::forUser($user)->denies('viewAny', JournalEntry::class));
        $this->assertTrue(Gate::forUser($user)->denies('post', $draft));

        $this->actingAs($user)
            ->get(ChartOfAccountResource::getUrl())
            ->assertForbidden();

        $this->actingAs($user)
            ->get(CategoryAccountMappingResource::getUrl())
            ->assertForbidden();

        $this->actingAs($user)
            ->get(JournalEntryResource::getUrl())
            ->assertForbidden();
    }

    public function test_user_dengan_permission_dapat_melihat_dan_memposting(): void
    {
        $user = $this->makeKaryawan('K0001');

        foreach ([
            'view_any_chart_of_account',
            'view_any_category_account_mapping',
            'view_any_journal_entry',
            'post_journal_entry',
            'reverse_journal_entry',
        ] as $permission) {
            $this->grantPermission($user, $permission);
        }

        $draft = $this->makeDraftJournal();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', ChartOfAccount::class));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CategoryAccountMapping::class));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', JournalEntry::class));
        $this->assertTrue(Gate::forUser($user)->allows('post', $draft));
        $this->assertTrue(Gate::forUser($user)->denies('reverse', $draft));

        $draft = app(JournalPostingService::class)->post($draft);

        $this->assertTrue(Gate::forUser($user)->allows('reverse', $draft));

        $this->actingAs($user)
            ->get(ChartOfAccountResource::getUrl())
            ->assertOk();

        $this->actingAs($user)
            ->get(CategoryAccountMappingResource::getUrl())
            ->assertOk();

        $this->actingAs($user)
            ->get(JournalEntryResource::getUrl())
            ->assertOk();
    }

    public function test_admin_super_admin_tetap_memiliki_akses_fondasi_akuntansi(): void
    {
        $admin = $this->makeKaryawan('K0001', 'R01');
        $draft = $this->makeDraftJournal();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', ChartOfAccount::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', CategoryAccountMapping::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', JournalEntry::class));
        $this->assertTrue(Gate::forUser($admin)->allows('post', $draft));
    }

    private function makeDraftJournal(): JournalEntry
    {
        return app(JournalPostingService::class)->createDraft([
            'journal_date' => '2026-10-12',
            'description' => 'Draft permission test',
        ], [
            ['chart_of_account_id' => $this->accountingAccount('1001')->getKey(), 'debit' => 100000, 'credit' => 0],
            ['chart_of_account_id' => $this->accountingAccount('4000')->getKey(), 'debit' => 0, 'credit' => 100000],
        ]);
    }
}
