<?php

namespace App\Services\Indexing;

use App\Enums\IndexStatus;
use App\Models\Repository;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Indexes a repository's default branch at its latest commit, re-chunking only files whose content changed.
 *
 * Built for short-lived queue workers with little memory and disk: the tarball is streamed straight into tar,
 * which skips ignored paths, and files are chunked and written in batches. Vectors are added afterwards by
 * EmbedChunks jobs, so this job only does the fast full-text part.
 */
class RepositoryIndexer
{
    /**
     * Directories that hold dependencies, build output, or tooling rather than the product's source.
     */
    protected const IGNORED_DIRECTORIES = [
        '.git', 'node_modules', 'vendor', 'bower_components', 'dist', 'build', 'out', 'coverage',
        '.next', '.nuxt', '.turbo', '.cache', '__pycache__', '.venv', 'venv', 'target',
    ];

    protected const IGNORED_FILES = [
        'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'composer.lock', 'Cargo.lock',
        'Gemfile.lock', 'poetry.lock', 'go.sum', 'bun.lockb',
    ];

    protected const IGNORED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg', 'bmp', 'pdf', 'zip', 'gz', 'tar', 'jar',
        'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'mov', 'webm', 'wav', 'map', 'snap',
        'exe', 'dll', 'so', 'dylib', 'bin', 'phar', 'sqlite', 'db', 'pyc', 'class',
    ];

    /**
     * Rows inserted per statement; each row uses nine bindings.
     */
    protected const INSERT_BATCH = 200;

    /**
     * Files whose chunks are replaced together, which bounds the content held in memory at once.
     */
    protected const FILE_BATCH = 100;

    public function index(Repository $repository): void
    {
        $github = $repository->github();

        $branch = $github->get("/repos/{$repository->full_name}")->throw()->json('default_branch');
        $sha = $github->get("/repos/{$repository->full_name}/branches/{$branch}")->throw()->json('commit.sha');

        if ($sha === $repository->indexed_sha && $repository->index_status === IndexStatus::Indexed) {
            $repository->update(['default_branch' => $branch, 'indexed_at' => now()]);

            return;
        }

        // Only a new commit means work; until it is done, the previous index keeps serving searches...
        $repository->forceFill(['index_status' => IndexStatus::Indexing, 'index_files_total' => null, 'index_files_done' => null])->save();

        $directory = storage_path('app/private/indexing/'.Str::uuid());

        try {
            File::ensureDirectoryExists($directory);

            $tarball = $github->timeout(60)
                ->withOptions(['stream' => true])
                ->get("/repos/{$repository->full_name}/tarball/{$sha}")
                ->throw();

            $body = $tarball->toPsrResponse()->getBody();

            Process::timeout(60)
                ->input((function () use ($body) {
                    while (! $body->eof()) {
                        yield $body->read(64 * 1024);
                    }
                })())
                ->run(['tar', ...$this->excludes(), '-xzf', '-', '-C', $directory, '--strip-components=1'])
                ->throw();

            $files = $this->scan($directory);

            $this->sync($repository, $directory, $files, $sha);

            $repository->forceFill([
                'default_branch' => $branch,
                'indexed_sha' => $sha,
                'indexed_at' => now(),
                'index_status' => IndexStatus::Indexed,
                'index_error' => null,
                'index_files_total' => null,
                'index_files_done' => null,
                'overview' => $this->overview($directory, array_keys($files)),
            ])->save();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Keep ignored paths out of the extracted archive, so they never use the worker's disk.
     *
     * @return list<string>
     */
    protected function excludes(): array
    {
        return array_map(fn (string $pattern) => "--exclude={$pattern}", [
            ...self::IGNORED_DIRECTORIES,
            ...self::IGNORED_FILES,
            '*.min.*',
            ...array_map(fn (string $extension) => "*.{$extension}", self::IGNORED_EXTENSIONS),
        ]);
    }

    /**
     * Find the indexable text files under a directory, keyed by relative path, with their git blob SHAs.
     *
     * @return array<string, string>
     */
    protected function scan(string $root): array
    {
        // Skip ignored directories without descending into them, and never follow links out of the archive...
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file) => ! $file->isLink()
                && ! ($file->isDir() && in_array($file->getFilename(), self::IGNORED_DIRECTORIES, true)),
        ));

        $files = [];
        $maxSize = config('atlas.index.max_file_size');

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);

            if ($this->ignores($path) || $file->getSize() > $maxSize) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            if (str_contains(substr($content, 0, 8192), "\0") || ! mb_check_encoding($content, 'UTF-8') || $this->looksMinified($content)) {
                continue;
            }

            $files[$path] = sha1('blob '.strlen($content)."\0".$content);

            if (count($files) >= config('atlas.index.max_files')) {
                break;
            }
        }

        ksort($files);

        return $files;
    }

    protected function ignores(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, self::IGNORED_DIRECTORIES, true)) {
                return true;
            }
        }

        $name = basename($path);

        return in_array($name, self::IGNORED_FILES, true)
            || str_contains($name, '.min.')
            || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IGNORED_EXTENSIONS, true);
    }

    protected function looksMinified(string $content): bool
    {
        return strlen($content) > 5000 && substr_count($content, "\n") < strlen($content) / 1000;
    }

    /**
     * Replace the chunks of changed and deleted files, leaving unchanged files untouched.
     *
     * Each batch of files is swapped in its own transaction, so searches during a refresh see every file
     * at either its old or its new version.
     *
     * @param  array<string, string>  $files  Blob SHAs keyed by path.
     */
    protected function sync(Repository $repository, string $root, array $files, string $sha): void
    {
        /** @var array<string, string> $indexed */
        $indexed = $repository->chunks()->distinct()->pluck('blob_sha', 'path')->all();

        $changed = array_filter($files, fn (string $blob, string $path) => ($indexed[$path] ?? null) !== $blob, ARRAY_FILTER_USE_BOTH);
        $chunker = new Chunker(config('atlas.index.chunk_lines'));

        $repository->forceFill(['index_files_total' => count($changed), 'index_files_done' => 0])->save();

        foreach (array_chunk($changed, self::FILE_BATCH, preserve_keys: true) as $batch) {
            $rows = [];

            foreach ($batch as $path => $blob) {
                foreach ($chunker->chunk((string) file_get_contents("{$root}/{$path}")) as $chunk) {
                    $rows[] = [...$chunk, 'path' => (string) $path, 'blob_sha' => $blob, 'commit_sha' => $sha];
                }
            }

            DB::transaction(function () use ($repository, $batch, $rows) {
                $repository->chunks()->whereIn('path', array_map('strval', array_keys($batch)))->delete();

                foreach (array_chunk($rows, self::INSERT_BATCH) as $insert) {
                    $this->insert($repository, $insert);
                }
            });

            $repository->increment('index_files_done', count($batch));
        }

        foreach (array_chunk(array_keys(array_diff_key($indexed, $files)), 500) as $paths) {
            $repository->chunks()->whereIn('path', $paths)->delete();
        }
    }

    /**
     * @param  array<int, array{path: string, blob_sha: string, commit_sha: string, start_line: int, end_line: int, content: string}>  $rows
     */
    protected function insert(Repository $repository, array $rows): void
    {
        $values = [];
        $bindings = [];

        foreach ($rows as $row) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?, setweight(to_tsvector('simple', ?), 'A') || setweight(to_tsvector('english', ?), 'B'))";

            array_push(
                $bindings,
                $repository->id,
                $row['path'],
                $row['blob_sha'],
                $row['commit_sha'],
                $row['start_line'],
                $row['end_line'],
                $row['content'],
                SearchTerms::forDocument(str_replace(['/', '.', '-', '_'], ' ', $row['path'])),
                SearchTerms::forDocument($row['content']),
            );
        }

        DB::insert(
            'insert into code_chunks (repository_id, path, blob_sha, commit_sha, start_line, end_line, content, search) values '.implode(', ', $values),
            $bindings,
        );
    }

    /**
     * Summarise the repository's layout and README so the agent knows where things live before searching.
     *
     * @param  list<string>  $paths
     */
    protected function overview(string $root, array $paths): string
    {
        $directories = [];
        $extensions = [];

        foreach ($paths as $path) {
            $segments = explode('/', $path);
            $directory = count($segments) > 2 ? "{$segments[0]}/{$segments[1]}/" : (count($segments) > 1 ? "{$segments[0]}/" : null);

            if ($directory !== null) {
                $directories[$directory] = ($directories[$directory] ?? 0) + 1;
            }

            if ($extension = pathinfo($path, PATHINFO_EXTENSION)) {
                $extensions[$extension] = ($extensions[$extension] ?? 0) + 1;
            }
        }

        arsort($extensions);
        ksort($directories);

        $lines = ['Files indexed: '.count($paths)];
        $lines[] = 'Main file types: '.collect(array_slice($extensions, 0, 6, true))->map(fn ($count, $ext) => ".{$ext} ({$count})")->implode(', ');
        $lines[] = 'Directories (file counts): '.collect(array_slice($directories, 0, 60, true))->map(fn ($count, $dir) => "{$dir} ({$count})")->implode(', ');

        $readme = collect($paths)->first(fn (string $path) => preg_match('/^readme(\.\w+)?$/i', $path) === 1);

        if ($readme !== null) {
            $lines[] = "README excerpt:\n".Str::limit(trim((string) file_get_contents("{$root}/{$readme}")), 2000);
        }

        return implode("\n", $lines);
    }
}
