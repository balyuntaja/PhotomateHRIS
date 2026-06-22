# Panduan Penggunaan Quanta HRIS (User & Developer Guide)

Selamat datang di Panduan Penggunaan **Quanta HRIS**. Dokumen ini dirancang untuk membantu pengembang, administrator HRD, dan tim finansial memahami cara kerja, instalasi, konfigurasi, dan penggunaan sistem Quanta HRIS.

Sistem ini terdiri dari dua bagian utama:
1. **Backend API & Web CMS Admin Panel** (Repositori ini, menggunakan Laravel 12 & Filament PHP).
2. **Mobile Application** (Aplikasi karyawan, menggunakan Flutter).

---

## 1. Arsitektur & Teknologi Utama

- **Framework Utama:** Laravel 12
- **Panel Administrasi (CMS):** [Filament PHP v3](https://filamentphp.com)
- **Otentikasi Mobile API:** Laravel Sanctum (Token-based)
- **Basis Data:** MySQL / MariaDB
- **Pemrosesan PDF:** Dompdf / Barryvdh-dompdf
- **Integrasi Frontend:** Vite & TailwindCSS v4
- **Containerization:** Docker & Docker Compose

---

## 2. Instalasi & Setup Lokal

Ikuti langkah-langkah di bawah ini untuk menjalankan backend di komputer Anda:

### Langkah 1: Klon Repositori
```bash
git clone https://github.com/munovrizall/quanta-hris-laravel.git
cd quanta-hris-laravel
```

### Langkah 2: Salin & Konfigurasi Lingkungan (`.env`)
Salin file konfigurasi contoh dan buat file `.env` baru:
```bash
cp .env.example .env
```
Buka file `.env` dan konfigurasikan database Anda (misalnya menggunakan MySQL lokal):
```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=photomatehris
DB_USERNAME=root
DB_PASSWORD=your_password
```

### Langkah 3: Instal Dependensi PHP
Gunakan Composer untuk menginstal semua library PHP yang diperlukan:
```bash
composer install
```

### Langkah 4: Generate Application Key
```bash
php artisan key:generate
```

### Langkah 5: Migrasi Database & Seeding Data
Jalankan migrasi untuk membuat tabel-tabel database beserta data awal (master tax TER, cabang, jabatan, dan dummy karyawan):
```bash
php artisan migrate --seed
```
*Catatan: Seeder akan otomatis mengonfigurasi aturan pajak PPh 21 TER, jaminan sosial BPJS, serta akun-akun demo.*

### Langkah 6: Tautkan Storage (Sangat Penting untuk Foto Selfie Absensi)
Agar file foto selfie dan dokumen dapat diakses secara publik, buat symbolic link ke direktori public:
```bash
php artisan storage:link
```

### Langkah 7: Instal Dependensi Javascript & Jalankan Asset Compiler
Instal library frontend dan jalankan server kompilasi asset (Vite):
```bash
npm install
npm run dev
```

### Langkah 8: Jalankan Server Lokal Laravel
Jalankan server pengembangan lokal:
```bash
php artisan serve
```
Sekarang, Web CMS dapat diakses melalui browser di alamat [http://127.0.0.1:8000/admin](http://127.0.0.1:8000/admin).

---

## 3. Akun Akses Default (Demo)

Setelah menjalankan `php artisan migrate --seed`, Anda dapat masuk ke CMS Admin Panel menggunakan akun-akun berikut:

| Role / Jabatan | Email | Password | Kegunaan Utama |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin@smartcool.id` | `admin123` | Manajemen penuh seluruh sistem, master data, & hak akses. |
| **CEO** | `ceo@smartcool.id` | `ceo123` | Persetujuan akhir penggajian & melihat laporan keuangan global. |
| **Manager HRD** | `manager.hrd1@smartcool.id` | `managerhrd123` | Verifikasi absensi, pengelolaan karyawan, approval cuti/izin/lembur. |
| **Staff HRD** | `staff.hrd1@smartcool.id` | `staffhrd123` | Administrasi harian karyawan, input absensi manual, draft pengajuan. |
| **Manager Finance** | `manager.finance@smartcool.id` | `managerfinance123`| Pemrosesan payroll bulanan, pencetakan slip gaji, & laporan keuangan. |
| **Account Payment** | `account.payment11@smartcool.id`| `accountpayment123`| Pembayaran transfer gaji & pencatatan transaksi payroll. |
| **Employee (Karyawan)**| `rizal@smartcool.id` | `rizal123` | Akses data profil, unduh slip gaji individual (biasanya via Mobile App).|

---

## 4. Panduan Fitur & Cara Penggunaan (Web CMS)

Sistem Web CMS dikelompokkan ke dalam menu-menu di sidebar berdasarkan hak akses (role-based access control):

### A. Manajemen Master Data (Grup: Data Master)
1. **Perusahaan (`PerusahaanResource`):**
   - Menyimpan informasi entitas perusahaan (Nama, Email, Alamat, NPWP, Website).
   - Setiap karyawan wajib ditautkan ke satu entitas perusahaan.
2. **Cabang (`CabangResource`):**
   - Digunakan untuk mengatur lokasi kantor cabang.
   - **Geofencing Setup:** Isi kolom `latitude` dan `longitude` lokasi cabang secara tepat, serta tentukan `radius_geofencing` (dalam satuan meter, contoh: `50` untuk radius 50 meter dari titik koordinat). Hal ini menentukan batas wilayah aman agar karyawan bisa melakukan Clock-in/Clock-out di aplikasi mobile.
3. **Karyawan (`KaryawanResource`):**
   - Berisi biodata lengkap karyawan, alamat, nomor telepon.
   - **Akun & Otentikasi:** Mengatur email dan password karyawan untuk login ke aplikasi mobile.
   - **Informasi Kepegawaian:** Menetapkan cabang penugasan, role akses, golongan PTKP, status kerja (Tetap, Kontrak, dll), serta nilai **Gaji Pokok**.
   - **BPJS & Rekening:** Menyimpan nomor rekening bank (untuk transfer gaji) dan nomor kartu BPJS Kesehatan/Ketenagakerjaan.

### B. Kehadiran & Operasional (Grup: Absensi & Pengajuan)
1. **Absensi (`AbsensiResource`):**
   - Menampilkan rekapan kehadiran harian karyawan.
   - Status absensi dihitung otomatis oleh sistem:
     - **Tepat Waktu:** Clock-in sebelum/pada jam masuk yang ditentukan.
     - **Terlambat:** Clock-in melewati batas jam masuk operasional.
     - **Pulang Cepat:** Clock-out sebelum jam pulang operasional.
   - Menyimpan bukti foto selfie wajah karyawan dan titik koordinat asli saat melakukan absensi.
2. **Izin & Cuti (`IzinResource` & `CutiResource`):**
   - Karyawan mengajukan cuti (tahunan, melahirkan, dll) atau izin (sakit dengan surat dokter, keperluan mendesak) melalui aplikasi mobile.
   - HRD dapat melihat detail dokumen/bukti pendukung, lalu mengubah status pengajuan menjadi **Disetujui** atau **Ditolak**.
   - Status pengajuan ini otomatis memengaruhi perhitungan absensi dan potongan gaji jika izin bersifat tidak dibayar (unpaid leave).
3. **Lembur (`LemburResource`):**
   - Karyawan dapat mengajukan lembur jika bekerja melebihi jam operasional normal.
   - HRD melakukan verifikasi dan approval atas klaim lembur tersebut.
   - Sistem secara otomatis menghitung tarif insentif lembur per jam sesuai dengan peraturan perusahaan.

### C. Manajemen Payroll / Penggajian (Grup: Manajemen Penggajian)
Sistem penggajian Quanta HRIS mempermudah perhitungan gaji ribuan karyawan dalam hitungan detik dengan otomatisasi berikut:
1. **Pemrosesan Gaji (`PenggajianResource`):**
   - **Langkah Pembuatan:** Administrator/Finance masuk ke menu *Kelola Penggajian*, pilih tombol **Create**, lalu masukkan periode Bulan dan Tahun yang ingin diproses.
   - **Perhitungan Otomatis:** Sistem akan membaca data karyawan aktif, mengambil nilai Gaji Pokok, lalu melakukan kalkulasi secara realtime:
     - **Tunjangan:** Menghitung semua tunjangan melekat (tunjangan jabatan, makan, transportasi, dll).
     - **Lembur:** Menambahkan insentif dari akumulasi lembur yang telah disetujui.
     - **Potongan Lateness/Mangkir:** Mengurangi gaji secara proporsional jika karyawan datang terlambat atau absen tanpa keterangan (Alfa).
     - **Potongan BPJS:** Memotong iuran BPJS Kesehatan dan BPJS Ketenagakerjaan porsi karyawan.
     - **Potongan Pajak PPh 21 (Metode TER):** Menghitung potongan pajak bulanan secara otomatis berdasarkan akumulasi penghasilan bruto dan golongan PTKP menggunakan tabel TER terbaru.
   - **Alur Persetujuan (Workflow):**
     - Draft Penggajian dibuat oleh Staff Finance.
     - Diajukan ke Manager Finance untuk diverifikasi (**Diverifikasi**).
     - Disetujui oleh CEO/Direktur Utama (**Disetujui**). Setelah status disetujui, slip gaji akan dirilis dan dapat diakses oleh karyawan.
2. **Slip Gaji (`SlipGajiResource`):**
   - Menampilkan slip gaji periode yang sudah disetujui.
   - Menyediakan tombol **Cetak PDF** untuk mengunduh slip gaji secara bulk (seluruh karyawan dalam satu file PDF) atau individual per karyawan.

### D. Laporan (Grup: Laporan)
1. **Laporan Keuangan (`LaporanKeuanganResource`):**
   - Rekap pengeluaran biaya gaji total perusahaan per bulan, total potongan pajak, potongan BPJS, dan denda.
2. **Laporan Kinerja (`LaporanKinerjaResource`):**
   - Menganalisis produktivitas dan kepatuhan waktu kerja karyawan.
3. **Rekapitulasi Absensi (`RekapitulasiAbsensiResource`):**
   - Digunakan untuk mencetak rekap data kehadiran karyawan dalam bentuk file laporan PDF terstruktur.

---

## 5. Integrasi Mobile API (Untuk Developer)

Aplikasi Flutter berkomunikasi dengan Backend menggunakan Rest API dengan autentikasi Token Laravel Sanctum. Seluruh response API dibungkus menggunakan helper JSON format seragam `ApiResponse`.

Berikut adalah daftar endpoint API utama yang digunakan aplikasi mobile:

### A. Otentikasi & Profil Karyawan
- **Login:** `POST /api/login`
  - *Request Body:* `{"email": "email@domain.com", "password": "password"}`
  - *Response:* Mengembalikan token bearer Sanctum dan data profil karyawan.
- **Logout:** `POST /api/logout` (Memerlukan Bearer Token)
- **Get Profile:** `GET /api/profile` (Memerlukan Bearer Token)
- **Update Profile:** `POST /api/update-profile` (Memerlukan Bearer Token)
  - Untuk memperbarui biodata personal, nomor telepon, atau password baru.

### B. Presensi / Kehadiran
- **Clock In (Masuk):** `POST /api/attendance/clock-in`
  - *Request Body (Multipart Form-Data):*
    - `latitude` (float): Koordinat latitude perangkat.
    - `longitude` (float): Koordinat longitude perangkat.
    - `photo` (file): File foto selfie wajah.
  - *Sistem Validation:* Validasi geofencing cabang & deteksi Fake GPS.
- **Clock Out (Pulang):** `POST /api/attendance/clock-out`
  - *Request Body (Multipart Form-Data):*
    - `latitude` (float), `longitude` (float), `photo` (file).
- **Status Hari Ini:** `GET /api/attendance/status`
  - Mengetahui apakah karyawan hari ini sudah clock-in atau clock-out.
- **Riwayat Absensi:** `GET /api/attendance/history`
  - Menampilkan daftar riwayat kehadiran karyawan dalam rentang waktu tertentu.

### C. Pengajuan Izin, Cuti & Lembur
- **Pengajuan Cuti:**
  - `GET /api/cuti` (Melihat riwayat cuti karyawan).
  - `POST /api/cuti` (Mengajukan cuti baru).
- **Pengajuan Izin:**
  - `GET /api/izin` (Melihat riwayat izin).
  - `POST /api/izin` (Mengajukan izin baru dengan melampirkan file dokumen pendukung).
- **Pengajuan Lembur:**
  - `GET /api/lembur` (Melihat daftar lembur).
  - `POST /api/lembur` (Mengajukan lembur beserta detail jam dan alasan).

### D. Slip Gaji & Notifikasi
- **List Slip Gaji:** `GET /api/slip-gaji`
  - Menampilkan daftar slip gaji bulanan karyawan yang telah dirilis.
- **Detail Slip Gaji:** `GET /api/slip-gaji/{tahun}/{bulan}`
  - Melihat rincian komponen gaji (gaji pokok, lembur, pajak, BPJS, dll).
- **Unduh Slip Gaji PDF:** `GET /api/slip-gaji/{tahun}/{bulan}/download`
  - Mengunduh file slip gaji format PDF.
- **Notifikasi:** `GET /api/notifications` & `POST /api/notifications/read-all`
  - Mengelola notifikasi persetujuan pengajuan dan perilisan gaji.

---

## 6. Aturan Kalkulasi Pajak PPh 21 TER (Tarif Efektif Rata-rata)

Sistem ini mematuhi Peraturan Pemerintah Nomor 58 Tahun 2023 tentang tarif pemotongan PPh Pasal 21.

1. **Kategori TER ditentukan berdasarkan Status PTKP:**
   - **Kategori A:** PTKP TK/0, TK/1, K/0.
   - **Kategori B:** PTKP TK/2, TK/3, K/1, K/2.
   - **Kategori C:** PTKP K/3.
2. **Kalkulasi Bulanan:**
   - Sistem mengambil nilai total Penghasilan Bruto (Gaji Pokok + Tunjangan Teratur + Lembur).
   - Sistem mencocokkan nilai tersebut dengan batas bawah dan batas atas di tabel `TarifTer` sesuai Kategori TER karyawan.
   - Potongan PPh 21 = Penghasilan Bruto $\times$ Tarif TER.

---

## 7. Cara Deploy ke Produksi (Menggunakan Docker)

Untuk mendeploy Quanta HRIS ke server VPS menggunakan Docker:

1. **Jalankan Docker Compose:**
   ```bash
   docker-compose up -d --build
   ```
2. **Jalankan Perintah Inisialisasi di Container:**
   ```bash
   docker compose exec app composer install --optimize-autoloader --no-dev
   docker compose exec app php artisan migrate --force
   docker compose exec app php artisan config:cache
   docker compose exec app php artisan route:cache
   docker compose exec app php artisan view:cache
   ```
3. **Atur Hak Akses Direktori:**
   ```bash
   docker compose exec app chmod -R 775 storage bootstrap/cache
   ```
4. **Perbarui `.env` Produksi:**
   ```ini
   APP_URL=https://hris.perusahaananda.com
   ASSET_URL=https://hris.perusahaananda.com
   FILESYSTEM_DISK=public
   ```

---

*Jika Anda menemui kendala atau memiliki pertanyaan seputar konfigurasi lebih lanjut, silakan hubungi tim administrator IT.*
