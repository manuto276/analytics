<?php

declare(strict_types=1);

namespace Analytics\Conversions\Application;

use Analytics\Conversions\Domain\ApiKey;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use Analytics\Shared\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * API keys shaped "ak_<prefix>_<secret>": the prefix identifies the row, the secret is stored hashed.
 */
final readonly class ApiKeyService
{
    public const string PATTERN = '/^ak_([A-Za-z0-9]{8})_([A-Za-z0-9_-]{43})$/';
    private const int LAST_USED_THROTTLE_SECONDS = 300;

    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock) {}

    /**
     * @param list<string> $scopes
     *
     * @return array{0: ApiKey, 1: string} key and the plaintext token (shown once)
     */
    public function create(int $siteId, string $name, array $scopes, ?int $createdBy, ?\DateTimeImmutable $expiresAt = null): array
    {
        foreach ($scopes as $scope) {
            if (!\in_array($scope, ApiKey::SCOPES, true)) {
                throw ApiProblem::validation(['scopes' => ['Unknown scope ' . $scope . '.']]);
            }
        }
        if ($scopes === []) {
            throw ApiProblem::validation(['scopes' => ['Choose at least one scope.']]);
        }
        do {
            $prefix = TokenGenerator::alphanumeric(8);
        } while ($this->em->getRepository(ApiKey::class)->findOneBy(['prefix' => $prefix]) !== null);

        $secret = TokenGenerator::base64Url(32);
        $key = new ApiKey($siteId, $name, $prefix, TokenHasher::hash($secret), array_values($scopes), $createdBy, $this->clock->now(), $expiresAt);
        $this->em->persist($key);
        $this->em->flush();

        return [$key, 'ak_' . $prefix . '_' . $secret];
    }

    public function verify(string $token): ?ApiKey
    {
        if (preg_match(self::PATTERN, $token, $m) !== 1) {
            return null;
        }
        $key = $this->em->getRepository(ApiKey::class)->findOneBy(['prefix' => $m[1]]);
        if (!$key instanceof ApiKey || !TokenHasher::equals($key->secretHash, $m[2]) || !$key->isUsable($this->clock->now())) {
            return null;
        }
        $now = $this->clock->now();
        if ($key->lastUsedAt === null || $now->getTimestamp() - $key->lastUsedAt->getTimestamp() > self::LAST_USED_THROTTLE_SECONDS) {
            $key->lastUsedAt = $now;
            $this->em->flush();
        }

        return $key;
    }

    public function revoke(int $siteId, int $keyId): ApiKey
    {
        $key = $this->em->find(ApiKey::class, $keyId);
        if (!$key instanceof ApiKey || $key->siteId !== $siteId) {
            throw ApiProblem::notFound('API key not found.');
        }
        $key->revokedAt ??= $this->clock->now();
        $this->em->flush();

        return $key;
    }

    /** @return list<ApiKey> */
    public function forSite(int $siteId): array
    {
        /** @var list<ApiKey> $keys */
        $keys = $this->em->getRepository(ApiKey::class)->findBy(['siteId' => $siteId], ['createdAt' => 'DESC']);

        return $keys;
    }

    /** @return array<string, mixed> */
    public static function toArray(ApiKey $key): array
    {
        return [
            'id' => $key->id(),
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'created_at' => $key->createdAt->format(\DATE_ATOM),
            'last_used_at' => $key->lastUsedAt?->format(\DATE_ATOM),
            'expires_at' => $key->expiresAt?->format(\DATE_ATOM),
            'revoked_at' => $key->revokedAt?->format(\DATE_ATOM),
        ];
    }
}
