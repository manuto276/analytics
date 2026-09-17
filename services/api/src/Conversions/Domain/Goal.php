<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'goals')]
#[ORM\UniqueConstraint(name: 'uniq_goals_site_name', columns: ['site_id', 'name'])]
class Goal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    /** @param array<string, mixed> $match */
    public function __construct(
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(type: 'string', length: 120)]
        public string $name,
        #[ORM\Column(type: 'string', length: 16, enumType: GoalType::class)]
        public GoalType $type,
        #[ORM\Column(name: '`match`', type: 'json')]
        public array $match,
    ) {}

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Goal not persisted.');
    }
}
