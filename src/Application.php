<?php

declare(strict_types=1);

namespace Refit;

use Refit\Command\FixCommand;
use Refit\Command\ScanCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0';
    public const REPOSITORY_URL = 'https://github.com/qiyuanlabs-ai/refit';
    public const DEFAULT_TARGET = '8.4';

    public function __construct()
    {
        parent::__construct('Refit', self::VERSION);
        $this->add(new ScanCommand());
        $this->add(new FixCommand());
    }
}
