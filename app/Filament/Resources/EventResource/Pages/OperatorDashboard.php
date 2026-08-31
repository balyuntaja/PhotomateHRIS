<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Models\Event;
use App\Models\QueueEntry;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class OperatorDashboard extends Page
{
    protected static string $resource = EventResource::class;

    protected static string $view = 'filament.resources.event-resource.pages.operator-dashboard';

    public Event $record;

    public function mount($record): void
    {
        $this->record = $record instanceof Event ? $record : Event::findOrFail($record);
    }

    public function getHeading(): string
    {
        return 'Operator Dashboard - ' . $this->record->name;
    }

    public function changeEventStatus(string $status): void
    {
        if (in_array($status, ['DRAFT', 'OPEN', 'PAUSED', 'CLOSED'])) {
            $this->record->update(['status' => $status]);
            $this->notification()
                ->title('Status Antrean Diubah')
                ->body("Status antrean kini diatur menjadi: {$status}")
                ->success()
                ->send();
        }
    }

    public function callNext(int $deviceId): void
    {
        // Concurrency safety check: check if this device is already serving or calling someone
        $alreadyActive = QueueEntry::where('event_id', $this->record->id)
            ->where('device_id', $deviceId)
            ->whereIn('status', ['CALLED', 'SERVING'])
            ->exists();

        if ($alreadyActive) {
            $this->notification()
                ->title('Device Sedang Sibuk')
                ->body("Device {$deviceId} sedang melayani antrean aktif. Selesaikan antrean tersebut terlebih dahulu.")
                ->warning()
                ->send();
            return;
        }

        $calledEntry = DB::transaction(function () use ($deviceId) {
            $next = QueueEntry::where('event_id', $this->record->id)
                ->where('status', 'WAITING')
                ->orderBy('queue_number', 'asc')
                ->lockForUpdate()
                ->first();

            if ($next) {
                $next->update([
                    'status' => 'CALLED',
                    'device_id' => $deviceId,
                    'called_at' => now(),
                ]);
                return $next;
            }
            return null;
        });

        if ($calledEntry) {
            $this->notification()
                ->title('Antrean Dipanggil')
                ->body("Nomor {$calledEntry->formatted_number} dipanggil ke Device {$deviceId}.")
                ->success()
                ->send();

            // Trigger Web Push Notification
            try {
                \App\Services\WebPushService::sendNotificationToEntry(
                    $calledEntry,
                    'Giliran Anda Tiba! 🎉',
                    "Nomor antrean Anda ({$calledEntry->formatted_number}) telah dipanggil. Silakan datang ke Device {$deviceId}."
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("[Push] Failed trigger in OperatorDashboard: " . $e->getMessage());
            }
        } else {
            $this->notification()
                ->title('Tidak Ada Antrean')
                ->body('Daftar tunggu kosong.')
                ->info()
                ->send();
        }
    }

    public function startServing(int $entryId): void
    {
        $entry = QueueEntry::findOrFail($entryId);
        $entry->update([
            'status' => 'SERVING',
            'serving_at' => now(),
        ]);

        $this->notification()
            ->title('Mulai Melayani')
            ->body("Nomor {$entry->formatted_number} sedang difoto.")
            ->success()
            ->send();
    }

    public function completeServing(int $entryId): void
    {
        $entry = QueueEntry::findOrFail($entryId);
        $entry->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);

        $this->notification()
            ->title('Antrean Selesai')
            ->body("Nomor {$entry->formatted_number} selesai berfoto.")
            ->success()
            ->send();
    }

    public function skipEntry(int $entryId): void
    {
        $entry = QueueEntry::findOrFail($entryId);
        $entry->update([
            'status' => 'SKIPPED',
            'skipped_at' => now(),
        ]);

        $this->notification()
            ->title('Antrean Dilewati')
            ->body("Nomor {$entry->formatted_number} ditandai sebagai dilewati.")
            ->danger()
            ->send();
    }

    public function cancelEntry(int $entryId): void
    {
        $entry = QueueEntry::findOrFail($entryId);
        $entry->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        $this->notification()
            ->title('Antrean Dibatalkan')
            ->body("Nomor {$entry->formatted_number} telah dibatalkan.")
            ->gray()
            ->send();
    }

    protected function getViewData(): array
    {
        // Reload record to catch status updates from other users/events
        $this->record->refresh();

        $nowServing = QueueEntry::where('event_id', $this->record->id)
            ->where('status', 'SERVING')
            ->orderBy('serving_at', 'asc')
            ->get();

        $nowCalled = QueueEntry::where('event_id', $this->record->id)
            ->where('status', 'CALLED')
            ->orderBy('called_at', 'asc')
            ->get();

        $waitingList = QueueEntry::where('event_id', $this->record->id)
            ->where('status', 'WAITING')
            ->orderBy('queue_number', 'asc')
            ->get();

        $completedList = QueueEntry::where('event_id', $this->record->id)
            ->where('status', 'COMPLETED')
            ->orderBy('completed_at', 'desc')
            ->limit(10)
            ->get();

        $skippedList = QueueEntry::where('event_id', $this->record->id)
            ->where('status', 'SKIPPED')
            ->orderBy('skipped_at', 'desc')
            ->limit(10)
            ->get();

        $stats = [
            'waiting' => QueueEntry::where('event_id', $this->record->id)->where('status', 'WAITING')->count(),
            'called' => QueueEntry::where('event_id', $this->record->id)->where('status', 'CALLED')->count(),
            'serving' => QueueEntry::where('event_id', $this->record->id)->where('status', 'SERVING')->count(),
            'completed' => QueueEntry::where('event_id', $this->record->id)->where('status', 'COMPLETED')->count(),
            'skipped' => QueueEntry::where('event_id', $this->record->id)->where('status', 'SKIPPED')->count(),
            'cancelled' => QueueEntry::where('event_id', $this->record->id)->where('status', 'CANCELLED')->count(),
        ];

        return [
            'nowServing' => $nowServing,
            'nowCalled' => $nowCalled,
            'waitingList' => $waitingList,
            'completedList' => $completedList,
            'skippedList' => $skippedList,
            'stats' => $stats,
        ];
    }

    protected function notification(): \Filament\Notifications\Notification
    {
        return \Filament\Notifications\Notification::make();
    }
}
