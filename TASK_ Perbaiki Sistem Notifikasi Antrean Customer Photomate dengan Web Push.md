# TASK: Perbaiki Sistem Notifikasi Antrean Customer Photomate dengan Web Push

Saya sedang mengembangkan sistem antrean digital untuk Photomate.

Saya sudah memiliki halaman customer queue berbasis React/TypeScript. Saat ini sistem menggunakan polling setiap 5 detik untuk mendapatkan status antrean. Ketika status customer berubah menjadi `CALLED`, frontend mencoba menjalankan:

- browser notification menggunakan `new Notification()`
- vibration menggunakan `navigator.vibrate()`
- audio menggunakan `AudioContext`

Namun pada HP customer, ketika customer sudah keluar dari browser, berpindah aplikasi, browser di-background, layar terkunci, atau kondisi tertentu lainnya, customer tidak mendapatkan:

- notifikasi
- getaran
- suara

Saya ingin memperbaiki arsitektur ini menggunakan **Web Push Notification + Service Worker**, tanpa menghilangkan sistem polling yang sudah ada.

---

# 1. KONDISI SISTEM SAAT INI

Frontend customer menggunakan React + TypeScript.

File yang sedang digunakan adalah:

`CustomerQueuePage.ts`

Kode saat ini sudah memiliki:

- event code
- queue token di localStorage
- API `/api/queue/{eventCode}`
- API `/api/queue/{eventCode}/join`
- polling setiap 5 detik
- status queue:
  - `WAITING`
  - `CALLED`
  - `SERVING`
  - `COMPLETED`
  - `SKIPPED`
  - `CANCELLED`
- `AudioContext`
- `Notification.requestPermission()`
- `navigator.vibrate()`
- `new Notification()`

Polling saat ini:

```ts
const interval = setInterval(() => {
  fetchQueueStatus(false);
}, 5000);
```

Saat status berubah menjadi `CALLED`, kode saat ini menjalankan:

```ts
if (currentStatus === "CALLED" && lastMyStatusRef.current !== "CALLED") {
    // audio
    // vibration
    // browser notification
}
```

Masalah utama adalah mekanisme tersebut hanya bergantung pada JavaScript halaman yang sedang berjalan.

---

# 2. TUJUAN SISTEM

Saya ingin sistem bekerja seperti ini:

```text
CUSTOMER
   │
   │ Scan / buka link antrean
   ▼
Join Queue
   │
   ├── Simpan queue token
   │
   └── Register Web Push Subscription
             │
             ▼
        Backend Database
             │
             │
             ▼
        Push Subscription
```

Kemudian:

```text
OPERATOR
   │
   │ Klik "Panggil A012"
   ▼
BACKEND
   │
   ├── Update status queue → CALLED
   │
   └── Kirim Web Push Notification
             │
             ▼
       Customer Device
             │
             ├── 🔔 Notification
             ├── 📳 Vibration
             └── 🔊 Notification Sound
```

Notifikasi harus tetap dapat diterima ketika customer:

- berpindah aplikasi
- membuka aplikasi lain
- browser berada di background
- layar terkunci

Untuk kondisi yang memang didukung oleh browser/OS.

---

# 3. JANGAN MEROMBAK SISTEM YANG SUDAH ADA

PENTING:

Jangan menghapus atau mengganti sistem queue yang sudah berjalan.

Pertahankan:

- polling 5 detik
- `fetchQueueStatus()`
- localStorage queue token
- status queue
- UI ticket
- form join queue
- cancel queue
- completed state
- existing AudioContext
- existing vibration
- existing browser notification

Web Push harus menjadi **tambahan / enhancement**, bukan pengganti total.

Arsitektur akhirnya:

```text
                  QUEUE SYSTEM
                       │
              ┌────────┴────────┐
              │                 │
          Polling             Web Push
              │                 │
              ▼                 ▼
      Update halaman       Background
      setiap 5 detik       notification
```

Polling tetap digunakan untuk update realtime status antrean.

Web Push digunakan terutama untuk notification ketika customer dipanggil.

---

# 4. IMPLEMENTASI WEB PUSH

Gunakan standar Web Push dengan:

- Service Worker
- Push API
- Notification API
- Push Subscription
- VAPID
- Backend push library yang sesuai dengan stack backend project

Backend project menggunakan Laravel.

Jika dependency Web Push belum tersedia, pilih library Laravel/PHP yang stabil dan kompatibel dengan versi Laravel/PHP project.

Jangan mengubah dependency yang sudah ada jika tidak diperlukan.

---

# 5. SERVICE WORKER

Buat atau update Service Worker untuk menangani push event.

Service Worker harus menangani:

```js
self.addEventListener("push", ...)
```

Ketika menerima push payload, tampilkan notification menggunakan:

```js
self.registration.showNotification(...)
```

Payload minimal harus dapat berisi:

```json
{
  "title": "Giliran Anda Tiba! 🎉",
  "body": "Nomor antrean Anda (A012) telah dipanggil. Silakan datang ke booth Photomate.",
  "icon": "/path/to/logo.png",
  "badge": "/path/to/badge.png",
  "tag": "queue-called-A012",
  "queue_number": "A012",
  "event_code": "xxxxx",
  "device_id": 1,
  "type": "QUEUE_CALLED"
}
```

Gunakan asset logo Photomate yang sudah tersedia jika memungkinkan.

Jangan hardcode path asset sebelum mengecek struktur project.

---

# 6. NOTIFICATION CLICK

Ketika customer menekan notification:

```text
🔔 Giliran Anda Tiba!
Nomor A012 telah dipanggil.
```

Service Worker harus membuka atau memfokuskan halaman antrean customer yang sesuai.

Jika halaman sudah terbuka:

- focus halaman tersebut

Jika belum terbuka:

- buka URL queue customer

Gunakan event:

```js
self.addEventListener("notificationclick", ...)
```

Jangan membuka duplicate tab jika tab antrean yang sama sudah tersedia.

---

# 7. FRONTEND: REGISTER SERVICE WORKER

Pada halaman customer, register Service Worker secara aman.

Contoh konsep:

```ts
if ("serviceWorker" in navigator) {
    const registration = await navigator.serviceWorker.register("/service-worker.js");
}
```

Namun jangan langsung copy contoh tersebut jika struktur project menggunakan nama/path Service Worker berbeda.

Cari terlebih dahulu:

- apakah project sudah memiliki service worker
- apakah sudah ada PWA configuration
- apakah ada Vite PWA plugin
- apakah ada manifest
- apakah ada service worker existing

Jika sudah ada, integrasikan ke sistem yang sudah ada daripada membuat service worker kedua yang konflik.

---

# 8. REQUEST NOTIFICATION PERMISSION

Saat customer join antrean, setelah user melakukan explicit user gesture, lakukan:

1. request notification permission
2. register service worker
3. create Push Subscription
4. kirim Push Subscription ke backend

PENTING:

Browser biasanya membutuhkan user gesture untuk permission.

Karena itu jangan meminta notification permission secara otomatis ketika halaman baru dibuka.

Gunakan flow existing:

```text
Customer klik "Bergabung ke Antrean"
        ↓
join queue berhasil
        ↓
request notification permission
        ↓
register service worker
        ↓
subscribe push
        ↓
save subscription ke backend
```

Jika browser sudah memiliki permission:

```text
Notification.permission === "granted"
```

langsung lanjut ke subscription.

Jika:

```text
Notification.permission === "denied"
```

jangan memaksa permission lagi.

Tampilkan UI yang jelas kepada customer agar mereka tahu notifikasi diblokir.

---

# 9. VAPID

Implementasikan VAPID.

Gunakan environment variables.

Contoh:

```env
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=
```

Jangan hardcode private key di frontend.

Yang boleh dikirim ke frontend hanya:

```text
VAPID_PUBLIC_KEY
```

Private key hanya berada di backend/server.

---

# 10. API UNTUK PUSH SUBSCRIPTION

Tambahkan endpoint backend untuk menyimpan subscription.

Contoh konsep:

```http
POST /api/queue/push-subscription
```

Payload:

```json
{
  "queue_entry_id": 123,
  "event_code": "ABC123",
  "subscription": {
    "endpoint": "...",
    "keys": {
      "p256dh": "...",
      "auth": "..."
    }
  }
}
```

Namun sebelum membuat endpoint, periksa struktur API existing dan ikuti:

- route convention
- controller convention
- authentication/token convention
- validation convention
- response format

Jangan membuat arsitektur API yang berbeda dari project existing.

---

# 11. DATABASE

Buat migration/table untuk menyimpan Push Subscription.

Struktur minimal:

```text
id
queue_entry_id nullable
event_id nullable
endpoint
p256dh
auth
created_at
updated_at
```

Jika project sudah memiliki tabel customer/user/device, gunakan relasi yang paling tepat.

Pastikan satu device/subscription tidak menghasilkan duplicate record yang tidak diperlukan.

Gunakan unique constraint pada endpoint jika sesuai.

Jangan menghapus subscription lama secara sembarangan karena satu customer/device dapat melakukan subscription ulang.

---

# 12. RELASI DENGAN QUEUE ENTRY

Push subscription harus dapat dikaitkan dengan customer/queue entry.

Contohnya:

```text
Queue Entry
    │
    └── Push Subscription
```

Ketika:

```text
Queue Entry A012
status = CALLED
```

backend harus tahu Push Subscription milik A012.

---

# 13. TRIGGER SAAT CUSTOMER DIPANGGIL

Cari di backend bagian yang menjalankan proses:

```text
WAITING → CALLED
```

Jangan membuat trigger baru yang terpisah jika sudah ada function/service untuk melakukan perubahan status.

Integrasikan Web Push tepat setelah status berhasil berubah menjadi `CALLED`.

Flow:

```text
Operator klik Panggil
        ↓
Backend update queue entry
        ↓
status = CALLED
        ↓
Cari Push Subscription
        ↓
Kirim Web Push
        ↓
Customer menerima notification
```

Pastikan notification **hanya dikirim ketika status benar-benar berubah menjadi `CALLED`**.

Jangan mengirim notification setiap polling.

---

# 14. PENTING: JANGAN TRIGGER DARI POLLING

Jangan melakukan:

```text
Frontend polling
      ↓
status CALLED
      ↓
frontend mengirim request notification
```

Jangan.

Yang benar:

```text
Operator
   ↓
Backend
   ↓
Status berubah CALLED
   ↓
Backend Push Notification
   ↓
Customer
```

Ini mencegah duplicate notification.

---

# 15. NOTIFICATION PAYLOAD

Gunakan payload yang informatif.

Contoh:

```json
{
  "title": "Giliran Anda Tiba! 🎉",
  "body": "Nomor antrean Anda (A012) telah dipanggil. Silakan datang ke booth Photomate.",
  "icon": "/assets/img/logophotomateblue.png",
  "badge": "/assets/img/logophotomateblue.png",
  "tag": "queue-called-123",
  "data": {
    "type": "QUEUE_CALLED",
    "queue_entry_id": 123,
    "queue_number": "A012",
    "event_code": "EVENT123",
    "device_id": 1,
    "url": "/queue/EVENT123"
  }
}
```

Sesuaikan URL berdasarkan routing project yang sebenarnya.

---

# 16. AUDIO

Pertahankan audio alert yang sekarang sebagai fallback ketika halaman customer sedang aktif.

Jadi:

```text
Web Push
   ↓
background / locked screen
```

dan:

```text
Polling
   ↓
status CALLED
   ↓
AudioContext
   ↓
sound jika halaman aktif
```

Jangan mengandalkan AudioContext untuk background notification.

Notification sound dalam kondisi background/lock screen harus mengikuti sistem notification OS/browser.

Jangan membuat hack autoplay audio yang melanggar browser policy.

---

# 17. VIBRATION

Pertahankan:

```ts
navigator.vibrate(...)
```

sebagai fallback ketika halaman aktif dan browser mendukungnya.

Namun jangan menganggap:

```ts
navigator.vibrate()
```

sebagai pengganti Web Push.

Untuk background/lock screen, vibration harus berasal dari native/browser notification behavior.

---

# 18. EXISTING `handleEnableNotifications()`

Refactor function ini jika diperlukan.

Saat ini function tersebut menangani:

- AudioContext unlock
- Notification permission

Ubahlah agar juga menangani:

- Service Worker registration
- Push Subscription
- backend registration

Tetapi tetap pertahankan AudioContext unlock.

Pisahkan logic menjadi function yang jelas, misalnya:

```ts
unlockAudio()
requestNotificationPermission()
registerServiceWorker()
subscribeToPush()
savePushSubscription()
```

Supaya lebih mudah di-maintain.

---

# 19. ERROR HANDLING

Sistem antrean tidak boleh gagal hanya karena Push Notification gagal.

Contoh:

```text
Join Queue
    │
    ├── Queue registration SUCCESS
    │
    └── Push subscription FAILED
             │
             ▼
       Queue tetap berhasil
```

Push notification adalah fitur tambahan.

Jangan sampai:

```text
Push gagal
    ↓
Customer dianggap gagal join antrean
```

Tidak boleh.

Log error dengan jelas di console/backend.

---

# 20. SUPPORT BROWSER

Implementasikan feature detection.

Sebelum menggunakan:

```ts
Notification
serviceWorker
PushManager
PushSubscription
```

cek availability.

Contoh konsep:

```ts
const supported =
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window;
```

Jika tidak support:

```text
Queue tetap berjalan normal.
Polling tetap berjalan.
Audio/vibration fallback tetap digunakan jika tersedia.
```

Jangan membuat halaman crash.

---

# 21. iOS / SAFARI

Perhatikan kompatibilitas iPhone/iOS.

Jangan berasumsi semua browser mendukung Web Push dengan cara yang sama.

Jika ada requirement seperti:

```text
iPhone harus Add to Home Screen terlebih dahulu
```

jelaskan dan implementasikan detection/instruction yang sesuai dengan kondisi browser/project.

Jika browser tidak mendukung Push API:

```text
Tampilkan fallback message.
```

Jangan memberikan false claim bahwa notifikasi pasti bekerja di semua device.

---

# 22. PWA

Periksa apakah project sudah memiliki:

- `manifest.json`
- Service Worker
- PWA configuration
- Vite PWA plugin atau library sejenis

Jika belum ada dan memang dibutuhkan untuk Web Push pada target browser tertentu, tambahkan konfigurasi PWA secara minimal.

Jangan membuat dua Service Worker yang saling konflik.

---

# 23. CUSTOMER UI

Pada halaman customer, buat status notification yang jelas.

Contoh:

### Saat belum aktif:

```text
🔔 Aktifkan notifikasi agar kami dapat memberi tahu
Anda ketika giliran tiba.
```

Button:

```text
Aktifkan Notifikasi
```

### Saat aktif:

```text
✓ Notifikasi aktif
```

### Saat denied:

```text
⚠️ Notifikasi diblokir

Aktifkan izin notifikasi melalui pengaturan browser
agar Anda tetap mendapatkan pemberitahuan saat dipanggil.
```

Jangan menggunakan wording yang menjanjikan:

> "HP pasti bergetar & berbunyi"

Karena behavior tetap bergantung pada OS/browser/settings/device.

---

# 24. PERMISSION FLOW YANG DIINGINKAN

Idealnya:

```text
Customer mengisi form
        ↓
Klik "Bergabung ke Antrean"
        ↓
Queue berhasil dibuat
        ↓
Notification permission
        ↓
Jika granted:
        ↓
Service Worker
        ↓
Push Subscription
        ↓
Save ke backend
        ↓
Customer melihat:
"✓ Notifikasi aktif"
```

Jika permission denied:

```text
Queue berhasil
        ↓
Notification denied
        ↓
Customer tetap masuk antrean
        ↓
Polling tetap aktif
        ↓
UI memberikan warning
```

---

# 25. DUPLICATE SUBSCRIPTION

Pastikan ketika customer:

- refresh page
- membuka ulang page
- join ulang
- subscription berubah

tidak membuat ribuan subscription duplicate.

Backend harus melakukan upsert berdasarkan endpoint atau identifier subscription yang sesuai.

---

# 26. INVALID / EXPIRED SUBSCRIPTION

Ketika Web Push mengembalikan error bahwa subscription sudah tidak valid/expired:

```text
hapus atau tandai subscription invalid
```

Jangan terus-menerus mencoba mengirim ke endpoint yang sudah mati.

Log error dengan aman.

---

# 27. SECURITY

PENTING:

Jangan expose:

```text
VAPID_PRIVATE_KEY
```

ke frontend.

Jangan expose credential backend.

Validasi:

- queue_entry_id
- event_code
- token
- subscription payload

Pastikan customer tidak dapat mendaftarkan subscription ke queue entry milik customer lain hanya dengan mengganti ID.

Gunakan secure queue token / authorization mechanism yang sudah ada.

---

# 28. TESTING

Setelah implementasi, lakukan testing minimal berikut.

### Test 1 — Desktop foreground

```text
Customer join A001
↓
Browser aktif
↓
Operator panggil A001
↓
Notification muncul
↓
Audio muncul
↓
Vibration jika browser support
```

### Test 2 — Browser background

```text
Customer join A002
↓
Buka tab lain
↓
Operator panggil A002
↓
Push notification muncul
```

### Test 3 — Mobile background

```text
Customer join A003
↓
Pindah ke WhatsApp
↓
Operator panggil A003
↓
Notification muncul
```

### Test 4 — Screen locked

```text
Customer join A004
↓
Lock screen
↓
Operator panggil A004
↓
Notification masuk jika device/browser mendukung
```

### Test 5 — Notification denied

```text
Customer deny notification
↓
Join queue tetap berhasil
↓
Polling tetap bekerja
↓
UI warning muncul
```

### Test 6 — Browser tidak support Push

```text
Push unsupported
↓
Queue tetap berjalan
↓
Tidak crash
↓
Polling tetap bekerja
```

### Test 7 — Refresh

```text
Customer join A005
↓
Refresh
↓
Push subscription tetap/terdaftar
↓
Operator panggil A005
↓
Notification tetap masuk
```

### Test 8 — Duplicate

```text
Refresh berkali-kali
↓
Pastikan subscription tidak duplicate berlebihan
```

---

# 29. DEBUGGING

Tambahkan logging yang membantu debugging.

Frontend:

```text
[Push] Service Worker registered
[Push] Notification permission: granted
[Push] Push subscription created
[Push] Push subscription saved
```

Backend:

```text
[Queue] Entry A012 status changed to CALLED
[Push] Sending notification to A012
[Push] Notification sent successfully
```

Jika gagal:

```text
[Push] Failed to send notification
```

Jangan log:

- VAPID private key
- auth secret
- sensitive customer information

---

# 30. ACCEPTANCE CRITERIA

Implementasi dianggap berhasil jika:

1. Customer dapat join queue seperti sebelumnya.
2. Polling 5 detik tetap berjalan.
3. Status queue tetap bekerja seperti sebelumnya.
4. Customer dapat memberikan permission notification.
5. Browser mendapatkan Push Subscription.
6. Push Subscription tersimpan di backend.
7. Ketika operator mengubah status customer menjadi `CALLED`, backend mengirim Web Push.
8. Customer menerima notification meskipun halaman berada di background, sejauh browser/OS mendukung.
9. Notification menampilkan nomor antrean customer.
10. Notification click membuka/focus halaman antrean.
11. Audio existing tetap bekerja ketika halaman aktif.
12. Vibration fallback tetap bekerja ketika didukung.
13. Notification failure tidak menggagalkan queue.
14. Browser yang tidak mendukung Push tidak menyebabkan error.
15. Tidak ada duplicate notification akibat polling.
16. Tidak ada duplicate subscription yang tidak diperlukan.
17. VAPID private key tidak pernah masuk frontend.
18. Tidak ada dua Service Worker yang konflik.
19. Existing UI dan queue flow tidak rusak.

---

# 31. CARA KERJA YANG SAYA INGINKAN DARI ANTIGRAVITY

Sebelum melakukan perubahan:

### STEP 1 — Audit project

Cari dan identifikasi:

- framework frontend
- build tool
- PWA setup
- existing Service Worker
- manifest
- backend Laravel version
- queue controller
- queue service
- queue model
- queue migration
- route API
- function yang mengubah status menjadi `CALLED`

Jangan langsung membuat file baru sebelum memahami struktur project.

### STEP 2 — Buat implementation plan

Tampilkan:

```text
Files to create
Files to modify
Database changes
Backend changes
Frontend changes
Service Worker changes
Environment variables
Dependencies
Testing plan
```

### STEP 3 — Implementasikan

Implementasi secara minimal dan mengikuti struktur project yang sudah ada.

### STEP 4 — Verify

Jalankan:

- type checking
- lint
- build
- backend test jika tersedia
- migration check
- route check

Pastikan tidak ada error.

### STEP 5 — Berikan summary

Setelah selesai, berikan:

```text
IMPLEMENTED
- ...
- ...

FILES CREATED
- ...

FILES MODIFIED
- ...

ENV VARIABLES
- ...

DEPENDENCIES
- ...

TEST RESULTS
- ...

KNOWN LIMITATIONS
- ...
```

---

# 32. HASIL AKHIR YANG SAYA INGINKAN

Saya ingin customer bisa melakukan:

```text
1. Scan QR
2. Isi nama + WhatsApp + email
3. Join antrean
4. Izinkan notifikasi
5. Mendapat nomor antrean
6. Bebas meninggalkan browser / membuka aplikasi lain
7. Operator memanggil nomor
8. HP customer mendapatkan notification
9. Customer membuka notification
10. Kembali ke halaman antrean
11. Datang ke booth Photomate
```

Target utama:

> **Jangan lagi bergantung sepenuhnya pada JavaScript halaman customer untuk mengetahui bahwa customer sudah dipanggil.**

Polling tetap dipertahankan sebagai mekanisme update UI, tetapi **Web Push harus menjadi mekanisme utama untuk notifikasi panggilan ketika customer berada di background.**

Gunakan kode `CustomerQueuePage.ts` yang sudah ada sebagai baseline dan lakukan perubahan secara aman, minimal, serta kompatibel dengan struktur project yang sudah ada.

Jangan menghapus fitur yang sudah berjalan hanya demi implementasi Web Push.