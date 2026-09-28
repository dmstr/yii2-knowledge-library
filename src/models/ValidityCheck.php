<?php

namespace dmstr\knowledgeLibrary\models;

use DateTimeImmutable;
use Yii;

/**
 * Checks the validity period of a new version and describes the
 * consequences of publishing it, without writing anything.
 *
 * The check follows the rules of Version::validateValidityPeriod() and uses
 * the same messages, but reports only the first error, as the validity step
 * of the version wizard shows one error at a time:
 *
 * - "Valid From" missing or not a date in the format `Y-m-d`
 * - "Valid Until" not a date in the format `Y-m-d`
 * - "Valid Until" before "Valid From"
 * - "Valid From" not after the start of the predecessor (retroactive),
 *   unless the version is a correction
 * - any date for a type without validity period
 *
 * The predecessor is the published version of the item with the highest
 * number, see Version::getPredecessor().
 *
 * getPreview() returns the timeline data of the item as it would look after
 * publishing, in the format of timeline(); see ValidityTimeline.
 */
class ValidityCheck
{
    public const STATE_IN_FORCE = 'kraft';
    public const STATE_HISTORICAL = 'hist';
    public const STATE_UPCOMING = 'bev';
    public const STATE_WITHDRAWN = 'zur';
    public const STATE_NEW = 'neu';

    /**
     * First and last year the timeline shows at least.
     */
    public const MIN_FIRST_YEAR = 2024;
    public const MIN_LAST_YEAR = 2028;

    private const DATE_FORMAT = 'Y-m-d';

    private Version $version;

    private string $today;

    private ?Version $predecessor;

    private bool $hasValidityPeriod;

    /**
     * @var string[]
     */
    private array $errors = [];

    /**
     * @var string[]
     */
    private array $consequences = [];

    /**
     * Effective start of the new version (`Y-m-d`), null if invalid.
     */
    private ?string $validFrom = null;

    /**
     * Effective end of the new version (`Y-m-d`), null if open-ended or
     * invalid.
     */
    private ?string $validUntil = null;

    /**
     * @param Version $version the new version with the entered dates, saved
     * or not; its item must exist
     * @param string|null $today date in the format `Y-m-d`, today if null
     */
    public function __construct(Version $version, ?string $today = null)
    {
        $this->version = $version;
        $this->today = $today ?? date(self::DATE_FORMAT);
        $this->predecessor = $version->getPredecessor();
        $type = $version->item instanceof Item ? $version->item->type : null;
        $this->hasValidityPeriod = $type === null || (bool)$type->has_validity_period;

        if ($this->hasValidityPeriod) {
            $this->checkPeriod();
        } else {
            $this->checkWithoutPeriod();
        }
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return string[] error messages, at most one
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[] consequences of publishing, empty if invalid
     */
    public function getConsequences(): array
    {
        return $this->consequences;
    }

    public function getPredecessor(): ?Version
    {
        return $this->predecessor;
    }

    /**
     * Start of the new version (`Y-m-d`): the entered date, today for types
     * without validity period, null if invalid.
     */
    public function getValidFrom(): ?string
    {
        return $this->validFrom;
    }

    /**
     * End of the new version (`Y-m-d`), null if open-ended or invalid.
     */
    public function getValidUntil(): ?string
    {
        return $this->validUntil;
    }

    /**
     * Timeline data of the item after publishing the version: the
     * predecessor ends the day before the new version starts (marked with
     * `hl`), the new version is added with state `neu`. Without the new
     * version if the check failed.
     *
     * @return array{y0: int, y1: int, v: array<int, array{n: int, a: string, b: string|null, s: string, hl: bool, lane: int}>}
     */
    public function getPreview(): array
    {
        $item = $this->version->item;
        $bars = $item instanceof Item ? static::bars($item, $this->today, $this->version->id) : [];

        if ($this->isValid()) {
            $predecessorEnd = $this->shiftDate($this->validFrom, -1);
            foreach ($bars as &$bar) {
                if (
                    $this->predecessor !== null
                    && $bar['n'] === (int)$this->predecessor->number
                    && $bar['a'] < $this->validFrom
                    && ($bar['b'] === null || $bar['b'] >= $this->validFrom)
                ) {
                    $bar['b'] = $predecessorEnd;
                    $bar['hl'] = true;
                }
            }
            unset($bar);

            $bars[] = [
                'n' => $this->getNumber(),
                'a' => $this->validFrom,
                'b' => $this->validUntil,
                's' => self::STATE_NEW,
                'hl' => false,
                'lane' => 0,
            ];
        }

        return static::frame($bars);
    }

    /**
     * Timeline data of the item: all published and withdrawn versions,
     * drafts and versions in review are left out.
     *
     * `a` and `b` are dates in the format `Y-m-d` (`b` null if open-ended),
     * `s` the state at the given date (`kraft`, `hist`, `bev`, `zur`), `lane`
     * 1 for withdrawn versions, 0 otherwise. `y0` and `y1` are the first and
     * last year of the axis: `min(2024, years)` and `max(2028, years + 2)`.
     *
     * For types without validity period a version is shown from its
     * publication until the day before the next publication.
     *
     * @param string|null $today date in the format `Y-m-d`, today if null
     *
     * @return array{y0: int, y1: int, v: array<int, array{n: int, a: string, b: string|null, s: string, hl: bool, lane: int}>}
     */
    public static function timeline(Item $item, ?string $today = null): array
    {
        return static::frame(static::bars($item, $today ?? date(self::DATE_FORMAT)));
    }

    /**
     * Message for a missing or invalid "Valid From" or "Valid Until".
     */
    public static function invalidDateMessage(string $attribute): string
    {
        return $attribute === 'valid_until'
            ? Yii::t('knowledge-library', 'Enter "Valid Until" in the format DD.MM.YYYY.')
            : Yii::t('knowledge-library', 'Enter "Valid From" in the format DD.MM.YYYY.');
    }

    public static function untilBeforeFromMessage(): string
    {
        return Yii::t('knowledge-library', 'Valid Until must not be before Valid From.');
    }

    /**
     * Message for a start date not after the start of the predecessor.
     */
    public static function retroactiveMessage(Version $predecessor): string
    {
        return Yii::t(
            'knowledge-library',
            'The date is before the start of version {number} ({date}). Retroactive changes are only possible with "Correct".',
            [
                'number' => (int)$predecessor->number,
                'date' => Yii::$app->formatter->asDate($predecessor->valid_from),
            ]
        );
    }

    public static function noValidityPeriodMessage(): string
    {
        return Yii::t('knowledge-library', 'The type of this item has no validity period, leave the date empty.');
    }

    /**
     * Whether the value is a date in the format `Y-m-d`.
     */
    public static function isDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
    }

    private function checkPeriod(): void
    {
        $validFrom = $this->normalize($this->version->valid_from);
        $validUntil = $this->normalize($this->version->valid_until);

        if ($validFrom === null || !static::isDate($validFrom)) {
            $this->errors[] = static::invalidDateMessage('valid_from');
        } elseif ($validUntil !== null && !static::isDate($validUntil)) {
            $this->errors[] = static::invalidDateMessage('valid_until');
        } elseif ($validUntil !== null && $validUntil < $validFrom) {
            $this->errors[] = static::untilBeforeFromMessage();
        } elseif (
            $this->predecessor !== null
            && empty($this->version->corrects_version_id)
            && $this->predecessor->valid_from !== null
            && $validFrom <= $this->predecessor->valid_from
        ) {
            $this->errors[] = static::retroactiveMessage($this->predecessor);
        }

        if (!$this->isValid()) {
            return;
        }

        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
        $formatter = Yii::$app->formatter;

        $predecessor = $this->predecessor;
        if ($predecessor !== null && $predecessor->valid_from !== null) {
            if ($predecessor->valid_until === null || $predecessor->valid_until >= $validFrom) {
                $this->consequences[] = Yii::t('knowledge-library', 'Version {number} ends on {date}.', [
                    'number' => (int)$predecessor->number,
                    'date' => $formatter->asDate($this->shiftDate($validFrom, -1)),
                ]);
            } elseif ($this->shiftDate($predecessor->valid_until, 1) < $validFrom) {
                $this->consequences[] = Yii::t('knowledge-library', 'From {from} to {until} no version is valid.', [
                    'from' => $formatter->asDate($this->shiftDate($predecessor->valid_until, 1)),
                    'until' => $formatter->asDate($this->shiftDate($validFrom, -1)),
                ]);
            }
        }

        $this->consequences[] = $validUntil === null
            ? Yii::t('knowledge-library', 'Version {number} is valid from {from}, open-ended.', [
                'number' => $this->getNumber(),
                'from' => $formatter->asDate($validFrom),
            ])
            : Yii::t('knowledge-library', 'Version {number} is valid from {from} until {until}.', [
                'number' => $this->getNumber(),
                'from' => $formatter->asDate($validFrom),
                'until' => $formatter->asDate($validUntil),
            ]);
    }

    private function checkWithoutPeriod(): void
    {
        if (
            $this->normalize($this->version->valid_from) !== null
            || $this->normalize($this->version->valid_until) !== null
        ) {
            $this->errors[] = static::noValidityPeriodMessage();

            return;
        }

        $this->validFrom = $this->today;

        if ($this->predecessor !== null) {
            $this->consequences[] = Yii::t(
                'knowledge-library',
                'Version {number} replaces version {predecessor} upon publication.',
                ['number' => $this->getNumber(), 'predecessor' => (int)$this->predecessor->number]
            );
        }
        $this->consequences[] = Yii::t(
            'knowledge-library',
            'Version {number} is valid from publication, open-ended.',
            ['number' => $this->getNumber()]
        );
    }

    /**
     * Number of the version; for an unsaved version the number it gets.
     */
    private function getNumber(): int
    {
        if ($this->version->number !== null) {
            return (int)$this->version->number;
        }

        return (int)Version::find()->forItem($this->version->item_id)->max('number') + 1;
    }

    /**
     * Empty strings count as no date.
     */
    private function normalize($value)
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : $value;
    }

    private function shiftDate(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(sprintf('%+d day', $days))->format(self::DATE_FORMAT);
    }

    /**
     * Timeline bars of the published and withdrawn versions of the item,
     * ordered by number.
     *
     * @return array<int, array{n: int, a: string, b: string|null, s: string, hl: bool, lane: int}>
     */
    private static function bars(Item $item, string $today, ?string $excludeId = null): array
    {
        $query = Version::find()
            ->forItem($item->id)
            ->andWhere(['status' => [Version::STATUS_PUBLISHED, Version::STATUS_WITHDRAWN]])
            ->orderBy(['number' => SORT_ASC]);
        if ($excludeId !== null) {
            $query->andWhere(['not', ['id' => $excludeId]]);
        }
        /** @var Version[] $versions */
        $versions = $query->all();

        $type = $item->type;
        $hasValidityPeriod = $type === null || (bool)$type->has_validity_period;
        $states = [
            Version::STATE_IN_FORCE => self::STATE_IN_FORCE,
            Version::STATE_HISTORICAL => self::STATE_HISTORICAL,
            Version::STATE_UPCOMING => self::STATE_UPCOMING,
        ];

        $bars = [];
        foreach ($versions as $index => $version) {
            $version->populateRelation('item', $item);
            if ($hasValidityPeriod) {
                $from = $version->valid_from;
                $until = $version->valid_until;
            } else {
                [$from, $until] = static::publicationPeriod($versions, $index);
            }
            if ($from === null) {
                continue;
            }
            if ($until !== null && $until < $from) {
                $until = $from;
            }

            $withdrawn = $version->status === Version::STATUS_WITHDRAWN;
            $bars[] = [
                'n' => (int)$version->number,
                'a' => $from,
                'b' => $until,
                's' => $withdrawn
                    ? self::STATE_WITHDRAWN
                    : $states[$version->getEffectiveState($today)] ?? self::STATE_HISTORICAL,
                'hl' => false,
                'lane' => $withdrawn ? 1 : 0,
            ];
        }

        return $bars;
    }

    /**
     * Period of a version of a type without validity period: from its
     * publication until the day before the next published version was
     * published (withdrawn versions: until withdrawal).
     *
     * @param Version[] $versions ordered by number
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function publicationPeriod(array $versions, int $index): array
    {
        $version = $versions[$index];
        $from = $version->published_at !== null ? substr($version->published_at, 0, 10) : null;

        if ($version->status === Version::STATUS_WITHDRAWN) {
            return [$from, $version->withdrawn_at !== null ? substr($version->withdrawn_at, 0, 10) : null];
        }

        for ($next = $index + 1, $count = count($versions); $next < $count; $next++) {
            $following = $versions[$next];
            if ($following->status === Version::STATUS_PUBLISHED && $following->published_at !== null) {
                $until = (new DateTimeImmutable(substr($following->published_at, 0, 10)))
                    ->modify('-1 day')
                    ->format(self::DATE_FORMAT);

                return [$from, $until];
            }
        }

        return [$from, null];
    }

    /**
     * Adds the year range to the bars.
     *
     * @param array<int, array{n: int, a: string, b: string|null, s: string, hl: bool, lane: int}> $bars
     */
    private static function frame(array $bars): array
    {
        $years = [];
        foreach ($bars as $bar) {
            foreach (['a', 'b'] as $key) {
                if ($bar[$key] !== null) {
                    $years[] = (int)substr($bar[$key], 0, 4);
                }
            }
        }

        return [
            'y0' => min(self::MIN_FIRST_YEAR, ...($years ?: [self::MIN_FIRST_YEAR])),
            'y1' => max(self::MIN_LAST_YEAR, ...array_map(static fn (int $year) => $year + 2, $years ?: [0])),
            'v' => array_values($bars),
        ];
    }
}
