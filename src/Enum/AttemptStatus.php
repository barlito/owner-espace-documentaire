<?php

declare(strict_types=1);

namespace App\Enum;

enum AttemptStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
