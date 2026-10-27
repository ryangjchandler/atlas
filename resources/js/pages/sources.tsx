import { Form, Head, Link, usePoll, usePage } from '@inertiajs/react';
import { FolderGit2, Github, Plug, RefreshCw, Trash2 } from 'lucide-react';
import { sync as syncGitHub } from '@/routes/github';
import {
    connect as connectMcp,
    disconnect as disconnectMcp,
} from '@/routes/mcp-servers/oauth';
import McpServerController from '@/actions/App/Http/Controllers/McpServerController';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useEffect, useState } from 'react';
import { sources } from '@/routes';
import type { GitHubAccount, McpServer, Repository } from '@/types';

type Props = {
    github: {
        driver: 'token' | 'app';
        configured: boolean;
        installUrl: string | null;
    };
    accounts: GitHubAccount[];
    repositories: Repository[];
    embeddings: boolean;
    mcpServers: McpServer[];
};

function Progress({
    label,
    done,
    total,
}: {
    label: string;
    done: number | null;
    total: number | null;
}) {
    const known = total !== null && done !== null && total > 0;

    return (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <Badge variant="secondary" className="gap-1.5">
                <Spinner className="size-3" /> {label}
            </Badge>
            {known && (
                <>
                    <span
                        role="progressbar"
                        aria-label={label}
                        aria-valuemin={0}
                        aria-valuemax={total}
                        aria-valuenow={done}
                        className="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            className="block h-full rounded-full bg-primary transition-[width]"
                            style={{ width: `${(done / total) * 100}%` }}
                        />
                    </span>
                    <span className="tabular-nums">
                        {done.toLocaleString()} of {total.toLocaleString()}
                    </span>
                </>
            )}
        </span>
    );
}

function isIndexing(repository: Repository) {
    return (
        repository.index_status === 'pending' ||
        repository.index_status === 'indexing'
    );
}

function isEmbedding(repository: Repository, embeddings: boolean) {
    return (
        embeddings &&
        repository.index_status === 'indexed' &&
        repository.embedded_chunks_count < repository.chunks_count
    );
}

function IndexStatus({
    repository,
    embeddings,
}: {
    repository: Repository;
    embeddings: boolean;
}) {
    if (repository.index_status === 'failed') {
        return (
            <Badge variant="destructive" title={repository.index_error ?? ''}>
                Index failed
            </Badge>
        );
    }

    const indexed = repository.indexed_sha !== null && (
        <span
            className="text-xs text-muted-foreground"
            title={repository.index_error ?? undefined}
        >
            {repository.chunks_count.toLocaleString()} chunks ·{' '}
            <a
                href={`https://github.com/${repository.full_name}/commit/${repository.indexed_sha}`}
                target="_blank"
                rel="noreferrer"
                className="font-mono hover:underline"
            >
                {repository.default_branch}@
                {repository.indexed_sha?.slice(0, 7)}
            </a>
            {repository.indexed_at &&
                ` · checked ${new Date(repository.indexed_at).toLocaleString()}`}
            {repository.index_error && ' · last refresh failed'}
        </span>
    );

    if (isIndexing(repository)) {
        return (
            <div className="space-y-1">
                <Progress
                    label={
                        repository.index_files_total === null
                            ? 'Downloading'
                            : repository.indexed_sha === null
                              ? 'Indexing files'
                              : 'Updating changed files'
                    }
                    done={repository.index_files_done}
                    total={repository.index_files_total}
                />
                {/* The previous index keeps answering questions until the new commit is in... */}
                {indexed}
            </div>
        );
    }

    if (isEmbedding(repository, embeddings)) {
        return (
            <div className="space-y-1">
                <Progress
                    label="Embedding chunks"
                    done={repository.embedded_chunks_count}
                    total={repository.chunks_count}
                />
                {indexed}
            </div>
        );
    }

    return indexed;
}

function RemoveButton({
    action,
    label,
}: {
    action: ReturnType<typeof RepositoryController.destroy.form>;
    label: string;
}) {
    return (
        <Form {...action} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    variant="ghost"
                    size="icon"
                    disabled={processing}
                    aria-label={label}
                >
                    <Trash2 className="size-4" />
                </Button>
            )}
        </Form>
    );
}

export default function Sources({
    repositories,
    embeddings,
    mcpServers,
    github,
    accounts,
}: Props) {
    const isAdmin = usePage().props.auth.user.role === 'admin';
    const [filter, setFilter] = useState('');
    const [auth, setAuth] = useState<McpServer['auth']>('oauth');

    const indexing = repositories.some(isIndexing);
    const working =
        indexing ||
        repositories.some((repository) => isEmbedding(repository, embeddings));

    // Refresh index statuses while a repository is being indexed or embedded...
    const { start, stop } = usePoll(
        3000,
        { only: ['repositories'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (working) {
            start();
        } else {
            stop();
        }
    }, [working, start, stop]);

    return (
        <>
            <Head title="Sources" />

            <div className="flex max-w-3xl flex-1 flex-col gap-10 p-4 md:p-6">
                <Heading
                    title="Sources"
                    description={
                        isAdmin
                            ? 'Everything Atlas can read. Every chat can use every source connected here.'
                            : 'Everything Atlas can read. An admin manages these; connect your own account on OAuth servers so Atlas can use them for you.'
                    }
                />

                <section className="space-y-4">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            variant="small"
                            title="GitHub repositories"
                            description={
                                isAdmin
                                    ? `Atlas reads repositories through ${github.driver === 'app' ? 'its GitHub App' : 'a GitHub access token'}. Choose which ones Atlas uses; each is indexed for fast search and refreshed every 15 minutes.`
                                    : 'Repositories Atlas searches. Each is indexed for fast search and refreshed every 15 minutes.'
                            }
                        />
                        {isAdmin && github.configured && (
                            <div className="flex shrink-0 gap-2">
                                <Form
                                    {...syncGitHub.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            <RefreshCw /> Refresh access
                                        </Button>
                                    )}
                                </Form>
                                {github.installUrl && (
                                    <Button size="sm" asChild>
                                        <a href={github.installUrl}>
                                            <Github /> Install app
                                        </a>
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    {!isAdmin && (
                        <ul className="divide-y rounded-lg border">
                            {repositories.map((repository) => (
                                <li
                                    key={repository.id}
                                    className="flex items-center gap-3 px-4 py-2"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate font-mono text-sm">
                                            {repository.full_name}
                                        </div>
                                        <IndexStatus
                                            repository={repository}
                                            embeddings={embeddings}
                                        />
                                    </div>
                                </li>
                            ))}
                            {repositories.length === 0 && (
                                <li className="px-4 py-3 text-sm text-muted-foreground">
                                    No repositories have been connected yet.
                                </li>
                            )}
                        </ul>
                    )}

                    {isAdmin &&
                        !github.configured &&
                        (github.driver === 'app' ? (
                            <Alert>
                                <FolderGit2 />
                                <AlertTitle>
                                    GitHub App not configured
                                </AlertTitle>
                                <AlertDescription>
                                    Create a GitHub App with read-only Contents,
                                    Issues, Pull requests and Metadata
                                    permissions, then set GITHUB_APP_ID,
                                    GITHUB_APP_SLUG and GITHUB_APP_PRIVATE_KEY.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <Alert>
                                <FolderGit2 />
                                <AlertTitle>GitHub not configured</AlertTitle>
                                <AlertDescription>
                                    Create a GitHub access token with read-only
                                    Contents, Issues, Pull requests and Metadata
                                    access to the repositories Atlas should know
                                    about, then set GITHUB_TOKEN.
                                </AlertDescription>
                            </Alert>
                        ))}

                    {isAdmin &&
                        github.configured &&
                        github.driver === 'app' &&
                        accounts.length === 0 && (
                            <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Install the app on a GitHub account or
                                organisation, grant it the repositories Atlas
                                should know about, then pick them here.
                            </p>
                        )}

                    {accounts.length > 0 && (
                        <Input
                            type="search"
                            placeholder="Filter repositories"
                            aria-label="Filter repositories"
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                        />
                    )}

                    {accounts.map((account) => {
                        // If GitHub can't be reached, the account lists the repositories Atlas already uses...
                        const rows = account.repositories;
                        const connected = repositories.filter((repository) =>
                            rows.some((row) => row.id === repository.github_id),
                        );
                        const visible = rows.filter((row) =>
                            row.full_name
                                .toLowerCase()
                                .includes(filter.toLowerCase()),
                        );

                        return (
                            <div
                                key={account.key}
                                className="overflow-hidden rounded-lg border"
                            >
                                <div className="flex items-center gap-2 border-b bg-muted/40 px-4 py-2 text-sm">
                                    <Github className="size-4" />
                                    <span className="font-medium">
                                        {account.label}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {connected.length} of {rows.length} used
                                        by Atlas
                                    </span>
                                </div>
                                {account.error && (
                                    <p className="border-b px-4 py-2 text-sm text-destructive">
                                        {account.error}
                                    </p>
                                )}
                                <ul className="max-h-[28rem] divide-y overflow-y-auto">
                                    {visible.map((row) => {
                                        const repository = connected.find(
                                            (candidate) =>
                                                candidate.github_id === row.id,
                                        );

                                        return (
                                            <li
                                                key={row.id}
                                                className="flex items-center gap-3 px-4 py-2"
                                            >
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <a
                                                            href={`https://github.com/${row.full_name}`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="truncate font-mono text-sm hover:underline"
                                                        >
                                                            {row.full_name}
                                                        </a>
                                                        {row.private && (
                                                            <Badge variant="outline">
                                                                Private
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {repository && (
                                                        <IndexStatus
                                                            repository={
                                                                repository
                                                            }
                                                            embeddings={
                                                                embeddings
                                                            }
                                                        />
                                                    )}
                                                </div>
                                                <div className="ml-auto flex shrink-0">
                                                    {repository ? (
                                                        <>
                                                            <Form
                                                                {...RepositoryController.reindex.form(
                                                                    repository.id,
                                                                )}
                                                                options={{
                                                                    preserveScroll: true,
                                                                }}
                                                            >
                                                                {({
                                                                    processing,
                                                                }) => (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        disabled={
                                                                            processing ||
                                                                            indexing
                                                                        }
                                                                        aria-label={`Reindex ${repository.full_name}`}
                                                                        title="Reindex now"
                                                                    >
                                                                        <RefreshCw className="size-4" />
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                            <RemoveButton
                                                                action={RepositoryController.destroy.form(
                                                                    repository.id,
                                                                )}
                                                                label={`Stop using ${repository.full_name}`}
                                                            />
                                                        </>
                                                    ) : (
                                                        <Form
                                                            {...RepositoryController.store.form()}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <>
                                                                    <input
                                                                        type="hidden"
                                                                        name="account"
                                                                        value={
                                                                            account.key
                                                                        }
                                                                    />
                                                                    <input
                                                                        type="hidden"
                                                                        name="github_id"
                                                                        value={
                                                                            row.id
                                                                        }
                                                                    />
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                    >
                                                                        Add
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </Form>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                    {visible.length === 0 && (
                                        <li className="px-4 py-3 text-sm text-muted-foreground">
                                            {rows.length === 0
                                                ? 'This account cannot access any repositories.'
                                                : 'No repositories match the filter.'}
                                        </li>
                                    )}
                                </ul>
                            </div>
                        );
                    })}
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="MCP servers"
                        description="Remote MCP servers for documentation (e.g. Mintlify), support systems, and other knowledge. For OAuth servers, each person connects their own account, so Atlas only sees what they can."
                    />

                    <ul className="divide-y rounded-lg border empty:hidden">
                        {mcpServers.map((server) => (
                            <li
                                key={server.id}
                                className="flex items-center gap-3 px-4 py-2"
                            >
                                <Plug className="size-4 text-muted-foreground" />
                                <div className="min-w-0">
                                    <div className="text-sm font-medium">
                                        {server.name}
                                    </div>
                                    <div className="truncate font-mono text-xs text-muted-foreground">
                                        {server.url}
                                    </div>
                                </div>
                                {server.auth === 'token' && (
                                    <Badge variant="secondary">
                                        Shared token
                                    </Badge>
                                )}
                                {server.auth === 'oauth' && (
                                    <Badge
                                        variant={
                                            server.connected
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {server.connected
                                            ? 'Connected'
                                            : 'Not connected'}
                                    </Badge>
                                )}
                                <div className="ml-auto flex items-center gap-1">
                                    {server.auth === 'oauth' &&
                                        (server.connected ? (
                                            <Form
                                                {...disconnectMcp.form(
                                                    server.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Disconnect
                                                    </Button>
                                                )}
                                            </Form>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={connectMcp(server.id)}
                                                >
                                                    Connect
                                                </Link>
                                            </Button>
                                        ))}
                                    {isAdmin && (
                                        <RemoveButton
                                            action={McpServerController.destroy.form(
                                                server.id,
                                            )}
                                            label={`Remove ${server.name}`}
                                        />
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>

                    {isAdmin && (
                        <Form
                            {...McpServerController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mcp-name">Name</Label>
                                        <Input
                                            id="mcp-name"
                                            name="name"
                                            required
                                            placeholder="Docs"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="mcp-url">
                                            Server URL
                                        </Label>
                                        <Input
                                            id="mcp-url"
                                            name="url"
                                            type="url"
                                            required
                                            placeholder="https://docs.example.com/mcp"
                                        />
                                        <InputError message={errors.url} />
                                    </div>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="mcp-auth">
                                            Authentication
                                        </Label>
                                        <select
                                            id="mcp-auth"
                                            name="auth"
                                            value={auth}
                                            onChange={(event) =>
                                                setAuth(
                                                    event.target
                                                        .value as McpServer['auth'],
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                        >
                                            <option value="oauth">
                                                OAuth: each user connects their
                                                own account
                                            </option>
                                            <option value="token">
                                                Shared bearer token
                                            </option>
                                            <option value="none">None</option>
                                        </select>
                                        <InputError message={errors.auth} />
                                    </div>
                                    {auth === 'token' && (
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="mcp-token">
                                                Bearer token
                                            </Label>
                                            <Input
                                                id="mcp-token"
                                                name="token"
                                                type="password"
                                                autoComplete="off"
                                                required
                                                placeholder="Stored encrypted; used for every user"
                                            />
                                            <InputError
                                                message={errors.token}
                                            />
                                        </div>
                                    )}
                                    {auth === 'oauth' && (
                                        <>
                                            <p className="text-sm text-muted-foreground sm:col-span-2">
                                                Most servers register Atlas
                                                automatically. Only fill these
                                                in if the server requires a
                                                pre-registered OAuth client,
                                                with redirect URI{' '}
                                                <code className="text-xs">
                                                    {`${window.location.origin}/sources/mcp-servers/{id}/callback`}
                                                </code>
                                                .
                                            </p>
                                            <div className="grid gap-2">
                                                <Label htmlFor="mcp-client-id">
                                                    Client ID (optional)
                                                </Label>
                                                <Input
                                                    id="mcp-client-id"
                                                    name="oauth_client_id"
                                                    autoComplete="off"
                                                />
                                                <InputError
                                                    message={
                                                        errors.oauth_client_id
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="mcp-client-secret">
                                                    Client secret (optional)
                                                </Label>
                                                <Input
                                                    id="mcp-client-secret"
                                                    name="oauth_client_secret"
                                                    type="password"
                                                    autoComplete="off"
                                                />
                                                <InputError
                                                    message={
                                                        errors.oauth_client_secret
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2 sm:col-span-2">
                                                <Label htmlFor="mcp-scope">
                                                    Scope (optional)
                                                </Label>
                                                <Input
                                                    id="mcp-scope"
                                                    name="oauth_scope"
                                                    autoComplete="off"
                                                    placeholder="Defaults to what the server advertises"
                                                />
                                                <InputError
                                                    message={errors.oauth_scope}
                                                />
                                            </div>
                                        </>
                                    )}
                                    <div className="sm:col-span-2">
                                        <Button disabled={processing}>
                                            Add server
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </section>
            </div>
        </>
    );
}

Sources.layout = {
    breadcrumbs: [{ title: 'Sources', href: sources() }],
};
