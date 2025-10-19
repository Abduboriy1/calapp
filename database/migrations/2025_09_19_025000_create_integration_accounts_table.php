
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                 // 'google'
            $table->string('provider_user_id');         // Google's sub or id
            $table->text('access_token');               // encrypted
            $table->text('refresh_token')->nullable();  // encrypted
            $table->timestamp('expires_at')->nullable();
            $table->string('scope')->nullable();
            $table->json('meta')->nullable();           // {email, name, picture, etc.}
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('integration_accounts');
    }
};
