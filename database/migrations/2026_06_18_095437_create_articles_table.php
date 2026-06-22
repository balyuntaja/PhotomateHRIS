<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id(); $table->string("title"); $table->string("slug")->unique(); $table->text("short_description")->nullable(); $table->longText("body")->nullable(); $table->string("image")->nullable(); $table->date("published_at")->nullable(); $table->boolean("is_published")->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
