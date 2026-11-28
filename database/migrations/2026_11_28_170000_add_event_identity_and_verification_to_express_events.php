<?php

use App\Services\ExpressEventConsolidationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('express_events')) {
            Schema::table('express_events', function (Blueprint $table) {
                if (! Schema::hasColumn('express_events', 'event_id')) {
                    $table->unsignedBigInteger('event_id')
                        ->nullable()
                        ->after('operational_type')
                        ->index('express_events_event_id_index');

                    if (Schema::hasTable('events')) {
                        $table->foreign('event_id', 'express_events_event_id_fk')
                            ->references('id')
                            ->on('events')
                            ->nullOnDelete();
                    }
                }

                if (! Schema::hasColumn('express_events', 'operational_identity_key')) {
                    $table->string('operational_identity_key', 190)
                        ->nullable()
                        ->after('event_id')
                        ->index('express_events_identity_key_index');
                }

                if (! Schema::hasColumn('express_events', 'needs_admin_verification')) {
                    $table->boolean('needs_admin_verification')
                        ->default(false)
                        ->after('hpp_status')
                        ->index('express_events_needs_verification_index');
                }

                if (! Schema::hasColumn('express_events', 'verification_note')) {
                    $table->text('verification_note')
                        ->nullable()
                        ->after('needs_admin_verification');
                }
            });

            if (Schema::hasTable('express_event_recaps') && Schema::hasTable('session_reports')) {
                app(ExpressEventConsolidationService::class)->consolidateHistoricalEvents();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('express_events')) {
            Schema::table('express_events', function (Blueprint $table) {
                if (Schema::hasColumn('express_events', 'event_id')) {
                    if (Schema::hasTable('events')) {
                        $table->dropForeign('express_events_event_id_fk');
                    }
                    $table->dropIndex('express_events_event_id_index');
                    $table->dropColumn('event_id');
                }

                if (Schema::hasColumn('express_events', 'operational_identity_key')) {
                    $table->dropIndex('express_events_identity_key_index');
                    $table->dropColumn('operational_identity_key');
                }

                if (Schema::hasColumn('express_events', 'verification_note')) {
                    $table->dropColumn('verification_note');
                }

                if (Schema::hasColumn('express_events', 'needs_admin_verification')) {
                    $table->dropIndex('express_events_needs_verification_index');
                    $table->dropColumn('needs_admin_verification');
                }
            });
        }
    }
};
