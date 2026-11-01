<?php

declare(strict_types=1);

namespace LaraInspect\Model;

enum ResultStatus: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
