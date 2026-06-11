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
        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->boolean('has_responded')->default(false);
            $table->boolean('accepted')->default(false);
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();       // close on specific date/time
            $table->timestamps();

            $table->unique(['survey_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_invitations');
    }
};
