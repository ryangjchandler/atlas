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
        Schema::ensureVectorExtensionExists();

        Schema::create('code_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->text('path');
            $table->string('blob_sha', 40);
            // The commit the chunk was read from, so citations stay exact while a refresh is part-way through.
            $table->string('commit_sha', 40);
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->text('content');
            $table->tsvector('search');
            $table->vector('embedding', dimensions: config('atlas.index.embeddings.dimensions'))->nullable()->index();

            $table->index(['repository_id', 'path']);
            $table->index('search', null, 'gin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_chunks');
    }
};
