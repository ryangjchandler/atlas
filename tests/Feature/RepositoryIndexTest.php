<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchIndex;
use App\Enums\IndexStatus;
use App\Jobs\IndexRepository;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class RepositoryIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.driver' => 'token', 'services.github.token' => 'ghp_test']);
    }

    protected function connect(string $fullName): Repository
    {
        return Repository::create(['github_id' => crc32($fullName), 'full_name' => $fullName]);
    }

    protected string $sha = '';

    protected string $tarball = '';

    protected bool $faking = false;

    /**
     * Fake GitHub serving the given files as the tarball of commit $sha.
     *
     * @param  array<string, string>  $files
     */
    protected function fakeGitHub(string $sha, array $files): void
    {
        $directory = storage_path('framework/testing/'.Str::uuid());
        $root = "{$directory}/acme-billing-".substr($sha, 0, 7);

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname("{$root}/{$path}"));
            File::put("{$root}/{$path}", $content);
        }

        Process::path($directory)->run(['tar', '-czf', 'source.tar.gz', basename($root)])->throw();
        $this->tarball = File::get("{$directory}/source.tar.gz");
        $this->sha = $sha;
        File::deleteDirectory($directory);

        // Registered once: later fakes would only append stubs that never match before these...
        if (! $this->faking) {
            $this->faking = true;

            Http::fake([
                'api.github.com/repos/acme/billing' => Http::response(['default_branch' => 'main']),
                'api.github.com/repos/acme/billing/branches/main' => fn () => Http::response(['commit' => ['sha' => $this->sha]]),
                'api.github.com/repos/acme/billing/tarball/*' => fn ($request) => str_ends_with($request->url(), $this->sha)
                    ? Http::response($this->tarball)
                    : Http::response(['message' => 'Not Found'], 404),
            ]);
        }
    }

    public function test_repositories_are_indexed_incrementally_and_searchable(): void
    {
        $refunds = "<?php\n\nclass RefundService\n{\n".str_repeat("    // padding\n", 100)."\n    public function issueRefund(\$charge)\n    {\n        return \$charge->refund();\n    }\n}\n";
        $invoices = "<?php\n\nclass InvoiceMailer\n{\n    public function send() {}\n}\n";

        $this->fakeGitHub(str_repeat('a', 40), [
            'src/RefundService.php' => $refunds,
            'src/InvoiceMailer.php' => $invoices,
            'src/Legacy.php' => "<?php\n\nclass LegacyGateway {}\n",
            'vendor/stripe/Refund.php' => "<?php class VendoredRefund {}\n",
            'node_modules/lib/refund.js' => "function vendoredRefund() {}\n",
            'composer.lock' => '{"refund": true}',
            'public/logo.png' => "\x89PNG\0\0refund",
        ]);

        $repository = $this->connect('acme/billing');
        IndexRepository::dispatchSync($repository);
        $repository->refresh();

        $this->assertSame(IndexStatus::Indexed, $repository->index_status);
        $this->assertSame(str_repeat('a', 40), $repository->indexed_sha);
        $this->assertSame(
            ['src/InvoiceMailer.php', 'src/Legacy.php', 'src/RefundService.php'],
            $repository->chunks()->distinct()->orderBy('path')->pluck('path')->all(),
        );

        // A camelCase method is found by its words, with a permalink to the exact lines at the indexed commit...
        $output = (string) (new SearchIndex)->handle(new Request(['query' => 'issue refund']));
        $this->assertStringStartsWith('### acme/billing — src/RefundService.php', $output);
        $this->assertMatchesRegularExpression('#blob/a{40}/src/RefundService\.php\#L(\d+)-L(\d+)#', $output);
        preg_match('#\#L(\d+)-L(\d+)#', $output, $lines);
        $this->assertTrue($lines[1] <= 106 && $lines[2] >= 106, 'The cited lines must contain issueRefund (line 106).');

        $unchangedChunk = $repository->chunks()->where('path', 'src/InvoiceMailer.php')->value('id');

        // The next commit edits one file and deletes another...
        $this->fakeGitHub(str_repeat('b', 40), [
            'src/RefundService.php' => str_replace('issueRefund', 'createRefund', $refunds),
            'src/InvoiceMailer.php' => $invoices,
        ]);

        IndexRepository::dispatchSync($repository);
        $repository->refresh();

        $this->assertSame(str_repeat('b', 40), $repository->indexed_sha);
        $this->assertSame($unchangedChunk, $repository->chunks()->where('path', 'src/InvoiceMailer.php')->value('id'));
        $this->assertFalse($repository->chunks()->where('path', 'src/Legacy.php')->exists());
        $this->assertFalse($repository->chunks()->where('content', 'like', '%issueRefund%')->exists());
        $this->assertStringContainsString('src/RefundService.php', (string) (new SearchIndex)->handle(new Request(['query' => 'createRefund'])));

        // Each citation points at the commit its lines were read from: unchanged files keep their original commit...
        $this->assertStringContainsString('/blob/'.str_repeat('a', 40).'/src/InvoiceMailer.php#L', (string) (new SearchIndex)->handle(new Request(['query' => 'InvoiceMailer'])));
        $this->assertStringContainsString('/blob/'.str_repeat('b', 40).'/src/RefundService.php#L', (string) (new SearchIndex)->handle(new Request(['query' => 'createRefund'])));
        $this->assertNull($repository->index_files_total);
    }

    public function test_chunks_are_embedded_when_an_embeddings_provider_is_configured(): void
    {
        Embeddings::fake();
        config(['atlas.index.embeddings.provider' => 'openai']);

        $this->fakeGitHub(str_repeat('c', 40), ['src/Refunds.php' => "<?php\n\nclass Refunds {}\n"]);

        $repository = $this->connect('acme/billing');
        IndexRepository::dispatchSync($repository);

        $this->assertCount(1536, $repository->chunks()->sole()->embedding);

        // The query is embedded too and blended into the ranking...
        $this->assertSame(['src/Refunds.php'], (new SearchIndex)->search('refunds')->pluck('path')->all());
        Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('refunds'));
    }

    public function test_a_failed_first_index_is_reported(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $repository = $this->connect('acme/missing');

        try {
            IndexRepository::dispatchSync($repository);
        } catch (\Throwable) {
            // dispatchSync rethrows after the job's failed() hook has run...
        }

        $repository->refresh();
        $this->assertSame(IndexStatus::Failed, $repository->index_status);
        $this->assertStringContainsString('404', (string) $repository->index_error);
    }
}
