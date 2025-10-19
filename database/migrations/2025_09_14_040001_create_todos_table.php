<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            // 1=Lowest (green) ... 5=Highest (red)
            $table->unsignedTinyInteger('urgency')->default(3);
            $table->enum('status', ['todo','in_progress','done'])->default('todo');
            $table->timestamp('due_at')->nullable();

            // Assignment + audit
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('todos');
    }
};