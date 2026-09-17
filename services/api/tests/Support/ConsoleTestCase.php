<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Kernel\ConsoleApplicationFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs `bin/analytics` commands against the test container.
 */
abstract class ConsoleTestCase extends IntegrationTestCase
{
    private ?Application $application = null;

    /** @param array<string, mixed> $arguments */
    protected function console(string $command, array $arguments = [], int $expectedExit = 0): string
    {
        $this->application ??= self::buildApplication();
        $output = new BufferedOutput();
        $exit = $this->application->run(new ArrayInput(['command' => $command] + $arguments), $output);
        $text = $output->fetch();
        self::assertSame($expectedExit, $exit, \sprintf("`%s` exited with %d:\n%s", $command, $exit, $text));

        return $text;
    }

    protected function buildApplication(): Application
    {
        $application = ConsoleApplicationFactory::create($this->container);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application;
    }

    protected function tearDown(): void
    {
        $this->application = null;
        parent::tearDown();
    }
}
