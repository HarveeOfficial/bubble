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
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_key_id')->constrained()->onDelete('cascade');
            $table->string('student_name')->nullable();
            $table->string('student_id')->nullable();
            $table->string('image_path');
            $table->json('detected_answers')->nullable();
            $table->integer('score')->nullable();
            $table->integer('total_items')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
