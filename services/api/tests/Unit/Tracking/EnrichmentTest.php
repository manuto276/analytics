<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Tracking;

use Analytics\Shared\Net\IpTruncator;
use Analytics\Tracking\Application\Enrichment\BotFilter;
use Analytics\Tracking\Application\Enrichment\ChannelClassifier;
use Analytics\Tracking\Application\Enrichment\PiiScrubber;
use Analytics\Tracking\Application\Enrichment\ReferrerClassifier;
use Analytics\Tracking\Application\Enrichment\UrlSanitizer;
use Analytics\Tracking\Application\Enrichment\UserAgentClassifier;
use Analytics\Tracking\Application\Payload\PayloadParser;
use Analytics\Tracking\Application\VisitorHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnrichmentTest extends TestCase
{
    private static function referrers(): ReferrerClassifier
    {
        return new ReferrerClassifier(\dirname(__DIR__, 3) . '/resources/referrers');
    }

    public function testUrlSanitizer(): void
    {
        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'ref', 'page'];
        $result = UrlSanitizer::sanitize('https://WWW.Site.test/Shop/%7Eitem?page=2&gclid=abc&utm_source=News&email=a@b.com&fbclid=x#section', $allowed, false);
        self::assertNotNull($result);
        self::assertSame('www.site.test', $result['host']);
        self::assertSame('/Shop/~item', $result['path']);
        self::assertSame('page=2&utm_source=News', $result['query']);
        self::assertSame('abc', $result['params']['gclid']);

        $hash = UrlSanitizer::sanitize('https://app.site.test/#/orders/123?x=1', [], true);
        self::assertNotNull($hash);
        self::assertSame('/#/orders/123', $hash['path']);
        $noHash = UrlSanitizer::sanitize('https://app.site.test/#/orders/123', [], false);
        self::assertNotNull($noHash);
        self::assertSame('/', $noHash['path']);

        $pii = UrlSanitizer::sanitize('https://www.site.test/users/mario.rossi@example.com/orders/123456789012?ref=+39 333 123 4567', ['ref'], false);
        self::assertNotNull($pii);
        self::assertSame('/users/[email]/orders/[number]', $pii['path']);
        self::assertSame('ref=%5Bphone%5D', $pii['query']);

        self::assertNull(UrlSanitizer::sanitize('not a url', [], false));
        self::assertSame(8, \strlen(UrlSanitizer::pageHash('www.site.test', '/')));
        self::assertNotSame(UrlSanitizer::pageHash('a.test', '/x'), UrlSanitizer::pageHash('b.test', '/x'));
    }

    public function testPiiScrubber(): void
    {
        self::assertSame('contact [email] now', PiiScrubber::scrub('contact someone%40example.co.uk now'));
        self::assertSame('call [phone]', PiiScrubber::scrub('call +39 (02) 1234-5678'));
        self::assertSame('order [number]', PiiScrubber::scrub('order 1234567890123'));
        self::assertSame('/2026/09/17/post-12345', PiiScrubber::scrubPath('/2026/09/17/post-12345'));
    }

    /**
     * A property key is validated at ingest and scrubbed afterwards, and the substitution is not
     * bound by the length of what it replaces. Whatever comes out has to fit
     * `rollup_events_daily.prop_key`, or that key fails the whole (site, day) rollup for ever.
     */
    public function testPropKeysComeOutOfTheScrubberStorable(): void
    {
        // The ingest limit is also the width of the column that stores the key.
        $max = PayloadParser::MAX_PROP_KEY;

        // Rewritten and still storable: the brackets are data to the JSON path the rollup builds.
        self::assertSame('user[number]', PiiScrubber::scrubPropKey('user1234567890', $max));
        self::assertSame('plan', PiiScrubber::scrubPropKey('plan', $max));
        self::assertLessThanOrEqual($max, \strlen((string) PiiScrubber::scrubPropKey(str_repeat('9', $max), $max)));

        // A substitution can be longer than what it replaces: 6 characters in, 7 out.
        self::assertSame('[email]', PiiScrubber::scrub('a@b.co'));
        self::assertNull(PiiScrubber::scrubPropKey('a@b.co', 6), 'a key that grew past the limit cannot be stored');
        self::assertNull(PiiScrubber::scrubPropKey('order1234567890', 8));
        self::assertNull(PiiScrubber::scrubPropKey(str_repeat('k', $max + 1), $max));
        // The empty key is the rollup's "no property" marker row, so it can never be a real key.
        self::assertNull(PiiScrubber::scrubPropKey('', $max));
    }

    /** @return iterable<string, array{string, ?string, string, ?string}> */
    public static function channelCases(): iterable
    {
        // [landing query, referrer, expected channel, expected source]
        yield 'direct' => ['', null, 'direct', null];
        yield 'internal referrer' => ['', 'https://app.site.test/x', 'internal', null];
        yield 'google organic' => ['', 'https://www.google.it/', 'organic_search', 'Google'];
        yield 'bing organic' => ['', 'https://www.bing.com/search?q=x', 'organic_search', 'Bing'];
        yield 'duckduckgo' => ['', 'https://duckduckgo.com/', 'organic_search', 'DuckDuckGo'];
        yield 'google ads gclid' => ['gclid=abc', 'https://www.google.com/', 'paid_search', 'Google'];
        yield 'utm cpc google' => ['utm_source=google&utm_medium=cpc&utm_campaign=brand', null, 'paid_search', 'Google'];
        yield 'utm cpc facebook' => ['utm_source=facebook&utm_medium=cpc', null, 'paid_social', 'Facebook'];
        yield 'utm paid_social' => ['utm_source=linkedin&utm_medium=paid_social', null, 'paid_social', 'LinkedIn'];
        yield 'facebook organic' => ['', 'https://l.facebook.com/', 'organic_social', 'Facebook'];
        yield 'fbclid only' => ['fbclid=x', null, 'organic_social', null];
        yield 'twitter t.co' => ['', 'https://t.co/abc', 'organic_social', 'X'];
        yield 'youtube' => ['', 'https://www.youtube.com/watch?v=1', 'organic_social', 'YouTube'];
        yield 'email utm' => ['utm_source=newsletter&utm_medium=email&utm_campaign=sept', null, 'email', 'newsletter'];
        yield 'gmail referrer' => ['', 'https://mail.google.com/', 'email', 'Gmail'];
        yield 'social utm medium' => ['utm_source=instagram&utm_medium=social', null, 'organic_social', 'Instagram'];
        yield 'generic campaign' => ['utm_source=partner&utm_campaign=spring', null, 'campaign', 'partner'];
        yield 'ref param' => ['ref=producthunt', null, 'organic_social', 'Product Hunt'];
        yield 'referral' => ['', 'https://blog.example.org/post', 'referral', 'blog.example.org'];
        yield 'chatgpt referral' => ['', 'https://chatgpt.com/', 'referral', 'ChatGPT'];
        yield 'organic medium' => ['utm_source=google&utm_medium=organic', null, 'organic_search', 'Google'];
    }

    #[DataProvider('channelCases')]
    public function testChannelClassification(string $query, ?string $referrer, string $channel, ?string $source): void
    {
        parse_str($query, $params);
        /** @var array<string, string> $params */
        $result = new ChannelClassifier(self::referrers())->classify($params, $referrer, static fn(string $h): bool => str_ends_with($h, 'site.test'));
        self::assertSame($channel, $result->channel->value);
        self::assertSame($source, $result->source);
        if ($result->channel->isExternal()) {
            self::assertNotNull($result->key());
        } else {
            self::assertNull($result->key());
        }
    }

    public function testUtmValuesAreNormalisedAndScrubbed(): void
    {
        $result = new ChannelClassifier(self::referrers())->classify(['utm_source' => ' NewsLetter ', 'utm_medium' => 'EMAIL', 'utm_campaign' => 'Promo for a@b.com'], null, static fn(): bool => false);
        self::assertSame('newsletter', $result->utmSource);
        self::assertSame('email', $result->utmMedium);
        self::assertSame('Promo for [email]', $result->utmCampaign);
    }

    public function testUserAgentClassifier(): void
    {
        $classifier = new UserAgentClassifier();
        $chrome = $classifier->classify('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');
        self::assertFalse($chrome->isBot);
        self::assertSame('Chrome', $chrome->browser);
        self::assertSame(126, $chrome->browserMajor);
        self::assertSame('Windows', $chrome->os);
        self::assertSame('desktop', $chrome->device);

        $iphone = $classifier->classify('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');
        self::assertSame('mobile', $iphone->device);
        self::assertSame('iOS', $iphone->os);
        self::assertSame(17, $iphone->osMajor);

        $ipad = $classifier->classify('Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');
        self::assertSame('tablet', $ipad->device);

        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'python-requests/2.31', '', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'HeadlessChrome/120.0'] as $bot) {
            self::assertTrue($classifier->classify($bot)->isBot, 'Expected bot: ' . $bot);
        }
    }

    public function testExcludedPaths(): void
    {
        self::assertTrue(BotFilter::isExcludedPath('/admin', ['/admin']));
        self::assertTrue(BotFilter::isExcludedPath('/admin/users', ['/admin']));
        self::assertTrue(BotFilter::isExcludedPath('/wp-admin/x.php', ['/wp-*']));
        self::assertFalse(BotFilter::isExcludedPath('/administration', ['/admin']));
    }

    public function testVisitorHasher(): void
    {
        $ip = IpTruncator::truncate('203.0.113.7');
        $sameNetwork = IpTruncator::truncate('203.0.113.200');
        $salt = random_bytes(32);
        $a = VisitorHasher::hash(1, $ip, 'UA', $salt);
        self::assertSame(16, \strlen($a));
        self::assertSame($a, VisitorHasher::hash(1, $sameNetwork, 'UA', $salt), 'hash uses only the shortened address');
        self::assertNotSame($a, VisitorHasher::hash(2, $ip, 'UA', $salt), 'per-site');
        self::assertNotSame($a, VisitorHasher::hash(1, $ip, 'UA2', $salt));
        self::assertNotSame($a, VisitorHasher::hash(1, $ip, 'UA', random_bytes(32)), 'salt rotation unlinks days');
        self::assertGreaterThanOrEqual(0, VisitorHasher::toInt($a));
        self::assertSame(VisitorHasher::toInt($a), VisitorHasher::toInt($a));
    }
}
