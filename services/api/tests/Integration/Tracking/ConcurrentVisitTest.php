<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Tracking;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;
use Analytics\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Two requests of one visitor arriving at the same time must join one visit.
 *
 * `IngestBatchHandler` runs under READ COMMITTED, where InnoDB takes no gap lock: a
 * `SELECT … FROM visit_lookup … FOR UPDATE` that finds nothing locks nothing, so two transactions
 * can both conclude "no visit yet". Before the fix they both started one and then overwrote each
 * other's `visit_lookup` row without any unique violation — which is also why the deadlock retry
 * loop never noticed. The fix claims the key with a single `INSERT IGNORE INTO visit_lookup`, whose
 * primary key does serialise the racers.
 *
 * Both tests use a second, real MySQL connection so the race is reproduced rather than simulated.
 */
final class ConcurrentVisitTest extends HttpTestCase
{
    protected bool $useTransaction = false;

    /** 25 ms apart: how long the racer waits for the request under test to block on its lock. */
    private const int POLL_ATTEMPTS = 60;
    /** Seconds the request must have spent blocked for the interleaving to have really happened. */
    private const float MIN_BLOCK_SECONDS = 1.0;

    /**
     * The racer is a separate OS process, so its transaction is genuinely concurrent with the
     * request under test. The interleaving the bug needs is
     *
     *   A: read the key → nothing | B: read the key → nothing | A: create | B: create,
     *
     * so the racer has to slip its own claim between the request's lookup and its claim. The window
     * is opened with the one locking read the handler makes in between: for a consent upgrade whose
     * cookie-level key is unknown, it reads the *base* (daily-hash) key of the same page load to see
     * whether that visit can be adopted. The racer holds a lock on that row, the request blocks
     * there, and the racer claims the session key and commits while it waits. The base visit is
     * yesterday's, so it cannot be adopted and the handler really does go on to create a visit.
     */
    public function testAConcurrentClaimMakesTheRequestJoinTheOtherVisitInsteadOfStartingASecondOne(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);

        // A base-level pageview creates the visit and the daily-hash lookup row of this browser.
        $this->assertStatus(202, $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/')])));
        $visit = $this->db->fetchAssociative('SELECT id, local_day FROM visits WHERE site_id = ?', [$site->id()]);
        self::assertIsArray($visit);
        $visitId = (int) $visit['id'];
        $day = (string) $visit['local_day'];
        $hashKey = (string) $this->db->fetchOne('SELECT visitor_key FROM visit_lookup WHERE site_id = ?', [$site->id()]);
        $this->db->executeStatement(
            'UPDATE visit_lookup SET visit_day = ?, last_activity_at = ? WHERE site_id = ?',
            [$this->clock->now()->modify('-1 day')->format('Y-m-d'), $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.v'), $site->id()],
        );

        $sessionId = Payloads::id22();
        $sessionKey = (string) Base64Url::decode($sessionId);
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.v');
        $racer = $this->startRacer($site->id(), $hashKey, $sessionKey, $visitId, $day, $timestamp);
        self::assertSame('locked:1', $racer['line'], 'the racer must hold the base key before the request starts');

        $start = microtime(true);
        $this->assertStatus(202, $this->collect(Payloads::batch($site->publicKey, [
            Payloads::consentUpgrade('https://www.site.test/'),
        ], 'c', ['vid' => Payloads::id22(), 'sid' => $sessionId, 'cv' => 1])));
        $elapsed = microtime(true) - $start;

        // The racer reports the lock wait when it can see it; reading performance_schema needs the
        // PROCESS privilege, which the containerised test user does not have, so the request's own
        // wall clock is the portable proof that it really sat behind the racer's lock.
        $observed = $this->finishRacer($racer);
        if ($observed !== 'committed:1' && $elapsed < self::MIN_BLOCK_SECONDS) {
            self::markTestSkipped(\sprintf('the request did not block on the racer (%s, %.3f s): the interleaving under test did not happen here', $observed, $elapsed));
        }

        $visits = $this->db->fetchAllAssociative('SELECT id, level FROM visits WHERE site_id = ?', [$site->id()]);
        self::assertCount(1, $visits, 'the request must join the visit the racer claimed, not start a second one');
        self::assertSame($visitId, (int) $visits[0]['id']);
        self::assertSame('c', $visits[0]['level'], 'the consent upgrade still upgrades the visit it reuses');

        $lookup = $this->db->fetchAssociative('SELECT visit_id FROM visit_lookup WHERE site_id = ? AND visitor_key = ?', [$site->id(), $sessionKey], [ParameterType::INTEGER, ParameterType::BINARY]);
        self::assertIsArray($lookup);
        self::assertSame($visitId, (int) $lookup['visit_id'], 'no visit is left orphaned in visit_lookup');
        self::assertSame($visitId, (int) $this->db->fetchOne("SELECT visit_id FROM events_raw WHERE site_id = ? AND type = 'cu'", [$site->id()]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT visits FROM visitors WHERE site_id = ?', [$site->id()]), 'the visitor is credited with one visit, not two');
    }

    /**
     * The contract claimVisitorKey() relies on, asserted directly on two connections: a row that
     * does not exist yet is not protected by a locking read, but it is by the insert.
     */
    public function testTheVisitorKeyClaimSerialisesWhereAForUpdateReadDoesNot(): void
    {
        $site = $this->factory->site();
        $key = random_bytes(16);
        $day = $this->clock->now()->format('Y-m-d');
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.v');
        $a = self::extraConnection();
        $b = self::extraConnection();

        try {
            $b->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            foreach ([$a, $b] as $connection) {
                $connection->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->beginTransaction();
                self::assertFalse(
                    $connection->fetchAssociative('SELECT visit_id FROM visit_lookup WHERE site_id = ? AND visitor_key = ? FOR UPDATE', [$site->id(), $key], [ParameterType::INTEGER, ParameterType::BINARY]),
                    'both transactions see no visit: the locking read of a missing row blocks nobody',
                );
            }

            self::assertTrue(self::claim($a, $site->id(), $key, $day, $timestamp), 'the first claim wins');

            try {
                self::claim($b, $site->id(), $key, $day, $timestamp);
                self::fail('the second claim must wait for the first transaction instead of succeeding');
            } catch (LockWaitTimeoutException) {
                $this->addToAssertionCount(1);
            }

            $a->commit();
            $b->rollBack();
            $b->beginTransaction();
            self::assertFalse(self::claim($b, $site->id(), $key, $day, $timestamp), 'once the winner has committed, the loser is told it lost');
            self::assertSame(1, (int) $b->fetchOne('SELECT COUNT(*) FROM visit_lookup WHERE site_id = ? AND visitor_key = ?', [$site->id(), $key], [ParameterType::INTEGER, ParameterType::BINARY]));
            $b->rollBack();
        } finally {
            foreach ([$a, $b] as $connection) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $connection->close();
            }
        }
    }

    private static function extraConnection(): Connection
    {
        return DriverManager::getConnection(new DsnParser(['mysql' => 'pdo_mysql'])->parse(TestDatabase::url()));
    }

    private static function claim(Connection $connection, int $siteId, string $key, string $day, string $timestamp): bool
    {
        return (int) $connection->executeStatement(
            'INSERT IGNORE INTO visit_lookup (site_id, visitor_key, visit_id, visit_day, last_activity_at, source_key) VALUES (?, ?, 0, ?, ?, NULL)',
            [$siteId, $key, $day, $timestamp],
            [ParameterType::INTEGER, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        ) === 1;
    }

    /**
     * Starts the racing process and returns once it holds its lock. It then waits for a transaction
     * to actually block before claiming the session key and committing, so the interleaving does not
     * depend on how fast this machine is, and it reports whether it really saw the request waiting.
     * The wait is observed on a second connection because the InnoDB `information_schema` /
     * `performance_schema` lock views are materialised once per transaction, and the racer's own
     * connection is inside one. Reading them needs the PROCESS privilege: where the test user does
     * not have it the poll simply runs out, the racer holds its lock for the whole window anyway,
     * and the caller falls back to timing the request.
     *
     * @return array{process: resource, pipes: array<int, resource>, line: string}
     */
    private function startRacer(int $siteId, string $hashKey, string $sessionKey, int $visitId, string $day, string $timestamp): array
    {
        $params = new DsnParser(['mysql' => 'pdo_mysql'])->parse(TestDatabase::url());
        $session = \sprintf("site_id = %d AND visitor_key = UNHEX('%s')", $siteId, bin2hex($sessionKey));
        $code = \sprintf(
            '$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];'
            . '$pdo = new PDO(%1$s, %2$s, %3$s, $options);'
            . '$watch = new PDO(%1$s, %2$s, %3$s, $options);'
            . '$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");'
            . '$pdo->beginTransaction();'
            . '$rows = $pdo->query(%4$s)->fetchAll();'
            . 'fwrite(STDOUT, "locked:" . count($rows) . "\n"); fflush(STDOUT);'
            . '$waited = 0;'
            . 'for ($i = 0; $i < %5$d && $waited === 0; ++$i) {'
            . '  try { $waited = (int) $watch->query("SELECT COUNT(*) FROM performance_schema.data_lock_waits")->fetchColumn(); }'
            . '  catch (Throwable $e) { $waited = 0; }'
            . '  if ($waited === 0) { usleep(25000); }'
            . '}'
            . '$pdo->exec(%6$s);'
            . '$pdo->exec(%7$s);'
            . '$pdo->commit();'
            . 'fwrite(STDOUT, "committed:" . min($waited, 1) . "\n"); fflush(STDOUT);',
            var_export(\sprintf('mysql:host=%s;port=%d;dbname=%s', $params['host'] ?? '127.0.0.1', $params['port'] ?? 3306, $params['dbname'] ?? ''), true),
            var_export($params['user'] ?? 'root', true),
            var_export($params['password'] ?? '', true),
            var_export(\sprintf("SELECT visit_id FROM visit_lookup WHERE site_id = %d AND visitor_key = UNHEX('%s') FOR UPDATE", $siteId, bin2hex($hashKey)), true),
            self::POLL_ATTEMPTS,
            var_export(\sprintf(
                "INSERT IGNORE INTO visit_lookup (site_id, visitor_key, visit_id, visit_day, last_activity_at, source_key) VALUES (%d, UNHEX('%s'), 0, '%s', '%s', NULL)",
                $siteId,
                bin2hex($sessionKey),
                $day,
                $timestamp,
            ), true),
            var_export(\sprintf("UPDATE visit_lookup SET visit_id = %d, visit_day = '%s', last_activity_at = '%s' WHERE %s", $visitId, $day, $timestamp, $session), true),
        );
        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes, 'line' => trim((string) fgets($pipes[1]))];
    }

    /** @param array{process: resource, pipes: array<int, resource>, line: string} $racer */
    private function finishRacer(array $racer): string
    {
        $line = trim((string) fgets($racer['pipes'][1]));
        $errors = trim((string) stream_get_contents($racer['pipes'][2]));
        foreach ($racer['pipes'] as $pipe) {
            fclose($pipe);
        }
        self::assertSame(0, proc_close($racer['process']), 'the racing process failed: ' . $errors);

        return $line;
    }
}
