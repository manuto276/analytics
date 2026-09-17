<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Tracking;

use Analytics\Tracking\Application\EventDraft;
use Analytics\Tracking\Domain\Channel;
use Analytics\Tracking\Domain\ConsentStatKind;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;
use PHPUnit\Framework\TestCase;

final class EventDraftTest extends TestCase
{
    private static function draft(): EventDraft
    {
        return new EventDraft(
            siteId: 7,
            uid: random_bytes(12),
            type: EventType::Custom,
            level: TrackingLevel::Consented,
            name: 'signup_click',
            occurredAt: new \DateTimeImmutable('2026-09-17 10:00:00.250', new \DateTimeZone('UTC')),
            receivedAt: new \DateTimeImmutable('2026-09-17 10:00:01.000', new \DateTimeZone('UTC')),
            localDay: '2026-09-17',
            visitorHash: 1234567890,
            hashKey: random_bytes(16),
            sessionKey: random_bytes(16),
            visitorId: random_bytes(16),
            host: 'www.example.com',
            path: '/pricing',
            pageHash: random_bytes(8),
            query: 'utm_source=x',
            channel: Channel::PaidSearch,
            source: 'Google',
            referrerHost: 'www.google.com',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'brand',
            utmContent: 'ad1',
            utmTerm: 'analytics',
            sourceKey: random_bytes(8),
            browser: 'Chrome',
            browserMajor: 126,
            os: 'Windows',
            osMajor: 10,
            device: 'desktop',
            country: 'IT',
            contentKey: 'author:42',
            engagedMs: 12000,
            scrollPct: 80,
            props: ['plan' => 'pro', 'count' => 2, 'flag' => true, 'ratio' => 1.5],
            consentVersion: 3,
            consentStat: null,
            consentReceipt: true,
        );
    }

    public function testSurvivesTheQueueRoundTrip(): void
    {
        $draft = self::draft();
        $json = json_encode($draft->toArray(), \JSON_THROW_ON_ERROR);
        $restored = EventDraft::fromArray((array) json_decode($json, true, 512, \JSON_THROW_ON_ERROR));

        self::assertEquals($draft, $restored);
        self::assertSame($draft->sessionKey, $restored->visitKey(), 'consented events are keyed by session');
    }

    public function testBaseLevelDraftsAreKeyedByTheDailyHash(): void
    {
        $draft = self::draft();
        $base = new EventDraft(
            $draft->siteId,
            $draft->uid,
            EventType::Pageview,
            TrackingLevel::Base,
            null,
            $draft->occurredAt,
            $draft->receivedAt,
            $draft->localDay,
            $draft->visitorHash,
            $draft->hashKey,
            null,
            null,
            $draft->host,
            $draft->path,
            $draft->pageHash,
            null,
            Channel::Direct,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $draft->browser,
            $draft->browserMajor,
            $draft->os,
            $draft->osMajor,
            $draft->device,
            null,
            null,
            null,
            null,
            [],
            0,
            ConsentStatKind::Shown,
            false,
        );
        self::assertSame($draft->hashKey, $base->visitKey());
        $restored = EventDraft::fromArray($base->toArray());
        self::assertEquals($base, $restored);
        self::assertSame(ConsentStatKind::Shown, $restored->consentStat);
    }

    public function testMalformedPayloadsAreRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        EventDraft::fromArray(['site' => 'not an id']);
    }

    public function testConsentStatKindsMapToCounterColumns(): void
    {
        self::assertSame(
            ['shown', 'accepted', 'rejected', 'dismissed', 'reopened'],
            array_map(static fn(ConsentStatKind $kind): string => $kind->column(), ConsentStatKind::cases()),
        );
    }
}
