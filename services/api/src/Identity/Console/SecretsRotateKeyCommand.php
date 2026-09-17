<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Domain\TotpCredential;
use Analytics\Shared\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'secrets:rotate-key', description: 'Re-encrypts stored secrets (TOTP) with the active key of APP_ENCRYPTION_KEYS')]
final class SecretsRotateKeyCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TotpService $totp, private readonly SecretBox $box)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        /** @var list<TotpCredential> $credentials */
        $credentials = $this->em->getRepository(TotpCredential::class)->findAll();
        foreach ($credentials as $credential) {
            if ($this->totp->reencrypt($credential)) {
                ++$count;
            }
        }
        $this->em->flush();
        $output->writeln(\sprintf('Re-encrypted %d secret(s) with key "%s". Old keys can be removed from APP_ENCRYPTION_KEYS once no secret uses them.', $count, $this->box->activeKeyId()));

        return self::SUCCESS;
    }
}
