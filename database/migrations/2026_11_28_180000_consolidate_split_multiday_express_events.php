<?php

use App\Services\ExpressEventConsolidationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('express_events') && Schema::hasTable('express_event_recaps')) {
            try {
                app(ExpressEventConsolidationService::class)->consolidateHistoricalEvents();
            } catch (\Throwable) {
                // Dapat dijalankan ulang melalui `php artisan hpp:consolidate-express-events` atau tombol Sinkronkan Rekap Baru
            }
        }
    }

    public function down(): void
    {
        // Konsolidasi data bersifat non-destruktif terhadap SessionReport dan Invoice
    }
};
