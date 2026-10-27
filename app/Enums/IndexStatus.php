<?php

namespace App\Enums;

enum IndexStatus: string
{
    case Pending = 'pending';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
