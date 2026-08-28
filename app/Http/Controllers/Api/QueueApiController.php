<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Event;
use App\Models\Customer;
use App\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class QueueApiController extends Controller
{
    /**
     * Get the current status of the queue.
     */
    public function getQueueStatus($event_code, Request $request)
    {
        $event = Event::where('event_code', $event_code)->first();

        if (!$event) {
            return ApiResponse::format(false, 404, 'Event tidak ditemukan.');
        }

        $nowServing = QueueEntry::where('event_id', $event->id)
            ->where('status', 'SERVING')
            ->orderBy('serving_at', 'asc')
            ->get()
            ->map(function ($entry) {
                return [
                    'queue_number' => $entry->queue_number,
                    'formatted_number' => $entry->formatted_number,
                    'device_id' => $entry->device_id,
                    'name' => $entry->customer->name,
                ];
            });

        $nowCalled = QueueEntry::where('event_id', $event->id)
            ->where('status', 'CALLED')
            ->orderBy('called_at', 'asc')
            ->get()
            ->map(function ($entry) {
                return [
                    'queue_number' => $entry->queue_number,
                    'formatted_number' => $entry->formatted_number,
                    'device_id' => $entry->device_id,
                    'name' => $entry->customer->name,
                ];
            });

        $nextInQueue = QueueEntry::where('event_id', $event->id)
            ->where('status', 'WAITING')
            ->orderBy('queue_number', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($entry) {
                return [
                    'queue_number' => $entry->queue_number,
                    'formatted_number' => $entry->formatted_number,
                    'name' => $entry->customer->name,
                ];
            });

        $stats = [
            'waiting' => QueueEntry::where('event_id', $event->id)->where('status', 'WAITING')->count(),
            'called' => QueueEntry::where('event_id', $event->id)->where('status', 'CALLED')->count(),
            'serving' => QueueEntry::where('event_id', $event->id)->where('status', 'SERVING')->count(),
            'completed' => QueueEntry::where('event_id', $event->id)->where('status', 'COMPLETED')->count(),
            'skipped' => QueueEntry::where('event_id', $event->id)->where('status', 'SKIPPED')->count(),
            'cancelled' => QueueEntry::where('event_id', $event->id)->where('status', 'CANCELLED')->count(),
        ];

        $myEntry = null;
        $token = $request->query('token');
        if ($token) {
            $entry = QueueEntry::where('event_id', $event->id)
                ->where('secure_token', $token)
                ->first();

            if ($entry) {
                $peopleAhead = 0;
                if ($entry->status === 'WAITING') {
                    $peopleAhead = QueueEntry::where('event_id', $event->id)
                        ->where('status', 'WAITING')
                        ->where('queue_number', '<', $entry->queue_number)
                        ->count();
                }

                $myEntry = [
                    'id' => $entry->id,
                    'queue_number' => $entry->queue_number,
                    'formatted_number' => $entry->formatted_number,
                    'status' => $entry->status,
                    'device_id' => $entry->device_id,
                    'people_ahead' => $peopleAhead,
                    'joined_at' => $entry->joined_at ? $entry->joined_at->toIso8601String() : null,
                ];
            }
        }

        $data = [
            'event' => [
                'name' => $event->name,
                'location' => $event->location,
                'date' => $event->date->format('Y-m-d'),
                'status' => $event->status,
            ],
            'now_serving' => $nowServing,
            'now_called' => $nowCalled,
            'next_in_queue' => $nextInQueue,
            'stats' => $stats,
            'my_entry' => $myEntry,
        ];

        return ApiResponse::format(true, 200, 'Status antrean berhasil diambil.', $data);
    }

    /**
     * Join the queue for an event.
     */
    public function joinQueue($event_code, Request $request)
    {
        $event = Event::where('event_code', $event_code)->first();

        if (!$event) {
            return ApiResponse::format(false, 404, 'Event tidak ditemukan.');
        }

        if ($event->status === 'DRAFT') {
            return ApiResponse::format(false, 400, 'Antrean untuk event ini belum dibuka.');
        }

        if ($event->status === 'CLOSED') {
            return ApiResponse::format(false, 400, 'Antrean untuk event ini sudah ditutup.');
        }

        if ($event->status === 'PAUSED') {
            return ApiResponse::format(false, 400, 'Antrean sementara waktu sedang ditangguhkan. Silakan coba sesaat lagi.');
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:100',
            'whatsapp' => 'required|string|min:8|max:20',
            'email' => 'required|email|max:150',
            'marketing_consent' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponse::format(false, 422, 'Data input tidak valid.', $validator->errors());
        }

        $whatsapp = Customer::normalizeWhatsapp($request->input('whatsapp'));
        $email = strtolower(trim($request->input('email')));
        $name = trim($request->input('name'));

        // Prevent abuse: basic length checks
        if (strlen($whatsapp) < 9 || strlen($whatsapp) > 15) {
            return ApiResponse::format(false, 422, 'Nomor WhatsApp tidak valid.');
        }

        try {
            $entry = DB::transaction(function () use ($event, $name, $whatsapp, $email, $request) {
                // Find or create customer
                // To prevent conflict, look up by whatsapp first, then by email
                $customer = Customer::where('whatsapp', $whatsapp)->first();

                if (!$customer) {
                    $customer = Customer::where('email', $email)->first();
                }

                if ($customer) {
                    // Update name or consent if needed
                    $customer->update([
                        'name' => $customer->name ?: $name,
                        'marketing_consent' => $request->boolean('marketing_consent', $customer->marketing_consent),
                    ]);
                } else {
                    $customer = Customer::create([
                        'name' => $name,
                        'whatsapp' => $whatsapp,
                        'email' => $email,
                        'marketing_consent' => $request->boolean('marketing_consent'),
                    ]);
                }

                // Check for double queue active entries in the same event
                $activeEntry = QueueEntry::where('event_id', $event->id)
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['WAITING', 'CALLED', 'SERVING'])
                    ->first();

                if ($activeEntry) {
                    throw new \Exception('DUPLICATE_QUEUE');
                }

                // Get next sequence number
                $maxNumber = QueueEntry::where('event_id', $event->id)
                    ->lockForUpdate()
                    ->max('queue_number');

                $queueNumber = $maxNumber ? $maxNumber + 1 : 1;
                $secureToken = Str::uuid()->toString();

                return QueueEntry::create([
                    'event_id' => $event->id,
                    'customer_id' => $customer->id,
                    'queue_number' => $queueNumber,
                    'status' => 'WAITING',
                    'secure_token' => $secureToken,
                    'joined_at' => now(),
                ]);
            });

            return ApiResponse::format(true, 201, 'Berhasil bergabung ke antrean.', [
                'secure_token' => $entry->secure_token,
                'queue_number' => $entry->queue_number,
                'formatted_number' => $entry->formatted_number,
                'status' => $entry->status,
            ]);

        } catch (\Exception $e) {
            if ($e->getMessage() === 'DUPLICATE_QUEUE') {
                $customer = Customer::where('whatsapp', $whatsapp)->first() ?? Customer::where('email', $email)->first();
                $activeEntry = QueueEntry::where('event_id', $event->id)
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', ['WAITING', 'CALLED', 'SERVING'])
                    ->first();

                return ApiResponse::format(false, 409, 'Anda sudah memiliki antrean aktif untuk event ini.', [
                    'secure_token' => $activeEntry->secure_token,
                    'queue_number' => $activeEntry->queue_number,
                    'formatted_number' => $activeEntry->formatted_number,
                    'status' => $activeEntry->status,
                ]);
            }

            return ApiResponse::format(false, 500, 'Gagal memproses pendaftaran antrean: ' . $e->getMessage());
        }
    }

    /**
     * Cancel an active queue entry.
     */
    public function cancelQueue($secure_token, Request $request)
    {
        $entry = QueueEntry::where('secure_token', $secure_token)->first();

        if (!$entry) {
            return ApiResponse::format(false, 404, 'Data antrean tidak ditemukan.');
        }

        if (!in_value($entry->status, ['WAITING', 'CALLED', 'SERVING'])) {
            return ApiResponse::format(false, 400, 'Antrean sudah tidak aktif atau selesai.');
        }

        $entry->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        return ApiResponse::format(true, 200, 'Antrean Anda berhasil dibatalkan.');
    }

    /**
     * Get display status for TV/screen.
     */
    public function getDisplayStatus($event_code)
    {
        $event = Event::where('event_code', $event_code)->first();

        if (!$event) {
            return ApiResponse::format(false, 404, 'Event tidak ditemukan.');
        }

        $nowServing = QueueEntry::where('event_id', $event->id)
            ->where('status', 'SERVING')
            ->orderBy('serving_at', 'asc')
            ->get()
            ->map(function ($entry) {
                return [
                    'formatted_number' => $entry->formatted_number,
                    'device_id' => $entry->device_id,
                ];
            });

        $nowCalled = QueueEntry::where('event_id', $event->id)
            ->where('status', 'CALLED')
            ->orderBy('called_at', 'asc')
            ->get()
            ->map(function ($entry) {
                return [
                    'formatted_number' => $entry->formatted_number,
                    'device_id' => $entry->device_id,
                ];
            });

        $nextInQueue = QueueEntry::where('event_id', $event->id)
            ->where('status', 'WAITING')
            ->orderBy('queue_number', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($entry) {
                return [
                    'formatted_number' => $entry->formatted_number,
                ];
            });

        $data = [
            'event' => [
                'name' => $event->name,
                'status' => $event->status,
            ],
            'now_serving' => $nowServing,
            'now_called' => $nowCalled,
            'next_in_queue' => $nextInQueue,
        ];

        return ApiResponse::format(true, 200, 'Data display antrean berhasil diambil.', $data);
    }
}

// Helper function for in_value (to handle PHP in_array checks)
if (!function_exists('in_value')) {
    function in_value($needle, $haystack) {
        return in_array($needle, $haystack);
    }
}
