import { Link, router, usePage } from '@inertiajs/react';
import { FolderGit2, Plug, Sparkles, X } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { sources as sourcesPage } from '@/routes';
import { connect } from '@/routes/mcp-servers/oauth';
import { sourceHint, welcome } from '@/routes/onboarding';
import type { ChatSources, ChatSourceServer } from '@/types';

type PageProps = { sources: ChatSources; welcome: boolean };

function useSources() {
    return usePage<PageProps>().props;
}

function names(servers: ChatSourceServer[]) {
    const list = servers.map((server) => server.name);

    return list.length > 1
        ? `${list.slice(0, -1).join(', ')} and ${list.at(-1)}`
        : list[0];
}

function repositoryLabel(count: number) {
    return `${count} ${count === 1 ? 'repository' : 'repositories'}`;
}

function ConnectButton({ server }: { server: ChatSourceServer }) {
    return (
        <Button variant="outline" size="sm" className="text-foreground" asChild>
            <Link href={connect(server.id)}>Connect {server.name}</Link>
        </Button>
    );
}

/**
 * What a new chat can read, with a way to connect the servers that need the user's own account.
 */
export function SourcesStrip() {
    const { sources } = useSources();
    const isAdmin = usePage().props.auth.user.role === 'admin';

    if (sources.repositories === 0 && sources.mcpServers.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No sources are connected yet.{' '}
                {isAdmin ? (
                    <Link href={sourcesPage()} className="underline">
                        Add repositories and MCP servers
                    </Link>
                ) : (
                    'Ask an admin to add repositories and MCP servers.'
                )}
            </p>
        );
    }

    const chip =
        'inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs';

    return (
        <ul
            aria-label="Sources this chat can use"
            className="flex flex-wrap gap-2"
        >
            {sources.repositories > 0 && (
                <li>
                    <Link
                        href={sourcesPage()}
                        className={cn(chip, 'hover:bg-accent')}
                    >
                        <FolderGit2 className="size-3.5 text-muted-foreground" />
                        {repositoryLabel(sources.repositories)}
                    </Link>
                </li>
            )}
            {sources.mcpServers.map((server) => (
                <li key={server.id}>
                    {server.connected ? (
                        <span className={chip}>
                            <span className="size-1.5 rounded-full bg-emerald-500" />
                            {server.name}
                        </span>
                    ) : (
                        <Link
                            href={connect(server.id)}
                            title={`Connect your ${server.name} account so Atlas can use it`}
                            className={cn(
                                chip,
                                'border-dashed text-muted-foreground hover:bg-accent hover:text-foreground',
                            )}
                        >
                            <span className="size-1.5 rounded-full bg-amber-500" />
                            {server.name}
                            <span className="font-medium text-foreground">
                                Connect
                            </span>
                        </Link>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Announces OAuth servers added since the user last saw the list, until they connect or dismiss.
 */
export function NewSourcesHint() {
    const { sources } = useSources();
    const added = sources.mcpServers.filter((server) => server.isNew);

    if (added.length === 0) {
        return null;
    }

    return (
        <Alert className="relative pr-10">
            <Sparkles />
            <AlertTitle>
                {names(added)} {added.length === 1 ? 'was' : 'were'} added
            </AlertTitle>
            <AlertDescription>
                <p>
                    Connect your account so Atlas can search{' '}
                    {added.length === 1 ? 'it' : 'them'} when answering you.
                    Atlas only sees what your account can access.
                </p>
                <div className="mt-2 flex flex-wrap gap-2">
                    {added.map((server) => (
                        <ConnectButton key={server.id} server={server} />
                    ))}
                </div>
            </AlertDescription>
            <Button
                variant="ghost"
                size="icon"
                className="absolute top-2 right-2 size-7"
                aria-label="Dismiss"
                onClick={() =>
                    router.post(
                        sourceHint.url(),
                        {},
                        { only: ['sources'], preserveScroll: true },
                    )
                }
            >
                <X className="size-4" />
            </Button>
        </Alert>
    );
}

/**
 * Shown over the first chat: explains where answers come from and which servers need connecting.
 */
export function WelcomeDialog() {
    const { sources, welcome: open } = useSources();

    const dismiss = () =>
        router.post(
            welcome.url(),
            {},
            { only: ['welcome', 'sources'], preserveScroll: true },
        );

    return (
        <Dialog open={open} onOpenChange={(next) => !next && dismiss()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Welcome to Atlas</DialogTitle>
                    <DialogDescription>
                        Ask anything about the product. Atlas answers from the
                        sources below and cites where each fact came from.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 text-sm">
                    <div className="flex items-start gap-3">
                        <FolderGit2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div>
                            <div className="font-medium">Code</div>
                            <p className="text-muted-foreground">
                                {sources.repositories > 0
                                    ? `${repositoryLabel(sources.repositories)}, indexed and shared with everyone.`
                                    : 'No repositories have been added yet.'}
                            </p>
                        </div>
                    </div>

                    {sources.mcpServers.length > 0 && (
                        <div className="flex items-start gap-3">
                            <Plug className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">
                                    Docs, support and other tools
                                </div>
                                <p className="text-muted-foreground">
                                    Some need your own account, so Atlas only
                                    sees what you can.
                                </p>
                                <ul className="mt-2 divide-y rounded-lg border">
                                    {sources.mcpServers.map((server) => (
                                        <li
                                            key={server.id}
                                            className="flex min-h-11 items-center gap-3 px-3 py-1.5"
                                        >
                                            <span className="truncate">
                                                {server.name}
                                            </span>
                                            <span className="ml-auto shrink-0">
                                                {server.connected ? (
                                                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        <span className="size-1.5 rounded-full bg-emerald-500" />
                                                        {server.auth === 'oauth'
                                                            ? 'Connected'
                                                            : 'Ready'}
                                                    </span>
                                                ) : (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={connect(
                                                                server.id,
                                                            )}
                                                        >
                                                            Connect
                                                        </Link>
                                                    </Button>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button onClick={dismiss}>Start asking</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
