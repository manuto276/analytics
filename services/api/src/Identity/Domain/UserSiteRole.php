<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_site_roles')]
#[ORM\Index(name: 'idx_user_site_roles_site', columns: ['site_id'])]
class UserSiteRole
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'integer', options: ['unsigned' => true])]
        public int $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(type: 'string', length: 16, enumType: SiteRole::class)]
        public SiteRole $role,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
    ) {}
}
