// Service Worker for Photomate Queue Web Push Notifications

self.addEventListener("push", function (event) {
    if (!event.data) {
        console.warn("[Service Worker] Push event received with no data.");
        return;
    }

    try {
        const payload = event.data.json();
        console.log("[Service Worker] Push received:", payload);

        const title = payload.title || "Giliran Anda Tiba! 🎉";
        const options = {
            body: payload.body || "Nomor antrean Anda telah dipanggil.",
            icon: payload.icon || "/favicon.ico",
            badge: payload.badge || "/favicon.ico",
            tag: payload.tag || "queue-called",
            requireInteraction: true,
            vibrate: [500, 250, 500],
            data: payload.data || {}
        };

        event.waitUntil(
            self.registration.showNotification(title, options)
        );
    } catch (e) {
        console.error("[Service Worker] Failed to parse push payload:", e);
    }
});

self.addEventListener("notificationclick", function (event) {
    console.log("[Service Worker] Notification clicked:", event.notification.tag);
    event.notification.close();

    const targetUrl = event.notification.data ? event.notification.data.url : null;
    if (!targetUrl) return;

    event.waitUntil(
        clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (clientList) {
            // Find if there is an existing tab with the same queue page open
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                if (client.url.includes(targetUrl) && "focus" in client) {
                    return client.focus();
                }
            }
            
            // If not open, open a new window/tab
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});
