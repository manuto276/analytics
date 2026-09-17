<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

/**
 * An inclusive range of local days in the site's time zone.
 */
final readonly class DateRange
{
    public const array PERIODS = ['today', 'yesterday', '7d', '30d', '90d', 'month', 'last_month', '12mo', 'year', 'custom'];
    public const int MAX_DAYS = 1900;

    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('from must not be after to.');
        }
    }

    public static function fromPeriod(string $period, \DateTimeImmutable $today, ?string $from = null, ?string $to = null): self
    {
        $day = static fn(string $value): \DateTimeImmutable => new \DateTimeImmutable($value . ' 00:00:00', $today->getTimezone());

        return match ($period) {
            'today' => new self($today, $today),
            'yesterday' => new self($today->modify('-1 day'), $today->modify('-1 day')),
            '7d' => new self($today->modify('-6 days'), $today),
            '30d' => new self($today->modify('-29 days'), $today),
            '90d' => new self($today->modify('-89 days'), $today),
            'month' => new self($day($today->format('Y-m-01')), $today),
            'last_month' => new self($day($today->modify('first day of previous month')->format('Y-m-01')), $day($today->modify('first day of previous month')->format('Y-m-t'))),
            '12mo' => new self($day($today->modify('-11 months')->format('Y-m-01')), $today),
            'year' => new self($day($today->format('Y-01-01')), $today),
            'custom' => new self(
                $day($from ?? throw new \InvalidArgumentException('from is required for period=custom')),
                $day($to ?? throw new \InvalidArgumentException('to is required for period=custom')),
            ),
            default => throw new \InvalidArgumentException('Unknown period ' . $period),
        };
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function contains(\DateTimeImmutable $day): bool
    {
        return $day >= $this->from && $day <= $this->to;
    }

    public function fromDay(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDay(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** @return list<string> */
    public function dayList(): array
    {
        $days = [];
        for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    public function compareRange(Comparison $comparison): ?self
    {
        return match ($comparison) {
            Comparison::None => null,
            Comparison::PreviousPeriod => new self($this->from->modify('-' . $this->days() . ' days'), $this->to->modify('-' . $this->days() . ' days')),
            Comparison::PreviousYear => new self($this->from->modify('-1 year'), $this->to->modify('-1 year')),
        };
    }

    public function defaultInterval(): Interval
    {
        return match (true) {
            $this->days() <= 1 => Interval::Hour,
            $this->days() <= 95 => Interval::Day,
            $this->days() <= 400 => Interval::Week,
            default => Interval::Month,
        };
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->fromDay(), 'to' => $this->toDay()];
    }
}
