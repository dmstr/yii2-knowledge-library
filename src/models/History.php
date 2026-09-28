<?php

namespace dmstr\knowledgeLibrary\models;

use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use dmstr\knowledgeLibrary\users\UserProviderInterface;
use Yii;
use yii\base\Exception;
use yii\db\ActiveQuery;

/**
 * Change history entry of a knowledge item, optionally for one version.
 *
 * `details` holds a JSON object with the facts needed to describe the entry,
 * see describe(). Known keys:
 *
 * - `number`: number of the version the entry is about
 * - `reviewer`: user reference of the (new) reviewer
 * - `previous_reviewer`: user reference of the reviewer before a change
 * - `valid_from`: start of validity of a published version (`Y-m-d`)
 * - `successor`: number of the version that remains valid after a withdrawal
 * - `correction`: number of the version correcting this one
 * - `relation`: relation type, see Relation::labels()
 * - `direction`: `forward` or `inverse`, the perspective of the relation label
 * - `target`: title of the related item
 *
 * @property string $id
 * @property string $item_id
 * @property string|null $version_id
 * @property string $action
 * @property string|null $reason
 * @property string|null $actor_id
 * @property string|null $details JSON object, see getDetails()
 * @property string $created_at
 *
 * @property-read Item|null $item
 * @property-read Version|null $version
 */
class History extends ActiveRecord
{
    public const ACTION_CREATED = 'created';
    public const ACTION_MASTER_DATA_CHANGED = 'master_data_changed';
    public const ACTION_SOURCE_CHANGED = 'source_changed';
    public const ACTION_DRAFT_SAVED = 'draft_saved';
    public const ACTION_DRAFT_DISCARDED = 'draft_discarded';
    public const ACTION_REVIEW_REQUESTED = 'review_requested';
    public const ACTION_REVIEWER_CHANGED = 'reviewer_changed';
    public const ACTION_RETURNED = 'returned';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_PUBLISHED = 'published';
    public const ACTION_WITHDRAWN = 'withdrawn';
    public const ACTION_CORRECTED = 'corrected';
    public const ACTION_ARCHIVED = 'archived';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_RELATION_ADDED = 'relation_added';
    public const ACTION_RELATION_REMOVED = 'relation_removed';

    public static function tableName()
    {
        return '{{%knowledge_library_history}}';
    }

    /**
     * Writes a history entry for the item and optionally one of its versions.
     *
     * An empty reason is stored as null. If details are given without
     * `number`, the number of the version is added, so the entry can still be
     * described after the version was deleted.
     *
     * @param array<string, mixed>|null $details see the class description
     * @throws Exception if the entry cannot be saved
     */
    public static function log(
        Item $item,
        string $action,
        ?Version $version = null,
        ?string $reason = null,
        ?array $details = null
    ): self {
        if ($version !== null && $version->number !== null && !isset($details['number'])) {
            $details = ['number' => (int)$version->number] + ($details ?? []);
        }

        $history = new static([
            'item_id' => $item->id,
            'version_id' => $version?->id,
            'action' => $action,
            'reason' => $reason === null || trim($reason) === '' ? null : $reason,
        ]);
        $history->setDetails($details);

        if (!$history->save()) {
            throw new Exception('History entry not saved: ' . json_encode($history->getErrors()));
        }

        return $history;
    }

    public function rules()
    {
        return [
            [['item_id', 'action'], 'required'],
            ['item_id', 'exist', 'targetClass' => Item::class, 'targetAttribute' => 'id'],
            ['version_id', 'exist', 'targetClass' => Version::class, 'targetAttribute' => 'id'],
            ['action', 'string', 'max' => 64],
            ['reason', 'string'],
            ['actor_id', 'string', 'max' => 64],
            ['details', 'string'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'item_id' => Yii::t('knowledge-library', 'Item'),
            'version_id' => Yii::t('knowledge-library', 'Version'),
            'action' => Yii::t('knowledge-library', 'Action'),
            'reason' => Yii::t('knowledge-library', 'Reason'),
            'actor_id' => Yii::t('knowledge-library', 'Actor'),
            'details' => Yii::t('knowledge-library', 'Details'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert && ($this->actor_id === null || $this->actor_id === '')) {
            $this->actor_id = DefaultUserProvider::resolve()->getCurrentUserReference();
        }

        return true;
    }

    public function getItem(): ActiveQuery
    {
        return $this->hasOne(Item::class, ['id' => 'item_id']);
    }

    public function getVersion(): ActiveQuery
    {
        return $this->hasOne(Version::class, ['id' => 'version_id']);
    }

    /**
     * Details of the entry (`details` decoded), empty if none.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        if ($this->details === null || $this->details === '') {
            return [];
        }

        $details = json_decode($this->details, true);

        return is_array($details) ? $details : [];
    }

    /**
     * Stores the details as JSON object, null or an empty array store null.
     *
     * @param array<string, mixed>|null $details
     */
    public function setDetails(?array $details): void
    {
        $this->details = $details === null || $details === []
            ? null
            : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Human-readable description of what happened, e.g. "Version 3 sent to
     * Jane Doe for approval", built from the action and the details. User
     * references are shown as display names, see UserProviderInterface.
     */
    public function describe(UserProviderInterface $users): string
    {
        $details = $this->getDetails();
        $number = $details['number'] ?? ($this->version_id !== null ? $this->version?->number : null);
        $params = [
            'number' => $number === null ? '?' : (string)$number,
            'actor' => $this->userName($users, $this->actor_id),
            'reviewer' => $this->userName($users, $details['reviewer'] ?? null),
            'previous' => $this->userName($users, $details['previous_reviewer'] ?? null),
        ];

        switch ($this->action) {
            case self::ACTION_CREATED:
                return Yii::t('knowledge-library', 'Knowledge object created');
            case self::ACTION_MASTER_DATA_CHANGED:
                return Yii::t('knowledge-library', 'Master data changed');
            case self::ACTION_SOURCE_CHANGED:
                return Yii::t('knowledge-library', 'Source & origin changed');
            case self::ACTION_DRAFT_SAVED:
                return Yii::t('knowledge-library', 'Version {number} saved as draft', $params);
            case self::ACTION_DRAFT_DISCARDED:
                return Yii::t('knowledge-library', 'Draft of version {number} discarded', $params);
            case self::ACTION_REVIEW_REQUESTED:
                return Yii::t('knowledge-library', 'Version {number} sent to {reviewer} for approval', $params);
            case self::ACTION_REVIEWER_CHANGED:
                return Yii::t(
                    'knowledge-library',
                    'Review of version {number} handed over to {reviewer} (previously {previous})',
                    $params
                );
            case self::ACTION_RETURNED:
                return Yii::t('knowledge-library', 'Version {number} returned by {actor}', $params);
            case self::ACTION_APPROVED:
                return Yii::t('knowledge-library', 'Version {number} approved by {actor}', $params);
            case self::ACTION_PUBLISHED:
                if (!empty($details['valid_from'])) {
                    return Yii::t('knowledge-library', 'Version {number} published, valid from {date}', $params + [
                        'date' => Yii::$app->formatter->asDate($details['valid_from']),
                    ]);
                }

                return Yii::t('knowledge-library', 'Version {number} published', $params);
            case self::ACTION_WITHDRAWN:
                if (isset($details['successor'])) {
                    return Yii::t(
                        'knowledge-library',
                        'Version {number} withdrawn, version {successor} remains valid',
                        $params + ['successor' => (string)$details['successor']]
                    );
                }

                return Yii::t('knowledge-library', 'Version {number} withdrawn, no valid version', $params);
            case self::ACTION_CORRECTED:
                return Yii::t('knowledge-library', 'Version {number} corrected by version {correction}', $params + [
                    'correction' => isset($details['correction']) ? (string)$details['correction'] : '?',
                ]);
            case self::ACTION_ARCHIVED:
                return Yii::t('knowledge-library', 'Archived');
            case self::ACTION_RESTORED:
                return Yii::t('knowledge-library', 'Restored');
            case self::ACTION_RELATION_ADDED:
                return Yii::t(
                    'knowledge-library',
                    'Relation added: {relation} {target}',
                    $this->relationParams($details)
                );
            case self::ACTION_RELATION_REMOVED:
                return Yii::t(
                    'knowledge-library',
                    'Relation removed: {relation} {target}',
                    $this->relationParams($details)
                );
        }

        return $this->action;
    }

    /**
     * Display name of the user reference, the reference itself if the user
     * is unknown, "–" without reference.
     */
    private function userName(UserProviderInterface $users, $reference): string
    {
        if ($reference === null || $reference === '') {
            return '–';
        }

        return $users->getDisplayName((string)$reference) ?? (string)$reference;
    }

    /**
     * Parameters `relation` (label of the relation type) and `target` (title
     * of the related item) of a relation entry.
     *
     * @return array<string, string>
     */
    private function relationParams(array $details): array
    {
        $type = (string)($details['relation'] ?? '');
        $direction = ($details['direction'] ?? 'forward') === 'inverse' ? 'inverse' : 'forward';
        $labels = Relation::labels();

        return [
            'relation' => $labels[$type][$direction] ?? $type,
            'target' => (string)($details['target'] ?? ''),
        ];
    }
}
