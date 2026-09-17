<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'funnel_steps')]
#[ORM\Index(name: 'idx_funnel_steps_goal', columns: ['goal_id'])]
class FunnelStep
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Funnel::class, inversedBy: 'steps')]
        #[ORM\JoinColumn(name: 'funnel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public Funnel $funnel,
        #[ORM\Id]
        #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
        public int $position,
        #[ORM\Column(name: 'goal_id', type: 'integer', options: ['unsigned' => true])]
        public int $goalId,
    ) {}
}
