<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\RecoveryCode;
use Analytics\Identity\Domain\TotpCredential;
use Analytics\Identity\Domain\User;
use Analytics\Shared\Crypto\SecretBox;
use Analytics\Shared\Crypto\TokenGenerator;
use Analytics\Shared\Crypto\TokenHasher;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

final readonly class TotpService
{
    public const int PERIOD = 30;
    public const int WINDOW = 1;
    public const int RECOVERY_CODES = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private SecretBox $secretBox,
        private ClockInterface $clock,
        private string $issuer,
    ) {}

    public function credential(int $userId): ?TotpCredential
    {
        $credential = $this->em->find(TotpCredential::class, $userId);

        return $credential instanceof TotpCredential ? $credential : null;
    }

    public function isEnabled(int $userId): bool
    {
        return $this->credential($userId)?->isConfirmed() === true;
    }

    /** @return array{secret: string, otpauth_uri: string, qr_svg: string} */
    public function beginSetup(User $user): array
    {
        $existing = $this->credential($user->id());
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }
        $totp = TOTP::generate()->withLabel(self::nonEmpty($user->email))->withIssuer(self::nonEmpty($this->issuer));
        $secret = $totp->getSecret();

        $credential = new TotpCredential($user->id(), $this->secretBox->encrypt($secret, 'totp:' . $user->id()), $this->secretBox->activeKeyId(), $this->clock->now());
        $this->em->persist($credential);
        $this->em->flush();

        $uri = $totp->getProvisioningUri();
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $writer->writeString($uri)];
    }

    /**
     * Confirms the pending credential with a first code and returns fresh recovery codes.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        $credential = $this->credential($user->id());
        if ($credential === null || $credential->isConfirmed() || !$this->verifyCode($credential, $code)) {
            return null;
        }
        $credential->confirmedAt = $this->clock->now();
        $this->em->flush();

        return $this->regenerateRecoveryCodes($user->id());
    }

    /** Verifies a TOTP code (with replay protection) or a single-use recovery code. */
    public function verifyLogin(int $userId, string $code): bool
    {
        $credential = $this->credential($userId);
        if ($credential === null || !$credential->isConfirmed()) {
            return false;
        }
        $code = strtolower(trim($code));
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->verifyCode($credential, $code);
        }

        return $this->useRecoveryCode($userId, $code);
    }

    public function disable(int $userId): void
    {
        $credential = $this->credential($userId);
        if ($credential !== null) {
            $this->em->remove($credential);
        }
        $this->em->createQueryBuilder()->delete(RecoveryCode::class, 'r')->where('r.userId = :u')->setParameter('u', $userId)->getQuery()->execute();
        $this->em->flush();
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $this->em->createQueryBuilder()->delete(RecoveryCode::class, 'r')->where('r.userId = :u')->setParameter('u', $userId)->getQuery()->execute();
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; ++$i) {
            $raw = TokenGenerator::lowerAlphanumeric(10);
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $codes[] = $code;
            $this->em->persist(new RecoveryCode($userId, TokenHasher::hash($code)));
        }
        $this->em->flush();

        return $codes;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(r.id)')->from(RecoveryCode::class, 'r')
            ->where('r.userId = :u')->andWhere('r.usedAt IS NULL')->setParameter('u', $userId)
            ->getQuery()->getSingleScalarResult();
    }

    /** Re-encrypts the secret with the active key when needed. Returns true when re-encrypted. */
    public function reencrypt(TotpCredential $credential): bool
    {
        if (!$this->secretBox->needsReencryption($credential->secretCiphertext)) {
            return false;
        }
        $secret = $this->secretBox->decrypt($credential->secretCiphertext, 'totp:' . $credential->userId);
        $credential->secretCiphertext = $this->secretBox->encrypt($secret, 'totp:' . $credential->userId);
        $credential->keyId = $this->secretBox->activeKeyId();

        return true;
    }

    /** Current code for a user (tests and console diagnostics only). */
    public function currentCode(int $userId, int $offsetSteps = 0): string
    {
        $credential = $this->credential($userId) ?? throw new \RuntimeException('No TOTP credential.');
        $totp = TOTP::createFromSecret(self::nonEmpty($this->secretBox->decrypt($credential->secretCiphertext, 'totp:' . $userId)));

        return $totp->at(max(0, $this->clock->now()->getTimestamp() + $offsetSteps * self::PERIOD));
    }

    private function verifyCode(TotpCredential $credential, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $totp = TOTP::createFromSecret(self::nonEmpty($this->secretBox->decrypt($credential->secretCiphertext, 'totp:' . $credential->userId)));
        $now = $this->clock->now()->getTimestamp();
        $currentStep = intdiv($now, self::PERIOD);
        $lastUsed = $credential->lastUsedStep === null ? -1 : (int) $credential->lastUsedStep;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; ++$offset) {
            $step = $currentStep + $offset;
            if ($step <= $lastUsed) {
                continue;
            }
            if ($step >= 0 && hash_equals($totp->at($step * self::PERIOD), $code)) {
                $credential->lastUsedStep = (string) $step;
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    /** @return non-empty-string */
    private static function nonEmpty(string $value): string
    {
        return $value !== '' ? $value : throw new \UnexpectedValueException('Unexpected empty value.');
    }

    private function useRecoveryCode(int $userId, string $code): bool
    {
        $recovery = $this->em->getRepository(RecoveryCode::class)->findOneBy(['userId' => $userId, 'codeHash' => TokenHasher::hash($code), 'usedAt' => null]);
        if (!$recovery instanceof RecoveryCode) {
            return false;
        }
        $recovery->usedAt = $this->clock->now();
        $this->em->flush();

        return true;
    }
}
