import { Link, usePage } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { chat } from '@/routes';

export function NavConversations() {
    const { conversations } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();

    if (conversations.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="min-h-0 px-2 group-data-[collapsible=icon]:hidden">
            <SidebarGroupLabel>Chats</SidebarGroupLabel>
            <SidebarMenu>
                {conversations.map((conversation) => (
                    <SidebarMenuItem key={conversation.id}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(chat(conversation.id))}
                        >
                            <Link href={chat(conversation.id)}>
                                <span>{conversation.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
