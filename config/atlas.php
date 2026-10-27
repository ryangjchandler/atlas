<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Repository Index
    |--------------------------------------------------------------------------
    |
    | Connected repositories are downloaded at their default branch's latest
    | commit, split into chunks, and stored for full-text search. Embeddings
    | are added to each chunk when an embeddings provider is configured.
    |
    */

    'index' => [
        'max_file_size' => 256 * 1024,
        'max_files' => 20_000,
        'chunk_lines' => 80,

        'embeddings' => [
            // Any provider in config/ai.php that supports embeddings, e.g. "openai" or "ollama". Null disables vectors.
            'provider' => env('ATLAS_EMBEDDINGS_PROVIDER'),
            'model' => env('ATLAS_EMBEDDINGS_MODEL'),
            // Fixed by the code_chunks migration; changing it needs a new migration.
            'dimensions' => 1536,
        ],
    ],

];
