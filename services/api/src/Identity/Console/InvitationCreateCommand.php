<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\InvitationService;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'invitation:create', description: 'Creates an invitation and prints the link (no mailer needed)')]
final class InvitationCreateCommand extends Command
{
    public function __construct(private readonly InvitationService $invitations, private readonly SiteRepository $sites, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED)
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin (global) | member | site role admin/viewer with --site', 'member')
            ->addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Site id or public key (role applies to it)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = Types::string($input->getOption('email'));
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>--email must be a valid email address.</error>');

            return self::INVALID;
        }
        $role = Types::string($input->getOption('role'));
        /** @var list<string> $siteRefs */
        $siteRefs = $input->getOption('site');
        $globalRole = $role === 'admin' && $siteRefs === [] ? GlobalRole::Admin : GlobalRole::Member;
        $siteRole = SiteRole::tryFrom($role) ?? SiteRole::Viewer;
        $siteRoles = [];
        foreach ($siteRefs as $ref) {
            $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
            if ($site === null) {
                $output->writeln('<error>Site not found: ' . $ref . '</error>');

                return self::FAILURE;
            }
            $siteRoles[] = ['site_id' => $site->id(), 'role' => $siteRole];
        }
        try {
            [$invitation, $token] = $this->invitations->create($email, $globalRole, $siteRoles, null);
        } catch (ApiProblem $e) {
            $output->writeln('<error>' . $e->detail . '</error>');

            return self::FAILURE;
        }
        $this->audit->log('invitation.created', Actor::console(), null, 'invitation', $invitation->id());
        $output->writeln('Invitation link (valid 7 days):');
        $output->writeln($this->invitations->link($token));

        return self::SUCCESS;
    }
}
