<?php

namespace App\Enums;

enum McpAuth: string
{
    case None = 'none';
    case Token = 'token';
    case OAuth = 'oauth';
}
