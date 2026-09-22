<?php

namespace App\Console\Commands;

use App\Notifications\TestEmailNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendTestEmail extends Command
{
    protected $signature = 'mail:test
                            {email : Alamat email tujuan pengujian}
                            {--sync : Kirim langsung tanpa queue untuk melihat error Resend secara real-time}
                            {--note= : Catatan tambahan yang akan disertakan pada isi email}';

    protected $description = 'Kirim email uji untuk memverifikasi konfigurasi Resend dan database queue';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Alamat email tidak valid: {$email}");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $resendKey = config('services.resend.key');

        $this->table(['Konfigurasi', 'Nilai'], [
            ['MAIL_MAILER', $mailer],
            ['MAIL_FROM_ADDRESS', (string) config('mail.from.address')],
            ['MAIL_FROM_NAME', (string) config('mail.from.name')],
            ['QUEUE_CONNECTION', (string) config('queue.default')],
            ['RESEND_API_KEY', filled($resendKey) ? 'tersedia' : 'BELUM DISET'],
        ]);

        if ($mailer !== 'resend') {
            $this->warn("MAIL_MAILER saat ini \"{$mailer}\", bukan \"resend\". Email tidak akan dikirim melalui Resend.");
        }

        if (blank($resendKey)) {
            $this->error('RESEND_API_KEY belum diisi pada file .env. Pengiriman dibatalkan.');

            return self::FAILURE;
        }

        $notification = new TestEmailNotification($this->option('note'));
        $route = Notification::route('mail', $email);

        if ($this->option('sync')) {
            $this->line('Mengirim email secara langsung (sinkron)...');

            try {
                $route->notifyNow($notification);
            } catch (\Throwable $e) {
                $this->error('Pengiriman gagal: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->info("Email uji berhasil dikirim ke {$email} melalui Resend (sinkron).");

            return self::SUCCESS;
        }

        if (config('queue.default') === 'sync') {
            $this->warn('QUEUE_CONNECTION bernilai "sync", email akan dikirim langsung tanpa melewati queue.');
        }

        $route->notify($notification);

        $this->info("Email uji telah dimasukkan ke queue untuk {$email}.");
        $this->line('Jalankan "php artisan queue:work" untuk memprosesnya.');
        $this->line('Cek kegagalan dengan "php artisan queue:failed" dan ulangi dengan "php artisan queue:retry all".');

        return self::SUCCESS;
    }
}
