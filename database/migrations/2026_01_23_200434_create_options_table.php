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
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')
                ->nullable()
                ->constrained('options')
                ->nullOnDelete();
            $table->string('label');
            $table->string('value')->nullable();
            $table->text('value_text')->nullable();
            $table->integer('value_int')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();





            $table->unique(['question_id', 'value'], 'options_question_id_value_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
