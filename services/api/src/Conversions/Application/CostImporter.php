<?php

declare(strict_types=1);

namespace Analytics\Conversions\Application;

use Analytics\Conversions\Domain\CampaignCost;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CSV import of campaign costs with a dry-run preview. Nothing is imported unless every row is valid.
 */
final readonly class CostImporter
{
    public const array COLUMNS = ['day_from', 'day_to', 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'amount', 'currency', 'note'];
    public const int MAX_ROWS = 5000;
    /** Currencies whose minor unit is not 1/100. */
    private const array EXPONENTS = ['JPY' => 0, 'KRW' => 0, 'ISK' => 0, 'CLP' => 0, 'VND' => 0, 'BHD' => 3, 'KWD' => 3, 'TND' => 3, 'JOD' => 3, 'OMR' => 3];

    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function import(SiteSnapshot $site, string $csv, bool $dryRun, ?int $userId): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $lines = $lines === false ? [] : $lines;
        if ($lines === [] || $lines === ['']) {
            throw ApiProblem::validation(['csv' => ['The file is empty.']]);
        }
        $header = str_getcsv(Types::string(array_shift($lines)), ',', '"', '\\');
        $header = array_map(static fn(?string $value): string => strtolower(trim((string) $value)), $header);
        foreach (['day_from', 'amount', 'currency'] as $required) {
            if (!\in_array($required, $header, true)) {
                throw ApiProblem::validation(['csv' => ['The header row must contain at least day_from, amount and currency (allowed columns: ' . implode(', ', self::COLUMNS) . ').']]);
            }
        }
        if (\count($lines) > self::MAX_ROWS) {
            throw ApiProblem::validation(['csv' => ['At most ' . self::MAX_ROWS . ' rows.']]);
        }

        $rows = [];
        $valid = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $record = [];
            foreach ($header as $position => $column) {
                $record[$column] = isset($values[$position]) ? trim(Types::string($values[$position])) : '';
            }
            $parsed = $this->parseRow($record, $site, $index + 2);
            $rows[] = $parsed;
            if ($parsed['errors'] === []) {
                ++$valid;
            }
        }

        $invalid = \count($rows) - $valid;
        $batchId = null;
        $imported = false;
        if (!$dryRun && $invalid === 0 && $rows !== []) {
            $batchId = Uuid::v7()->toRfc4122();
            foreach ($rows as $row) {
                $this->em->persist(new CampaignCost(
                    siteId: $site->id,
                    dayFrom: new \DateTimeImmutable(Types::string($row['day_from'])),
                    dayTo: new \DateTimeImmutable(Types::string($row['day_to'])),
                    channel: self::nullable($row['channel']),
                    utmSource: self::nullable($row['utm_source']),
                    utmMedium: self::nullable($row['utm_medium']),
                    utmCampaign: self::nullable($row['utm_campaign']),
                    amountMinor: Types::string($row['amount_minor']),
                    currency: Types::string($row['currency']),
                    note: self::nullable($row['note']),
                    importBatchId: $batchId,
                    createdBy: $userId,
                    createdAt: $this->clock->now(),
                ));
            }
            $this->em->flush();
            $imported = true;
        }

        return [
            'dry_run' => $dryRun,
            'imported' => $imported,
            'batch_id' => $batchId,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, string> $record
     *
     * @return array<string, mixed>
     */
    private function parseRow(array $record, SiteSnapshot $site, int $line): array
    {
        $errors = [];
        $dayFrom = $record['day_from'] ?? '';
        $dayTo = ($record['day_to'] ?? '') !== '' ? $record['day_to'] : $dayFrom;
        foreach (['day_from' => $dayFrom, 'day_to' => $dayTo] as $name => $day) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
                $errors[] = $name . ' must be a date (YYYY-MM-DD).';
            }
        }
        if ($errors === [] && $dayTo < $dayFrom) {
            $errors[] = 'day_to must not be before day_from.';
        }
        $currency = strtoupper($record['currency'] ?? '');
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors[] = 'currency must be a three-letter ISO 4217 code.';
            $currency = $site->currency;
        }
        $amountRaw = str_replace([' ', ','], ['', '.'], $record['amount'] ?? '');
        $amountMinor = null;
        if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
            $errors[] = 'amount must be a non-negative decimal number in major units.';
        } else {
            $exponent = self::EXPONENTS[$currency] ?? 2;
            $amountMinor = (int) round((float) $amountRaw * 10 ** $exponent);
        }
        foreach (['channel' => 32, 'utm_source' => 100, 'utm_medium' => 100, 'utm_campaign' => 100, 'note' => 255] as $field => $max) {
            if (mb_strlen($record[$field] ?? '') > $max) {
                $errors[] = $field . ' must be at most ' . $max . ' characters.';
            }
        }

        return [
            'line' => $line,
            'day_from' => $dayFrom,
            'day_to' => $dayTo,
            'channel' => self::nullable($record['channel'] ?? ''),
            'utm_source' => self::nullable($record['utm_source'] ?? ''),
            'utm_medium' => self::nullable($record['utm_medium'] ?? ''),
            'utm_campaign' => self::nullable($record['utm_campaign'] ?? ''),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'note' => self::nullable($record['note'] ?? ''),
            'errors' => $errors,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
