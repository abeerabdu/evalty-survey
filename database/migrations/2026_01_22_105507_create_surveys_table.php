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
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('short_slug', 16)->nullable();

            $table->string('default_lang', 20)->default('en');

            // $table->string('title');
            // $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->enum('status', ['draft', 'published', 'closed'])->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamp('close_at')->nullable();       // close on specific date/time
            $table->integer('response_limit')->nullable();      // close after X responses
            $table->text('closed_message')->nullable();

            $table->timestamp('open_at')->nullable();       // close on specific date/time
            $table->enum('access_type', ['public', 'private'])->default('public');

            $table->string('access_token')->nullable();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('domain', 1024)->nullable()->after('access_type');

            $table->foreignId('duplicated_from_id')->nullable();
            $table->foreignId('template_id')
                ->nullable()
                ->constrained('survey_templates')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
