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
        Schema::create('survey_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('locale'); // en, ar, fr...
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('welcome_message')->nullable();
            $table->text('completed_message')->nullable();
            $table->text('closed_message')->nullable();
            $table->text('limit_message')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'locale']); // prevent duplicate language
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_translations');
    }
};
