<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\QueueEntry;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Illuminate\Support\Facades\Log;

class WebPushService
{
    /**
     * Send web push notification to all subscriptions associated with a queue entry.
     */
    public static function sendNotificationToEntry(QueueEntry $entry, string $title, string $body, array $extraData = []): void
    {
        $subscriptions = PushSubscription::where('queue_entry_id', $entry->id)->get();

        if ($subscriptions->isEmpty()) {
            Log::info("[Push] No subscription found for Queue Entry ID: {$entry->id}");
            return;
        }

        $vapidPublic = env('VAPID_PUBLIC_KEY');
        $vapidPrivate = env('VAPID_PRIVATE_KEY');
        $vapidSubject = env('VAPID_SUBJECT', 'mailto:admin@photomate.id');

        if (!$vapidPublic || !$vapidPrivate) {
            Log::warning('[Push] VAPID keys not configured in .env. Skipping push notification.');
            return;
        }

        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        try {
            $webPush = new WebPush($auth);
            
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => asset('favicon.ico'),
                'badge' => asset('favicon.ico'),
                'tag' => 'queue-called-' . $entry->id,
                'data' => array_merge([
                    'type' => 'QUEUE_CALLED',
                    'queue_entry_id' => $entry->id,
                    'queue_number' => $entry->queue_number,
                    'formatted_number' => $entry->formatted_number,
                    'event_code' => $entry->event->event_code ?? '',
                    'device_id' => $entry->device_id,
                    'url' => url('/queue/' . ($entry->event->event_code ?? '')),
                ], $extraData)
            ]);

            Log::info("[Push] Dispatching web push to {$subscriptions->count()} subscriptions for Entry ID: {$entry->id}");

            foreach ($subscriptions as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->p256dh,
                        'auth' => $sub->auth,
                    ],
                ]);

                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                if ($report->isSuccess()) {
                    Log::info("[Push] Notification sent successfully to: {$endpoint}");
                } else {
                    Log::warning("[Push] Failed to send notification to: {$endpoint}. Error: {$report->getReason()}");
                    
                    // If subscription has expired or is invalid (404/410), delete it
                    if ($report->isSubscriptionExpired()) {
                        Log::info("[Push] Subscription expired, deleting from database: {$endpoint}");
                        PushSubscription::where('endpoint_sha256', hash('sha256', $endpoint))->delete();
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("[Push] WebPushService error: " . $e->getMessage());
        }
    }
}
