<?php

namespace dmstr\knowledgeLibrary\models;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\models\query\VersionQuery;
use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use Throwable;
use Yii;
use yii\db\ActiveQuery;

/**
 * Content version of a knowledge item.
 *
 * Versions are numbered per item. For types with a validity period each
 * published version is valid from `valid_from` until `valid_until` (open-ended
 * if null); publishing a new version ends its predecessor the day before.
 * For types without a validity period the newest published version is valid.
 *
 * @property string $id
 * @property string $item_id
 * @property int $number
 * @property string $status
 * @property string|null $valid_from
 * @property string|null $valid_until
 * @property string|null $content
 * @property string|null $reviewer_id
 * @property string|null $review_message
 * @property string|null $review_requested_by
 * @property string|null $review_requested_at
 * @property string|null $return_note
 * @property string|null $returned_by
 * @property string|null $returned_at
 * @property string|null $published_by
 * @property string|null $published_at
 * @property string|null $withdrawn_by
 * @property string|null $withdrawn_at
 * @property string|null $withdraw_reason
 * @property string|null $corrects_version_id
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property-read Item|null $item
 * @property-read File[] $files
 * @property-read File[] $mainFiles
 * @property-read File[] $attachments
 * @property-read Version|null $correctedVersion
 * @property-read History[] $history
 */
class Version extends ActiveRecord
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATE_IN_FORCE = 'in_force';
    public const STATE_HISTORICAL = 'historical';
    public const STATE_UPCOMING = 'upcoming';

    private const DATE_FORMAT = 'Y-m-d';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public static function tableName()
    {
        return '{{%knowledge_library_version}}';
    }

    public static function find(): VersionQuery
    {
        return new VersionQuery(static::class);
    }

    /**
     * @return array<string, string> map `status => label`
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => Yii::t('knowledge-library', 'Draft'),
            self::STATUS_IN_REVIEW => Yii::t('knowledge-library', 'In Review'),
            self::STATUS_PUBLISHED => Yii::t('knowledge-library', 'Published'),
            self::STATUS_WITHDRAWN => Yii::t('knowledge-library', 'Withdrawn'),
        ];
    }

    /**
     * @return array<string, string> map `effective state => label`
     */
    public static function effectiveStates(): array
    {
        return [
            self::STATE_IN_FORCE => Yii::t('knowledge-library', 'In Force'),
            self::STATE_HISTORICAL => Yii::t('knowledge-library', 'Historical'),
            self::STATE_UPCOMING => Yii::t('knowledge-library', 'Upcoming'),
        ];
    }

    public function rules()
    {
        // Attributes prefixed with "!" are validated but only set by the
        // transitions, never by mass assignment.
        return [
            ['item_id', 'required'],
            ['item_id', 'exist', 'targetClass' => Item::class, 'targetAttribute' => 'id'],
            ['!status', 'default', 'value' => self::STATUS_DRAFT],
            ['!status', 'in', 'range' => array_keys(static::statuses())],
            [
                '!status',
                'unique',
                'targetAttribute' => ['item_id'],
                'filter' => ['status' => self::STATUS_DRAFT],
                'when' => static fn (self $model) => $model->status === self::STATUS_DRAFT,
                'message' => Yii::t('knowledge-library', 'This item already has a draft.'),
            ],
            [
                '!status',
                'unique',
                'targetAttribute' => ['item_id'],
                'filter' => ['status' => self::STATUS_IN_REVIEW],
                'when' => static fn (self $model) => $model->status === self::STATUS_IN_REVIEW,
                'message' => Yii::t('knowledge-library', 'This item already has a version in review.'),
            ],
            [['content', '!review_message', '!return_note', '!withdraw_reason'], 'string'],
            ['content', 'validateContent', 'skipOnEmpty' => false],
            [
                ['!reviewer_id', '!review_requested_by', '!returned_by', '!published_by', '!withdrawn_by'],
                'string',
                'max' => 64,
            ],
            [['valid_from', 'valid_until'], 'date', 'format' => 'php:' . self::DATE_FORMAT, 'strictDateFormat' => true],
            ['valid_from', 'validateValidityPeriod', 'skipOnEmpty' => false],
            ['corrects_version_id', 'compare', 'compareAttribute' => 'id', 'operator' => '!=='],
            [
                'corrects_version_id',
                'exist',
                'targetClass' => self::class,
                'targetAttribute' => 'id',
                'filter' => fn (ActiveQuery $query) => $query->andWhere(['item_id' => $this->item_id]),
                'message' => Yii::t('knowledge-library', 'The corrected version must belong to the same item.'),
            ],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'item_id' => Yii::t('knowledge-library', 'Item'),
            'number' => Yii::t('knowledge-library', 'Number'),
            'status' => Yii::t('knowledge-library', 'Status'),
            'valid_from' => Yii::t('knowledge-library', 'Valid From'),
            'valid_until' => Yii::t('knowledge-library', 'Valid Until'),
            'content' => Yii::t('knowledge-library', 'Content'),
            'reviewer_id' => Yii::t('knowledge-library', 'Reviewer'),
            'review_message' => Yii::t('knowledge-library', 'Review Message'),
            'review_requested_by' => Yii::t('knowledge-library', 'Review Requested By'),
            'review_requested_at' => Yii::t('knowledge-library', 'Review Requested At'),
            'return_note' => Yii::t('knowledge-library', 'Return Note'),
            'returned_by' => Yii::t('knowledge-library', 'Returned By'),
            'returned_at' => Yii::t('knowledge-library', 'Returned At'),
            'published_by' => Yii::t('knowledge-library', 'Published By'),
            'published_at' => Yii::t('knowledge-library', 'Published At'),
            'withdrawn_by' => Yii::t('knowledge-library', 'Withdrawn By'),
            'withdrawn_at' => Yii::t('knowledge-library', 'Withdrawn At'),
            'withdraw_reason' => Yii::t('knowledge-library', 'Withdraw Reason'),
            'corrects_version_id' => Yii::t('knowledge-library', 'Corrects Version'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    /**
     * Content is required once a version is submitted for review or published:
     * Markdown text or at least one main file.
     */
    public function validateContent(string $attribute): void
    {
        if (!in_array($this->status, [self::STATUS_IN_REVIEW, self::STATUS_PUBLISHED], true)) {
            return;
        }

        if (trim((string)$this->content) !== '' || $this->hasMainFile()) {
            return;
        }

        $this->addError(
            $attribute,
            Yii::t('knowledge-library', 'Enter a text or attach a main file.')
        );
    }

    /**
     * Checks the validity dates against the type of the item and the latest
     * published version.
     */
    public function validateValidityPeriod(): void
    {
        $type = $this->getItemType();
        if ($type === null) {
            return;
        }

        if (!$type->has_validity_period) {
            foreach (['valid_from', 'valid_until'] as $attribute) {
                if ($this->$attribute !== null && $this->$attribute !== '') {
                    $this->addError(
                        $attribute,
                        Yii::t(
                            'knowledge-library',
                            'The type of this item has no validity period, leave the date empty.'
                        )
                    );
                }
            }

            return;
        }

        if ($this->hasErrors('valid_from') || $this->hasErrors('valid_until')) {
            return;
        }

        $validFrom = $this->valid_from === '' ? null : $this->valid_from;
        $validUntil = $this->valid_until === '' ? null : $this->valid_until;

        if ($validFrom === null) {
            if (in_array($this->status, [self::STATUS_IN_REVIEW, self::STATUS_PUBLISHED], true)) {
                $this->addError(
                    'valid_from',
                    Yii::t('knowledge-library', 'Valid From is required for review and publication.')
                );
            }

            return;
        }

        if ($validUntil !== null && $validUntil < $validFrom) {
            $this->addError(
                'valid_until',
                Yii::t('knowledge-library', 'Valid Until must not be before Valid From.')
            );
        }

        if (!$this->isPublishedInDatabase() && empty($this->corrects_version_id)) {
            $latest = $this->findLatestPublishedSibling();
            if ($latest !== null && $latest->valid_from !== null && $validFrom <= $latest->valid_from) {
                $this->addError(
                    'valid_from',
                    Yii::t(
                        'knowledge-library',
                        'A new version must start after {date}, the start of the latest published version. Use a correction to change a published version.',
                        ['date' => $latest->valid_from]
                    )
                );
            }
        }
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($insert) {
            $this->number = (int)static::find()->forItem($this->item_id)->max('number') + 1;
        }

        return true;
    }

    public function getItem(): ActiveQuery
    {
        return $this->hasOne(Item::class, ['id' => 'item_id']);
    }

    public function getFiles(): ActiveQuery
    {
        return $this->hasMany(File::class, ['version_id' => 'id'])
            ->orderBy(['position' => SORT_ASC]);
    }

    public function getMainFiles(): ActiveQuery
    {
        return $this->getFiles()->andOnCondition(['kind' => File::KIND_MAIN]);
    }

    public function getAttachments(): ActiveQuery
    {
        return $this->getFiles()->andOnCondition(['kind' => File::KIND_ATTACHMENT]);
    }

    public function getCorrectedVersion(): VersionQuery
    {
        /** @var VersionQuery $query */
        $query = $this->hasOne(self::class, ['id' => 'corrects_version_id']);

        return $query;
    }

    public function getHistory(): ActiveQuery
    {
        return $this->hasMany(History::class, ['version_id' => 'id'])
            ->orderBy(['created_at' => SORT_DESC]);
    }

    /**
     * Effective state of a published version at the given date, null for all
     * other statuses.
     *
     * @param string|null $date date in the format `Y-m-d`, today if null
     */
    public function getEffectiveState(?string $date = null): ?string
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return null;
        }

        $date ??= date(self::DATE_FORMAT);
        $type = $this->getItemType();

        if ($type !== null && !$type->has_validity_period) {
            $latest = $this->findLatestPublished();

            return $latest !== null && $latest->id === $this->id ? self::STATE_IN_FORCE : self::STATE_HISTORICAL;
        }

        if ($this->valid_from !== null && $this->valid_from > $date) {
            return self::STATE_UPCOMING;
        }

        if ($this->valid_until !== null && $this->valid_until < $date) {
            return self::STATE_HISTORICAL;
        }

        return self::STATE_IN_FORCE;
    }

    /**
     * Submits a draft for review by the given reviewer.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function submitForReview(string $reviewerId, ?string $message = null): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            $this->addError('status', Yii::t('knowledge-library', 'Only a draft can be submitted for review.'));

            return false;
        }

        return $this->transition([
            'status' => self::STATUS_IN_REVIEW,
            'reviewer_id' => $reviewerId,
            'review_message' => $message,
            'review_requested_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
            'review_requested_at' => date(self::DATETIME_FORMAT),
        ]);
    }

    /**
     * Publishes the version. For types with a validity period the latest
     * published version is ended the day before this version starts.
     *
     * A version in review can always be published, a draft only if the type
     * of the item does not require a review.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function publish(): bool
    {
        $type = $this->getItemType();

        if ($this->status === self::STATUS_DRAFT) {
            if ($type === null || $type->requires_review) {
                $this->addError(
                    'status',
                    Yii::t('knowledge-library', 'This version must be reviewed before it can be published.')
                );

                return false;
            }
        } elseif ($this->status !== self::STATUS_IN_REVIEW) {
            $this->addError(
                'status',
                Yii::t('knowledge-library', 'Only a draft or a version in review can be published.')
            );

            return false;
        }

        return $this->transition([
            'status' => self::STATUS_PUBLISHED,
            'published_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
            'published_at' => date(self::DATETIME_FORMAT),
        ], fn () => $this->endPredecessor());
    }

    /**
     * Sets the given attributes, validates and saves in a transaction.
     *
     * On failure the previous attribute values are restored and the errors are
     * kept.
     *
     * @param callable|null $beforeSave called after a successful validation,
     * returning false aborts the transition
     */
    private function transition(array $attributes, ?callable $beforeSave = null): bool
    {
        $previous = $this->getAttributes();
        $this->setAttributes($attributes, false);

        $transaction = static::getDb()->beginTransaction();
        try {
            if (
                $this->validate()
                && ($beforeSave === null || $beforeSave() !== false)
                && $this->save(false)
            ) {
                $transaction->commit();

                return true;
            }

            $transaction->rollBack();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $this->setAttributes($previous, false);

            throw $e;
        }

        $this->setAttributes($previous, false);

        return false;
    }

    /**
     * Ends the latest published version the day before this version starts,
     * unless it already ends earlier.
     */
    private function endPredecessor(): bool
    {
        $type = $this->getItemType();
        if ($type === null || !$type->has_validity_period || $this->valid_from === null) {
            // Without a validity period the newest published version wins.
            return true;
        }

        if (!empty($this->corrects_version_id)) {
            // TODO: a correction replaces the corrected version instead of
            // following it; handling the corrected version (e.g. withdrawing
            // it) is part of the correction flow, which is not implemented yet.
            return true;
        }

        $predecessor = $this->findLatestPublishedSibling();
        if ($predecessor === null) {
            return true;
        }

        if ($predecessor->valid_until !== null && $predecessor->valid_until < $this->valid_from) {
            return true;
        }

        $predecessor->valid_until = (new DateTimeImmutable($this->valid_from))
            ->modify('-1 day')
            ->format(self::DATE_FORMAT);

        if (!$predecessor->save(false)) {
            $this->addError('status', Yii::t('knowledge-library', 'The previous version could not be ended.'));

            return false;
        }

        return true;
    }

    private function getItemType(): ?Type
    {
        $item = $this->item;

        return $item instanceof Item ? $item->type : null;
    }

    private function hasMainFile(): bool
    {
        if ($this->getIsNewRecord()) {
            return false;
        }

        return File::find()
            ->where(['version_id' => $this->id, 'kind' => File::KIND_MAIN])
            ->exists();
    }

    /**
     * Whether the version is stored as published or withdrawn, i.e. it is not
     * a new version anymore.
     */
    private function isPublishedInDatabase(): bool
    {
        return in_array(
            $this->getOldAttribute('status'),
            [self::STATUS_PUBLISHED, self::STATUS_WITHDRAWN],
            true
        );
    }

    /**
     * Published version of the item with the highest number.
     */
    private function findLatestPublished(): ?self
    {
        return static::find()
            ->published()
            ->forItem($this->item_id)
            ->orderBy(['number' => SORT_DESC])
            ->limit(1)
            ->one();
    }

    /**
     * Published version of the item with the highest number, except this one.
     */
    private function findLatestPublishedSibling(): ?self
    {
        return static::find()
            ->published()
            ->forItem($this->item_id)
            ->andWhere(['not', ['id' => $this->id]])
            ->orderBy(['number' => SORT_DESC])
            ->limit(1)
            ->one();
    }
}
