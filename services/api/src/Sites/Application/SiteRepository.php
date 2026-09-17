<?php

declare(strict_types=1);

namespace Analytics\Sites\Application;

use Analytics\Shared\Http\ApiProblem;
use Analytics\Sites\Domain\Site;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

final readonly class SiteRepository
{
    public const int SNAPSHOT_TTL = 60;

    public function __construct(
        private EntityManagerInterface $em,
        private CacheItemPoolInterface $cache,
    ) {}

    public function find(int $id): ?Site
    {
        $site = $this->em->find(Site::class, $id);

        return $site instanceof Site ? $site : null;
    }

    public function get(int $id): Site
    {
        return $this->find($id) ?? throw ApiProblem::notFound('Site not found.');
    }

    public function findByPublicKey(string $publicKey): ?Site
    {
        $site = $this->em->getRepository(Site::class)->findOneBy(['publicKey' => $publicKey]);

        return $site instanceof Site ? $site : null;
    }

    /** @return list<Site> */
    public function all(bool $includeArchived = false): array
    {
        $qb = $this->em->createQueryBuilder()->select('s')->from(Site::class, 's')->orderBy('s.name', 'ASC');
        if (!$includeArchived) {
            $qb->where('s.archivedAt IS NULL');
        }
        /** @var list<Site> $sites */
        $sites = $qb->getQuery()->getResult();

        return $sites;
    }

    /** Snapshot by public key, cached for SNAPSHOT_TTL seconds. */
    public function snapshotByPublicKey(string $publicKey): ?SiteSnapshot
    {
        if (preg_match('/^pk_[A-Za-z0-9]{21}$/', $publicKey) !== 1) {
            return null;
        }
        $item = $this->cache->getItem('site_snapshot_' . $publicKey);
        if ($item->isHit()) {
            $value = $item->get();

            return $value instanceof SiteSnapshot ? $value : null;
        }
        $site = $this->findByPublicKey($publicKey);
        $snapshot = $site === null ? null : SiteSnapshot::fromSite($site);
        $item->set($snapshot)->expiresAfter($snapshot === null ? 10 : self::SNAPSHOT_TTL);
        $this->cache->save($item);

        return $snapshot;
    }

    public function snapshot(int $siteId): ?SiteSnapshot
    {
        $site = $this->find($siteId);

        return $site === null ? null : SiteSnapshot::fromSite($site);
    }

    public function forgetSnapshot(Site $site): void
    {
        $this->cache->deleteItem('site_snapshot_' . $site->publicKey);
    }
}
