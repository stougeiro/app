<?php declare(strict_types=1);

namespace Sourcerer\Tool\Contract;


enum ExitCode: int
{
    case Success = 0;
    case Failure = 1;
    case Invalid = 2;
    case CheckFailed = 3;
}
