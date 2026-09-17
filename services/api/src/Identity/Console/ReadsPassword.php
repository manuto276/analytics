<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Identity\Application\PasswordHasher;
use Analytics\Shared\Types;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

trait ReadsPassword
{
    /** @var resource|null test hook */
    public $passwordStream;

    private function readPassword(InputInterface $input, OutputInterface $output): ?string
    {
        if ($input->getOption('password-stdin') === true) {
            $stream = $this->passwordStream ?? \STDIN;
            $password = rtrim((string) stream_get_contents($stream), "\r\n");
        } else {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Use --password-stdin in non-interactive mode.</error>');

                return null;
            }
            $helper = new QuestionHelper();
            $question = new Question('Password: ')->setHidden(true)->setHiddenFallback(false);
            $password = Types::string($helper->ask($input, $output, $question));
        }
        $violations = PasswordHasher::validatePolicy($password);
        if ($violations !== []) {
            $output->writeln('<error>Password ' . strtolower(implode(' ', $violations)) . '</error>');

            return null;
        }

        return $password;
    }
}
