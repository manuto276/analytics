<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Reporting;

use Analytics\Reporting\Application\CsvExporter;
use Analytics\Reporting\Application\Cursor;
use Analytics\Reporting\Application\DailyMetrics;
use Analytics\Reporting\Application\Reports\TimeseriesReport;
use Analytics\Reporting\Domain\Comparison;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Interval;
use Analytics\Shared\Http\ApiProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    private const string TZ = 'Europe/Rome';

    private static function today(string $day = '2026-09-17'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone(self::TZ));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function periods(): iterable
    {
        yield 'today' => ['today', '2026-09-17', '2026-09-17'];
        yield 'yesterday' => ['yesterday', '2026-09-16', '2026-09-16'];
        yield '7d' => ['7d', '2026-09-11', '2026-09-17'];
        yield '30d' => ['30d', '2026-08-19', '2026-09-17'];
        yield '90d' => ['90d', '2026-06-20', '2026-09-17'];
        yield 'month' => ['month', '2026-09-01', '2026-09-17'];
        yield 'last_month' => ['last_month', '2026-08-01', '2026-08-31'];
        yield '12mo' => ['12mo', '2025-10-01', '2026-09-17'];
        yield 'year' => ['year', '2026-01-01', '2026-09-17'];
    }

    #[DataProvider('periods')]
    public function testPeriods(string $period, string $from, string $to): void
    {
        $range = DateRange::fromPeriod($period, self::today());
        self::assertSame(['from' => $from, 'to' => $to], $range->toArray());
    }

    public function testCustomPeriodNeedsBothEnds(): void
    {
        $range = DateRange::fromPeriod('custom', self::today(), '2026-01-01', '2026-01-31');
        self::assertSame(31, $range->days());

        $this->expectException(\InvalidArgumentException::class);
        DateRange::fromPeriod('custom', self::today(), '2026-01-01');
    }

    public function testRangesAreInclusiveAndOrdered(): void
    {
        self::assertSame(1, new DateRange(self::today(), self::today())->days());
        self::assertCount(7, DateRange::fromPeriod('7d', self::today())->dayList());
        $this->expectException(\InvalidArgumentException::class);
        new DateRange(self::today(), self::today()->modify('-1 day'));
    }

    public function testComparisonRanges(): void
    {
        $range = DateRange::fromPeriod('7d', self::today());
        self::assertNull($range->compareRange(Comparison::None));
        self::assertSame(['from' => '2026-09-04', 'to' => '2026-09-10'], $range->compareRange(Comparison::PreviousPeriod)?->toArray());
        self::assertSame(['from' => '2025-09-11', 'to' => '2025-09-17'], $range->compareRange(Comparison::PreviousYear)?->toArray());
    }

    public function testRangesSpanningDaylightSavingKeepWholeDays(): void
    {
        // Italy moves to winter time on 25 October 2026.
        $range = DateRange::fromPeriod('custom', self::today('2026-10-31'), '2026-10-24', '2026-10-26');
        self::assertSame(3, $range->days());
        self::assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], $range->dayList());
        self::assertTrue($range->contains(new \DateTimeImmutable('2026-10-25 00:00:00', new \DateTimeZone(self::TZ))));

        $spring = DateRange::fromPeriod('custom', self::today('2026-04-01'), '2026-03-28', '2026-03-30');
        self::assertSame(['2026-03-28', '2026-03-29', '2026-03-30'], $spring->dayList());
    }

    public function testDefaultInterval(): void
    {
        self::assertSame(Interval::Hour, DateRange::fromPeriod('today', self::today())->defaultInterval());
        self::assertSame(Interval::Day, DateRange::fromPeriod('30d', self::today())->defaultInterval());
        self::assertSame(Interval::Week, DateRange::fromPeriod('12mo', self::today())->defaultInterval());
        self::assertSame(Interval::Month, DateRange::fromPeriod('custom', self::today(), '2020-01-01', '2026-09-17')->defaultInterval());
    }

    public function testBucketingByWeekAndMonth(): void
    {
        $days = [];
        foreach (['2026-09-14', '2026-09-15', '2026-09-21', '2026-10-01'] as $day) {
            $days[$day] = DailyMetrics::EMPTY;
            $days[$day]['visits'] = 1;
        }
        $weeks = TimeseriesReport::bucket($days, Interval::Week, new \DateTimeZone(self::TZ));
        self::assertSame(['2026-09-14' => 2, '2026-09-21' => 1, '2026-09-28' => 1], array_map(static fn(array $m): int => $m['visits'], $weeks));

        $months = TimeseriesReport::bucket($days, Interval::Month, new \DateTimeZone(self::TZ));
        self::assertSame(['2026-09-01' => 3, '2026-10-01' => 1], array_map(static fn(array $m): int => $m['visits'], $months));
    }

    public function testPresentedMetricsRespectAvailability(): void
    {
        $totals = DailyMetrics::EMPTY;
        $totals['visits'] = 4;
        $totals['pageviews'] = 10;
        $totals['visitors'] = 3;
        $totals['bounces'] = 1;
        $totals['engagement_ms'] = 8000;
        $totals['consented_visits'] = 2;

        $full = DailyMetrics::present($totals, true, true, true);
        self::assertSame(3, $full['visitors']);
        self::assertSame(2.5, $full['views_per_visit']);
        self::assertSame(0.25, $full['bounce_rate']);
        self::assertSame(2000, $full['avg_duration_ms']);
        self::assertSame(0.5, $full['consent_rate']);

        $limited = DailyMetrics::present($totals, false, false, false);
        self::assertNull($limited['visitors']);
        self::assertNull($limited['bounce_rate']);
        self::assertNull($limited['avg_duration_ms']);

        $empty = DailyMetrics::present(DailyMetrics::EMPTY, true, true, true);
        self::assertNull($empty['views_per_visit']);
        self::assertNull($empty['bounce_rate']);
    }

    public function testCursorRoundTripAndRejection(): void
    {
        self::assertSame(0, Cursor::decode(null));
        self::assertSame(0, Cursor::decode(''));
        self::assertSame(250, Cursor::decode(Cursor::encode(250)));
        foreach (['abc', 'bzzz', Cursor::encode(Cursor::MAX_OFFSET + 1)] as $bad) {
            try {
                Cursor::decode($bad);
                self::fail('expected rejection of ' . $bad);
            } catch (ApiProblem $problem) {
                self::assertSame(422, $problem->status);
            }
        }
    }

    public function testCsvExport(): void
    {
        $csv = CsvExporter::fromRows([
            ['path' => '/a', 'pageviews' => 2, 'bounce_rate' => 0.5, 'country' => null, 'flag' => true, 'channels' => ['direct' => 1]],
            ['path' => 'quote"and,comma', 'pageviews' => 1, 'bounce_rate' => null, 'country' => 'IT', 'flag' => false, 'channels' => []],
        ]);
        self::assertStringStartsWith("\u{FEFF}path,pageviews,bounce_rate,country,flag,channels\r\n", $csv);
        self::assertStringContainsString('/a,2,0.5,,true,"{""direct"":1}"', $csv);
        self::assertStringContainsString('"quote""and,comma",1,,IT,false,[]', $csv);
        self::assertSame("\u{FEFF}", CsvExporter::fromRows([]));
    }
}
