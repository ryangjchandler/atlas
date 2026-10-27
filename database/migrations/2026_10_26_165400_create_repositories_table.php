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
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_installation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('full_name');
            $table->string('default_branch')->nullable();
            $table->string('index_status', 20)->default('pending');
            $table->string('indexed_sha', 40)->nullable();
            $table->unsignedInteger('index_files_total')->nullable();
            $table->unsignedInteger('index_files_done')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->text('index_error')->nullable();
            $table->text('overview')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
