<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'funnels')]
#[ORM\UniqueConstraint(name: 'uniq_funnels_site_name', columns: ['site_id', 'name'])]
class Funnel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    public ?int $id = null;

    /** @var Collection<int, FunnelStep> */
    #[ORM\OneToMany(targetEntity: FunnelStep::class, mappedBy: 'funnel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $steps;

    public function __construct(
        #[ORM\Column(name: 'site_id', type: 'integer', options: ['unsigned' => true])]
        public int $siteId,
        #[ORM\Column(type: 'string', length: 120)]
        public string $name,
        /** visit: steps within one visit; visitor: within window_days for consented visitors */
        #[ORM\Column(type: 'string', length: 16)]
        public string $scope,
        #[ORM\Column(name: 'window_days', type: 'smallint', options: ['unsigned' => true])]
        public int $windowDays,
    ) {
        $this->steps = new ArrayCollection();
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Funnel not persisted.');
    }

    /** @param list<int> $goalIds */
    public function replaceSteps(array $goalIds): void
    {
        $this->steps->clear();
        foreach (array_values($goalIds) as $position => $goalId) {
            $this->steps->add(new FunnelStep($this, $position + 1, $goalId));
        }
    }
}
