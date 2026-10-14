<?php

namespace App\Filament\Resources\SessionReportResource\Pages;

use App\Filament\Resources\SessionReportResource;
use App\Models\FinancialTransaction;
use App\Models\LuminashImportBatch;
use App\Models\SessionReport;
use App\Services\LuminashImportService;
use App\Services\PricingService;
use App\Services\SessionWorkflowService;
use App\Utils\RupiahHelper;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use RuntimeException;

class CreateSessionReport extends CreateRecord implements HasTable
{
    use InteractsWithTable;
    use WithFileUploads;

    public const LUMINASH_PREVIEW_LIMIT = 300;

    public const LUMINASH_MAX_KB = 10240;

    protected static string $resource = SessionReportResource::class;

    protected static ?string $title = 'Buat Rekap Sesi Baru';

    protected static bool $canCreateAnother = false;

    protected static string $view = 'filament.resources.session-reports.pages.create-session-report';

    /**
     * File CSV yang baru dipilih (sebelum disimpan ke disk).
     *
     * @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile | null
     */
    public $luminashUpload = null;

    public ?string $luminashStoredPath = null;

    public ?string $luminashFileName = null;

    public ?int $luminashFileSize = null;

    /**
     * idle | selected | validated | invalid | imported
     */
    public string $luminashFileState = 'idle';

    public ?string $luminashErrorMessage = null;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $luminashImportResult = null;

    /**
     * @var array<int, array<string, mixed>> | null
     */
    public ?array $luminashPreviewRows = null;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $luminashPreviewSummary = null;

    public bool $luminashTruncated = false;

    public static function golioCabangId(): ?string
    {
        return LuminashImportService::golioCabangId();
    }

    public function isGolioSelected(): bool
    {
        $cabangId = $this->data['cabang_id'] ?? null;

        return filled($cabangId) && $cabangId === self::golioCabangId();
    }

    public function canImportLuminash(): bool
    {
        $user = Auth::user();

        return $user?->isSuperAdmin() || ($user?->can('create_keuangan_transaction') ?? false);
    }

    public function updatedLuminashUpload(): void
    {
        $upload = $this->luminashUpload;

        $this->resetLuminashFile();

        if (! $upload) {
            return;
        }

        $validator = Validator::make(
            ['luminashUpload' => $upload],
            ['luminashUpload' => ['required', 'file', 'mimes:csv,txt', 'max:' . self::LUMINASH_MAX_KB]],
            [
                'luminashUpload.required' => 'Pilih file CSV Luminash terlebih dahulu.',
                'luminashUpload.mimes' => 'File harus berformat CSV hasil export Luminash.',
                'luminashUpload.max' => 'Ukuran file maksimal ' . (self::LUMINASH_MAX_KB / 1024) . 'MB.',
            ],
        );

        if ($validator->fails()) {
            $this->luminashFileState = 'invalid';
            $this->luminashErrorMessage = $validator->errors()->first('luminashUpload');

            Notification::make()
                ->title('File CSV tidak dapat digunakan')
                ->body($this->luminashErrorMessage)
                ->danger()
                ->send();

            return;
        }

        $this->luminashFileName = $upload->getClientOriginalName();
        $this->luminashFileSize = $upload->getSize();
        $this->luminashStoredPath = $upload->storeAs('luminash-imports', Str::ulid() . '.csv', 'local');
        $this->luminashFileState = 'selected';
    }

    public function hapusFileLuminash(): void
    {
        $this->resetLuminashFile();

        Notification::make()
            ->title('File CSV dihapus')
            ->body('Hasil validasi dan preview sebelumnya ikut direset.')
            ->success()
            ->send();
    }

    public function luminashFileSizeLabel(): ?string
    {
        if ($this->luminashFileSize === null) {
            return null;
        }

        $kilobytes = $this->luminashFileSize / 1024;

        if ($kilobytes >= 1024) {
            return number_format($kilobytes / 1024, 2, ',', '.') . ' MB';
        }

        return number_format(max($kilobytes, 0.1), 1, ',', '.') . ' KB';
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function luminashStatus(): array
    {
        return match ($this->luminashFileState) {
            'validated' => ['Validasi berhasil', 'success'],
            'invalid' => ['Validasi gagal', 'danger'],
            'imported' => ['Impor berhasil', 'success'],
            'selected' => ['File dipilih — siap divalidasi', 'info'],
            default => ['Menunggu file', 'gray'],
        };
    }

    public function validasiLuminash(): void
    {
        Gate::authorize('create', FinancialTransaction::class);

        $this->resetLuminashPreview();
        $this->luminashErrorMessage = null;

        if (blank($this->luminashStoredPath)) {
            Notification::make()
                ->title('File CSV belum dipilih')
                ->body('Unggah file CSV Luminash terlebih dahulu.')
                ->warning()
                ->send();

            return;
        }

        try {
            $analysis = app(LuminashImportService::class)->analyzeFile($this->luminashStoredPath, 'local');
        } catch (RuntimeException $exception) {
            $this->luminashFileState = 'invalid';
            $this->luminashErrorMessage = $exception->getMessage();

            Notification::make()
                ->title('Validasi CSV gagal')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->luminashPreviewSummary = $analysis['summary'];
        $this->luminashPreviewRows = array_slice($analysis['rows'], 0, self::LUMINASH_PREVIEW_LIMIT);
        $this->luminashTruncated = count($analysis['rows']) > self::LUMINASH_PREVIEW_LIMIT;
        $this->luminashFileState = 'validated';

        Notification::make()
            ->title('Validasi selesai')
            ->body($analysis['summary']['imported'] . ' dari ' . $analysis['summary']['total_rows'] . ' baris siap diimpor.')
            ->success()
            ->send();
    }

    public function importLuminash(): void
    {
        Gate::authorize('create', FinancialTransaction::class);

        if ($this->luminashFileState !== 'validated' || $this->luminashPreviewSummary === null) {
            Notification::make()
                ->title('Validasi belum dijalankan')
                ->body('Jalankan "Validasi CSV" untuk file yang terpilih sebelum mengimpor.')
                ->warning()
                ->send();

            return;
        }

        if (($this->luminashPreviewSummary['imported'] ?? 0) < 1) {
            Notification::make()
                ->title('Tidak ada transaksi untuk diimpor')
                ->body('Semua baris pada file ini dikecualikan, duplikat, atau tidak valid.')
                ->warning()
                ->send();

            return;
        }

        if (blank($this->luminashStoredPath)) {
            Notification::make()
                ->title('Data impor belum lengkap')
                ->body('Unggah file CSV Luminash terlebih dahulu.')
                ->danger()
                ->send();

            return;
        }

        $user = Auth::user();
        $crewIds = array_values(array_filter((array) $this->data['crews'] ?? []));

        try {
            $batch = app(LuminashImportService::class)->import(
                $this->luminashStoredPath,
                'local',
                $user,
                $this->luminashFileName,
                $crewIds,
            );
        } catch (RuntimeException $exception) {
            $this->luminashErrorMessage = $exception->getMessage();

            Notification::make()
                ->title('Impor gagal')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->resetLuminashPreview();

        $this->luminashFileState = 'imported';
        $this->luminashImportResult = [
            'imported_count' => $batch->imported_count,
            'session_report_count' => $batch->session_report_count,
            'total_imported_amount' => $batch->total_imported_amount,
        ];

        Notification::make()
            ->title('Impor selesai')
            ->body(
                $batch->imported_count . ' transaksi keuangan dicatat dan ' . $batch->session_report_count
                . ' rekap sesi Golio dibuat dengan total ' . RupiahHelper::format($batch->total_imported_amount) . '.'
            )
            ->success()
            ->send();
    }

    protected function resetLuminashFile(): void
    {
        $this->luminashUpload = null;
        $this->luminashStoredPath = null;
        $this->luminashFileName = null;
        $this->luminashFileSize = null;
        $this->luminashFileState = 'idle';
        $this->luminashErrorMessage = null;
        $this->luminashImportResult = null;
        $this->resetLuminashPreview();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => LuminashImportBatch::query()->with(['cabang', 'importer'])->latest('id'))
            ->heading('Riwayat Impor Luminash')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu Impor')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (LuminashImportBatch $record): ?string => $record->file_name),

                Tables\Columns\TextColumn::make('importer.nama_lengkap')
                    ->label('Diimpor Oleh')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('period_label')
                    ->label('Periode Transaksi'),

                Tables\Columns\TextColumn::make('total_rows')
                    ->label('Baris')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('imported_count')
                    ->label('Berhasil')
                    ->color('success')
                    ->weight('semibold')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('session_report_count')
                    ->label('Rekap Sesi')
                    ->color('primary')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('excluded_count')
                    ->label('Dikecualikan')
                    ->getStateUsing(fn (LuminashImportBatch $record): int => $record->excluded_count)
                    ->color('warning')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('duplicate_count')
                    ->label('Duplikat')
                    ->color('gray')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('invalid_count')
                    ->label('Gagal')
                    ->color('danger')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_imported_amount')
                    ->label('Nominal Impor')
                    ->formatStateUsing(fn ($state): string => RupiahHelper::format($state))
                    ->alignEnd(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make('detail')
                    ->label('Detail')
                    ->modalHeading(fn (LuminashImportBatch $record): string => 'Detail Impor: ' . $record->file_name)
                    ->modalWidth('7xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (LuminashImportBatch $record) => view('filament.resources.session-reports.luminash-import-detail', [
                        'batch' => $record->load(['rows', 'importer', 'cabang']),
                    ])),
            ])
            ->emptyStateHeading('Belum ada riwayat impor')
            ->emptyStateDescription('Riwayat proses import transaksi Luminash akan tampil di sini.')
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->defaultPaginationPageOption(10);
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Kirim ke Supervisor')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (): bool => ! $this->isGolioSelected());
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['cabang_id'] ?? null) === self::golioCabangId()) {
            throw ValidationException::withMessages([
                'data.cabang_id' => 'Rekap sesi Golio dibuat otomatis melalui impor CSV Luminash, bukan lewat input manual.',
            ]);
        }

        $user = Auth::user();
        $reportDate = $data['report_date'] ?? null;

        if ($reportDate && app(SessionWorkflowService::class)->isInputClosed($user, $reportDate)) {
            throw ValidationException::withMessages([
                'data.report_date' => SessionReport::inputClosedMessageFor($reportDate),
            ]);
        }

        $data['status'] = SessionReport::STATUS_DRAFT;
        $data['submitted_by'] = Auth::user()?->karyawan_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var SessionReport $record */
        $record = $this->record;

        $user = Auth::user();
        if ($user) {
            app(SessionWorkflowService::class)->submit($record, $user);
        } else {
            app(PricingService::class)->recalculateReport($record);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Rekap Sesi Berhasil Dikirim')
            ->body('Rekap sesi telah diserahkan dan menunggu persetujuan (approval) Supervisor.')
            ->success();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function resetLuminashPreview(): void
    {
        $this->luminashPreviewRows = null;
        $this->luminashPreviewSummary = null;
        $this->luminashTruncated = false;
    }
}
