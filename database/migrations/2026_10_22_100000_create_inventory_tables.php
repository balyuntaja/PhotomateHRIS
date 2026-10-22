<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('kode_barang', 50);
            $table->string('nama_barang', 150);
            $table->string('kategori', 100)->nullable();
            $table->string('satuan', 20)->default('pcs');
            $table->unsignedInteger('min_stok_default')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 5)->nullable();
            $table->string('updated_by', 5)->nullable();
            $table->timestamps();

            $table->unique('kode_barang', 'inv_items_kode_unique');
            $table->index('nama_barang', 'inv_items_nama_index');
            $table->index('kategori', 'inv_items_kategori_index');
            $table->index('is_active', 'inv_items_active_index');

            $table->foreign('created_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
            $table->foreign('updated_by')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('cabang_id', 5);
            $table->unsignedInteger('jumlah')->default(0);
            $table->unsignedInteger('min_stok')->default(0);
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_item_id', 'cabang_id'], 'inv_stocks_item_cabang_unique');
            $table->index(['cabang_id', 'jumlah'], 'inv_stocks_cabang_jumlah_index');
            $table->index(['inventory_item_id', 'jumlah'], 'inv_stocks_item_jumlah_index');

            $table->foreign('cabang_id')->references('cabang_id')->on('cabang')->restrictOnDelete();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_transaksi', 40);
            $table->string('idempotency_key', 64)->nullable();
            $table->date('tanggal_transaksi');
            $table->string('jenis_transaksi', 20);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('cabang_id', 5);
            $table->unsignedInteger('jumlah');
            $table->unsignedInteger('stok_sebelum');
            $table->unsignedInteger('stok_sesudah');
            $table->text('alasan')->nullable();
            $table->string('user_id', 5)->nullable();
            $table->string('user_name', 150)->nullable();
            $table->timestamps();

            $table->unique('nomor_transaksi', 'inv_tx_nomor_unique');
            $table->unique('idempotency_key', 'inv_tx_idempotency_unique');
            $table->index(['tanggal_transaksi', 'jenis_transaksi'], 'inv_tx_tanggal_jenis_index');
            $table->index(['inventory_item_id', 'tanggal_transaksi'], 'inv_tx_item_tanggal_index');
            $table->index(['cabang_id', 'tanggal_transaksi'], 'inv_tx_cabang_tanggal_index');
            $table->index(['user_id', 'tanggal_transaksi'], 'inv_tx_user_tanggal_index');

            $table->foreign('cabang_id')->references('cabang_id')->on('cabang')->restrictOnDelete();
            $table->foreign('user_id')->references('karyawan_id')->on('karyawan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('inventory_items');
    }
};
