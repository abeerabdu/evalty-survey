 <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('survey_templates', function (Blueprint $table) {
                $table->id();

                // Unique key used in code (VERY IMPORTANT)
                $table->string('key')->unique();
                // example: default_form, map_form

                // Display name
                $table->string('name');

                // Description (optional)
                $table->text('description')->nullable();

                // JSON configuration (dynamic behavior)
                $table->json('config')->nullable();

                // Icon (for UI)
                $table->string('icon')->nullable();

                // Preview image (for selection UI)
                $table->string('preview_image')->nullable();

                // Is active or not
                $table->boolean('is_active')->default(true);

                // Versioning (important for future updates)
                $table->string('version')->default('1.0');
                // Audit
                $table->timestamps();
                $table->softDeletes(); // optional but professional
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('survey_templates');
        }
    };
