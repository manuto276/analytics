<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Tracking;

use Analytics\Kernel\Settings;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

/**
 * Invariant: the full IP address and the full User-Agent are never stored (database, logs).
 */
final class PrivacyInvariantsTest extends HttpTestCase
{
    private const string UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 PrivacyProbe/7.3.1';
    private const string IPV4 = '198.51.100.123';
    private const string IPV6 = '2001:db8:1234:5678:9abc:def0:1234:5678';
    private const string PROXIED = '203.0.113.211';

    public function testNoFullAddressOrUserAgentAnywhere(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true, 'consentReceiptsEnabled' => true]);
        $admin = $this->factory->admin();
        $headers = ['User-Agent' => self::UA];

        $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/?utm_source=x'), Payloads::event('click', ['k' => 'v']), Payloads::consentStat('shown')]), $headers, self::IPV4);
        $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview(), Payloads::consentUpgrade('https://www.site.test/')], 'c', ['vid' => Payloads::id22(), 'sid' => Payloads::id22()]), $headers, self::IPV6);
        $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/proxied')]), $headers + ['X-Forwarded-For' => self::PROXIED], '10.1.2.3');
        $this->request('POST', '/t/e', 'invalid json', ['Origin' => 'https://www.site.test', 'User-Agent' => self::UA], ['REMOTE_ADDR' => self::IPV4]);
        // Dashboard activity also writes sessions and audit rows.
        $this->request('POST', '/api/v1/auth/login', ['email' => $admin->email, 'password' => 'wrong password'], ['User-Agent' => self::UA], ['REMOTE_ADDR' => self::IPV4]);
        $this->request('POST', '/api/v1/auth/login', ['email' => $admin->email, 'password' => \Analytics\Tests\Support\Factory::PASSWORD], ['User-Agent' => self::UA], ['REMOTE_ADDR' => self::IPV6]);

        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw'));
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM auth_sessions'));
        self::assertSame('198.51.100.0/24', $this->db->fetchOne("SELECT ip_prefix FROM audit_log WHERE action = 'auth.login_failed' ORDER BY id DESC LIMIT 1"));
        self::assertSame('2001:db8:1234::/48', $this->db->fetchOne('SELECT ip_prefix FROM auth_sessions ORDER BY created_at DESC LIMIT 1'));

        $needles = [
            self::IPV4,
            self::IPV6,
            self::PROXIED,
            '2001:db8:1234:5678',
            'PrivacyProbe',
            self::UA,
        ];
        $binaryNeedles = [inet_pton(self::IPV4), inet_pton(self::IPV6), inet_pton(self::PROXIED)];

        $tables = $this->db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
        $scanned = 0;
        foreach ($tables as $table) {
            $columns = $this->db->fetchFirstColumn('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            foreach ($this->db->fetchAllAssociative('SELECT * FROM `' . $table . '`') as $row) {
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $value = (string) $value;
                    ++$scanned;
                    foreach ($needles as $needle) {
                        self::assertStringNotContainsString($needle, $value, \sprintf('%s.%s contains "%s"', $table, $column, $needle));
                    }
                    foreach ($binaryNeedles as $needle) {
                        self::assertIsString($needle);
                        self::assertStringNotContainsString($needle, $value, \sprintf('%s.%s contains a packed full address', $table, $column));
                    }
                }
            }
        }
        self::assertGreaterThan(50, $scanned);

        $settings = $this->service(Settings::class);
        foreach (glob($settings->logDir . '/*.log') ?: [] as $log) {
            $content = (string) file_get_contents($log);
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $content, basename($log) . ' contains ' . $needle);
            }
        }
    }
}
