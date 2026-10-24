<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Process\Process;

/**
 * Creates the `bin/magento` child processes of the concurrent benchmark (injected so unit tests do not
 * spawn processes).
 */
class WorkerProcessFactory
{
    private const float TIMEOUT_SECONDS = 900.0;

    public function __construct(private readonly DirectoryList $directoryList)
    {
    }

    /**
     * @param string[] $arguments `bin/magento` arguments, command name first.
     */
    public function create(array $arguments): Process
    {
        $root = $this->directoryList->getRoot();

        return new Process(
            [PHP_BINARY, $root . '/bin/magento', ...$arguments],
            $root,
            null,
            null,
            self::TIMEOUT_SECONDS,
        );
    }
}
