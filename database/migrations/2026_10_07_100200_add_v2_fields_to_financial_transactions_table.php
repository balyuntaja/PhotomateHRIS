<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('category_id')
                ->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('party_id')->nullable()->after('account_id')
                ->constrained('financial_parties')->nullOnDelete()
                ->comment('Pihak yang membayar (pengeluaran) atau menerima (pemasukan)');
            $table->string('payment_status', 20)->default('lunas')->after('payment_method_id')
                ->comment('lunas, utang');
            $table->unsignedTinyInteger('period_month')->nullable()->after('transaction_date')
                ->comment('Bulan periode laporan yang dipilih user, terpisah dari transaction_date');
            $table->unsignedSmallInteger('period_year')->nullable()->after('period_month');
            $table->date('due_date')->nullable()->after('period_year')
                ->comment('Jatuh tempo, diisi untuk transaksi berstatus utang');
            $table->string('counterparty', 150)->nullable()->after('description')
                ->comment('Pihak/kepada siapa transaksi utang tersebut');

            $table->index('payment_status');
            $table->index(['period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['period_year', 'period_month']);
            $table->dropConstrainedForeignId('party_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn([
                'payment_status',
                'period_month',
                'period_year',
                'due_date',
                'counterparty',
            ]);
        });
    }
};
