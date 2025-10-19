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
        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('title');
        $table->text('description')->nullable();
        $table->boolean('all_day')->default(false);
        $table->dateTime('start');
        $table->dateTime('end')->nullable(); // if null and not all_day, you can set a default duration on FE
        $table->string('color', 20)->nullable(); // e.g. "#1677ff"
        $table->string('location')->nullable();
        $table->json('meta')->nullable(); // tags, attachments, etc.
        $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
