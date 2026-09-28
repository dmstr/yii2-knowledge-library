<?php

namespace dmstr\knowledgeLibrary\models;

use Yii;
use yii\base\BaseObject;
use yii\db\Query;

/**
 * Current state of a knowledge item as shown in lists, derived from its
 * archive flag and its versions.
 *
 * Precedence: archived, in review, draft (also without any version), no valid
 * version, valid version. The states of a whole page are resolved at once
 * with forItems().
 *
 * @property-read string $state one of the state constants
 * @property-read Version|null $version the version valid at the date
 * @property-read int|null $number number of the valid version
 * @property-read string|null $since date the valid version is valid from
 * @property-read string $label
 * @property-read string|null $sinceLabel
 */
class ItemState extends BaseObject
{
    public const ARCHIVED = 'archived';
    public const IN_REVIEW = 'in_review';
    public const DRAFT = 'draft';
    public const NONE = 'none';
    public const VALID = 'valid';

    private string $_state;
    private ?Version $_version;

    public function __construct(string $state, ?Version $version = null, array $config = [])
    {
        $this->_state = $state;
        $this->_version = $version;

        parent::__construct($config);
    }

    /**
     * Resolves the states of the given items with a fixed number of queries.
     *
     * @param Item[] $items
     * @param string|null $date date in the format `Y-m-d`, today if null
     * @return array<string, ItemState> states indexed by item ID
     */
    public static function forItems(array $items, ?string $date = null): array
    {
        if ($items === []) {
            return [];
        }

        $date ??= date('Y-m-d');
        $ids = array_values(array_unique(array_map(static fn (Item $item) => (string)$item->id, $items)));

        /** @var Version[] $validVersions */
        $validVersions = Version::find()
            ->validAt($date)
            ->andWhere([Version::tableName() . '.[[item_id]]' => $ids])
            ->indexBy('item_id')
            ->all();

        $rows = (new Query())
            ->select(['item_id', 'status'])
            ->from(Version::tableName())
            ->where(['item_id' => $ids])
            ->groupBy(['item_id', 'status'])
            ->all(Version::getDb());

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[$row['item_id']][$row['status']] = true;
        }

        $states = [];
        foreach ($items as $item) {
            $id = (string)$item->id;
            $states[$id] = static::resolve($item, $validVersions[$id] ?? null, $statuses[$id] ?? []);
        }

        return $states;
    }

    /**
     * @param array<string, true> $statuses statuses of all versions of the item
     */
    private static function resolve(Item $item, ?Version $validVersion, array $statuses): self
    {
        if ($item->is_archived) {
            $state = self::ARCHIVED;
        } elseif ($validVersion !== null) {
            $state = self::VALID;
        } elseif (isset($statuses[Version::STATUS_IN_REVIEW])) {
            $state = self::IN_REVIEW;
        } elseif ($statuses === [] || isset($statuses[Version::STATUS_DRAFT])) {
            $state = self::DRAFT;
        } else {
            $state = self::NONE;
        }

        return new static($state, $validVersion);
    }

    public function getState(): string
    {
        return $this->_state;
    }

    public function getVersion(): ?Version
    {
        return $this->_version;
    }

    public function getNumber(): ?int
    {
        return $this->_version === null ? null : (int)$this->_version->number;
    }

    /**
     * Raw date (`Y-m-d`) the valid version is valid from; the publishing date
     * for types without a validity period.
     */
    public function getSince(): ?string
    {
        if ($this->_version === null) {
            return null;
        }

        if ($this->_version->valid_from !== null && $this->_version->valid_from !== '') {
            return $this->_version->valid_from;
        }

        if ($this->_version->published_at !== null && $this->_version->published_at !== '') {
            return substr($this->_version->published_at, 0, 10);
        }

        return null;
    }

    public function getLabel(): string
    {
        return match ($this->_state) {
            self::ARCHIVED => Yii::t('knowledge-library', 'Archived'),
            self::IN_REVIEW => Yii::t('knowledge-library', 'In Review'),
            self::DRAFT => Yii::t('knowledge-library', 'Draft'),
            self::NONE => Yii::t('knowledge-library', 'No valid version'),
            default => Yii::t('knowledge-library', 'Version {number}', ['number' => $this->getNumber()]),
        };
    }

    public function getSinceLabel(): ?string
    {
        $since = $this->getSince();
        if ($this->_state !== self::VALID || $since === null) {
            return null;
        }

        return Yii::t('knowledge-library', 'since {date}', ['date' => Yii::$app->formatter->asDate($since)]);
    }
}
