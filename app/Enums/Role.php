<?php

namespace App\Enums;

enum Role: string
{
    /**
     * Configures Atlas: connected repositories, MCP servers, and users' roles.
     */
    case Admin = 'admin';

    /**
     * Chats with Atlas and connects their own accounts on OAuth MCP servers.
     */
    case User = 'user';
}
