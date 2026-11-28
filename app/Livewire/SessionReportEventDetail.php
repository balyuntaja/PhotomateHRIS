<?php

namespace App\Livewire;

use App\Models\Karyawan;
use App\Models\SessionReport;
use App\Services\SessionWorkflowService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SessionReportEventDetail extends Component implements HasForms
{
    use InteractsWithForms;

    public int $reportId;

    public bool $isEditing = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(int $reportId): void
    {
        $this->reportId = $reportId;
        $this->fillFormFromReport();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('event_note')
                    ->label('Nama Event')
                    ->placeholder('Contoh: Event Gathering Telkomsel, Wedding Budi & Ani')
                    ->maxLength(255)
                    ->rules(['nullable', 'string', 'max:255']),

                Forms\Components\DatePicker::make('event_date')
                    ->label('Tanggal Event')
                    ->placeholder('Pilih Tanggal Event...')
                    ->format('Y-m-d')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->rules(['nullable', 'date']),
            ])
            ->statePath('data')
            ->columns([
                'default' => 1,
                'sm' => 2,
            ]);
    }

    public function openEdit(): void
    {
        $report = SessionReport::with(['cabang', 'invoice'])->findOrFail($this->reportId);

        if (! $this->authorizeEventEdit($report, 'Hanya Super Admin yang dapat mengedit detail event.')) {
            return;
        }

        $this->resetErrorBag();
        $this->fillFormFromReport($report);
        $this->isEditing = true;
    }

    public function cancelEdit(): void
    {
        $this->resetErrorBag();
        $this->fillFormFromReport();
        $this->isEditing = false;
    }

    public function save(): void
    {
        /** @var Karyawan|null $user */
        $user = Auth::user();
        $report = SessionReport::with(['cabang', 'invoice'])->findOrFail($this->reportId);

        if (! $this->authorizeEventEdit($report, 'Hanya Super Admin yang dapat menyimpan detail event.') || ! $user instanceof Karyawan) {
            return;
        }

        $state = $this->form->getState();

        try {
            $updated = app(SessionWorkflowService::class)->updateEventDetail(
                $report,
                $user,
                $state['event_note'] ?? null,
                $state['event_date'] ?? null
            );

            $this->isEditing = false;
            $this->fillFormFromReport($updated);

            Notification::make()
                ->title('Detail event berhasil diperbarui.')
                ->body('Informasi Nama Event dan Tanggal Event telah disimpan.')
                ->success()
                ->send();

            $this->dispatch('$refresh');
            $this->dispatch('session-report-updated');
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal memperbarui detail event.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function authorizeEventEdit(SessionReport $report, string $superAdminMessage): bool
    {
        if (! $report->supportsEventDetail()) {
            $this->isEditing = false;

            Notification::make()
                ->title('Fitur tidak tersedia')
                ->body('Detail event hanya tersedia untuk cabang Express.')
                ->danger()
                ->send();

            return false;
        }

        $user = Auth::user();

        if (! $user instanceof Karyawan || ! $user->can('updateEventDetail', $report)) {
            $this->isEditing = false;

            Notification::make()
                ->title('Akses ditolak')
                ->body($superAdminMessage)
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    protected function canEdit(?SessionReport $report = null): bool
    {
        $user = Auth::user();
        if (! $user instanceof Karyawan) {
            return false;
        }

        $report ??= SessionReport::with('cabang')->find($this->reportId);
        if (! $report || ! $report->supportsEventDetail()) {
            return false;
        }

        return $user->can('updateEventDetail', $report);
    }

    protected function fillFormFromReport(?SessionReport $report = null): void
    {
        $report ??= SessionReport::with(['cabang', 'invoice'])->find($this->reportId);

        if (! $report || ! $report->supportsEventDetail()) {
            $this->form->fill([]);

            return;
        }

        $this->form->fill([
            'event_note' => filled($report->event_note)
                ? $report->event_note
                : $report->invoice?->event_name,
            'event_date' => $report->event_date?->format('Y-m-d'),
        ]);
    }

    public function render()
    {
        $report = SessionReport::with(['cabang', 'invoice'])->find($this->reportId);
        $supportsEventDetail = $report?->supportsEventDetail() ?? false;

        return view('livewire.session-report-event-detail', [
            'report' => $report,
            'supportsEventDetail' => $supportsEventDetail,
            'canEdit' => $supportsEventDetail && $report ? $this->canEdit($report) : false,
        ]);
    }
}
