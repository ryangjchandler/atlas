import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, update } from '@/routes/users';
import type { User } from '@/types';

export default function Users({ users }: { users: User[] }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Users" />

            <h1 className="sr-only">Users</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Users"
                    description="Admins manage Atlas's repositories and MCP servers. Users chat and connect their own accounts."
                />

                <ul className="divide-y rounded-lg border">
                    {users.map((user) => {
                        const isSelf = user.id === auth.user.id;

                        return (
                            <li
                                key={user.id}
                                className="flex items-center gap-3 px-4 py-2"
                            >
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-medium">
                                        {user.name}
                                        {isSelf && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (you)
                                            </span>
                                        )}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </div>
                                </div>
                                <Select
                                    value={user.role}
                                    disabled={isSelf}
                                    onValueChange={(role) =>
                                        router.patch(
                                            update.url(user.id),
                                            { role },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        className="ml-auto w-28"
                                        aria-label={`Role for ${user.name}`}
                                        title={
                                            isSelf
                                                ? 'You cannot change your own role'
                                                : undefined
                                        }
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent align="end">
                                        <SelectItem value="admin">
                                            Admin
                                        </SelectItem>
                                        <SelectItem value="user">
                                            User
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

Users.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};
