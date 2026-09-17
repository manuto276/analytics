<?php

declare(strict_types=1);

namespace Analytics\Tracking\Infrastructure;

use Analytics\Tracking\Application\DailySaltProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalDailySaltProvider implements DailySaltProvider
{
    private ?string $day = null;
    private ?string $salt = null;

    public function __construct(private readonly Connection $connection) {}

    public function saltFor(\DateTimeImmutable $now): string
    {
        $day = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        if ($this->day === $day && $this->salt !== null) {
            return $this->salt;
        }
        $this->connection->executeStatement(
            'INSERT IGNORE INTO daily_salts (day, salt, created_at) VALUES (?, ?, ?)',
            [$day, random_bytes(32), $now->format('Y-m-d H:i:s.v')],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
        );
        $salt = $this->connection->fetchOne('SELECT salt FROM daily_salts WHERE day = ?', [$day]);
        if (!\is_string($salt) || \strlen($salt) !== 32) {
            throw new \RuntimeException('Daily salt unavailable.');
        }
        $this->connection->executeStatement('DELETE FROM daily_salts WHERE day < ?', [$day]);
        $this->day = $day;
        $this->salt = $salt;

        return $salt;
    }

    public function rotate(\DateTimeImmutable $now): int
    {
        $this->day = null;
        $this->salt = null;
        $this->saltFor($now);

        return 0;
    }

    public function forgetMemo(): void
    {
        $this->day = null;
        $this->salt = null;
    }
}
