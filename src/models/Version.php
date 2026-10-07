<?php

namespace dmstr\knowledgeLibrary\models;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\models\query\VersionQuery;
use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use Throwable;
use Yii;
use yii\base\InvalidArgumentException;
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
 * @property string|null $draft_title
 * @property string|null $draft_summary
 * @property string|null $draft_topic_ids JSON list of topic IDs
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property string[] $draftTopicIds topic IDs of the draft, see getDraftTopicIds()
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

    /**
     * Wizard step "content": the Markdown text.
     */
    public const SCENARIO_CONTENT = 'content';

    /**
     * Wizard step "validity": Valid From and Valid Until; Valid From is
     * required for types with validity period.
     */
    public const SCENARIO_VALIDITY = 'validity';

    /**
     * Wizard step "details": title, summary and topics of the item, stored in
     * the draft until publication.
     */
    public const SCENARIO_DETAILS = 'details';

    /**
     * Results of getContentChange().
     */
    public const CONTENT_CHANGED = 'changed';
    public const CONTENT_UNCHANGED = 'unchanged';
    public const CONTENT_NONE = 'none';

    /**
     * What applies instead of a withdrawn version, see withdraw().
     */
    public const WITHDRAW_PREVIOUS = 'previous';
    public const WITHDRAW_CORRECTION = 'correction';
    public const WITHDRAW_NONE = 'none';

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

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_CONTENT] = ['content'];
        $scenarios[self::SCENARIO_VALIDITY] = ['valid_from', 'valid_until'];
        $scenarios[self::SCENARIO_DETAILS] = ['draft_title', 'draft_summary', 'draftTopicIds'];

        return $scenarios;
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
            [
                'valid_from',
                'required',
                'on' => self::SCENARIO_VALIDITY,
                'when' => fn () => $this->getItemType() === null || $this->getItemType()->has_validity_period,
                'message' => ValidityCheck::invalidDateMessage('valid_from'),
            ],
            [
                'valid_from',
                'date',
                'format' => 'php:' . self::DATE_FORMAT,
                'strictDateFormat' => true,
                'message' => ValidityCheck::invalidDateMessage('valid_from'),
            ],
            [
                'valid_until',
                'date',
                'format' => 'php:' . self::DATE_FORMAT,
                'strictDateFormat' => true,
                'message' => ValidityCheck::invalidDateMessage('valid_until'),
            ],
            ['valid_from', 'validateValidityPeriod', 'skipOnEmpty' => false],
            ['draft_title', 'trim', 'on' => self::SCENARIO_DETAILS],
            ['draft_title', 'required', 'on' => self::SCENARIO_DETAILS],
            ['draft_title', 'string', 'max' => 255],
            ['draft_summary', 'string'],
            // Checked when the editor picks the topics; a topic deleted
            // later must not block the transitions, see applyDraftDetails().
            [
                'draftTopicIds',
                'each',
                'rule' => ['exist', 'targetClass' => Topic::class, 'targetAttribute' => 'id'],
                'on' => self::SCENARIO_DETAILS,
            ],
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
            'draft_title' => Yii::t('knowledge-library', 'Title'),
            'draft_summary' => Yii::t('knowledge-library', 'Summary'),
            'draftTopicIds' => Yii::t('knowledge-library', 'Topics'),
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

        if ($this->hasContent()) {
            return;
        }

        $this->addError($attribute, static::noContentMessage());
    }

    /**
     * Whether the version has content: a non-empty text or at least one main
     * file (stored as file row). Attachments do not count.
     */
    public function hasContent(): bool
    {
        return trim((string)$this->content) !== '' || $this->hasMainFile();
    }

    /**
     * Message for a version without content, see hasContent().
     */
    public static function noContentMessage(): string
    {
        return Yii::t('knowledge-library', 'Enter a text or attach a main file.');
    }

    /**
     * Message for a correction whose dates differ from the corrected version.
     */
    public static function correctionPeriodMessage(): string
    {
        return Yii::t('knowledge-library', 'A correction keeps the validity period of the corrected version.');
    }

    /**
     * Checks the validity dates against the type of the item and the latest
     * published version. A correction is not checked against the latest
     * published version, its dates must equal those of the corrected
     * version instead (until it is published).
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
                    $this->addError($attribute, ValidityCheck::noValidityPeriodMessage());
                }
            }

            return;
        }

        if ($this->hasErrors('valid_from') || $this->hasErrors('valid_until')) {
            return;
        }

        $validFrom = $this->valid_from === '' ? null : $this->valid_from;
        $validUntil = $this->valid_until === '' ? null : $this->valid_until;

        if ($this->isCorrection() && !$this->isPublishedInDatabase()) {
            $corrected = $this->correctedVersion;
            if (
                $corrected !== null
                && ($validFrom !== $corrected->valid_from || $validUntil !== $corrected->valid_until)
            ) {
                $this->addError('valid_from', static::correctionPeriodMessage());

                return;
            }
        }

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
            $this->addError('valid_until', ValidityCheck::untilBeforeFromMessage());
        }

        if (!$this->isPublishedInDatabase() && !$this->isCorrection()) {
            $latest = $this->findLatestPublishedSibling();
            if ($latest !== null && $latest->valid_from !== null && $validFrom <= $latest->valid_from) {
                $this->addError('valid_from', ValidityCheck::retroactiveMessage($latest));
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

    /**
     * Discarding the draft of a correction clears the reason stored in
     * `withdraw_reason` of the corrected version, see createCorrection().
     */
    public function afterDelete()
    {
        parent::afterDelete();

        if ($this->isCorrection() && in_array($this->status, [self::STATUS_DRAFT, self::STATUS_IN_REVIEW], true)) {
            static::updateAll(
                ['withdraw_reason' => null],
                ['id' => $this->corrects_version_id, 'status' => self::STATUS_PUBLISHED]
            );
        }
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
     * Topic IDs stored in the draft (`draft_topic_ids`), empty if none.
     *
     * @return string[]
     */
    public function getDraftTopicIds(): array
    {
        if ($this->draft_topic_ids === null || $this->draft_topic_ids === '') {
            return [];
        }

        $ids = json_decode($this->draft_topic_ids, true);

        return is_array($ids) ? array_values(array_map('strval', array_filter($ids, 'is_scalar'))) : [];
    }

    /**
     * Stores topic IDs in the draft as JSON list; null or an empty string
     * store an empty list (e.g. an empty multiple select).
     *
     * @param string[]|string|null $topicIds
     */
    public function setDraftTopicIds($topicIds): void
    {
        if ($topicIds === null || $topicIds === '') {
            $topicIds = [];
        }

        $this->draft_topic_ids = json_encode(
            array_values(array_unique(array_map('strval', (array)$topicIds)))
        );
    }

    /**
     * Returns the draft of the item, creating it if the item has none.
     *
     * A new draft starts from the published version with the highest
     * number: its text is copied, and its files are copied as new file rows
     * pointing to the same stored files (see File::copyToVersion()). Title,
     * summary and topics are taken from the item. For types with validity
     * period Valid From is prefilled, see getSuggestedValidFrom().
     *
     * An item has at most one draft. No draft is created while another
     * version of the item is in review or the item is archived. In these
     * cases, and if saving fails (e.g. a concurrent request created a draft
     * meanwhile), the returned version is not saved (`getIsNewRecord()` is
     * true) and carries the errors.
     *
     * @param string|null $today date in the format `Y-m-d`, today if null
     */
    public static function createDraft(Item $item, ?string $today = null): self
    {
        if ($item->is_archived) {
            return static::rejectedDraft($item, Item::archivedMessage());
        }

        $existing = static::find()
            ->forItem($item->id)
            ->andWhere(['status' => self::STATUS_DRAFT])
            ->orderBy(['number' => SORT_DESC])
            ->limit(1)
            ->one();
        if ($existing !== null) {
            return $existing;
        }

        $inReview = static::find()
            ->forItem($item->id)
            ->andWhere(['status' => self::STATUS_IN_REVIEW])
            ->exists();
        if ($inReview) {
            return static::rejectedDraft(
                $item,
                Yii::t('knowledge-library', 'This item already has a version in review.')
            );
        }

        $base = $item->getLatestPublishedVersion();

        $draft = new static();
        $draft->item_id = $item->id;
        $draft->status = self::STATUS_DRAFT;
        $draft->populateRelation('item', $item);
        $draft->content = $base !== null ? $base->content : null;
        $draft->draft_title = $item->title;
        $draft->draft_summary = $item->summary;
        $draft->setDraftTopicIds($item->getTopicIds());
        $draft->valid_from = $draft->getSuggestedValidFrom($today);

        $transaction = static::getDb()->beginTransaction();
        try {
            if (!$draft->save()) {
                $transaction->rollBack();

                return $draft;
            }

            foreach ($base !== null ? $base->files : [] as $file) {
                $copy = $file->copyToVersion($draft);
                if ($copy->hasErrors()) {
                    $transaction->rollBack();
                    $draft->setIsNewRecord(true);
                    $draft->addError('item_id', implode(' ', $copy->getFirstErrors()));

                    return $draft;
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        unset($draft->files, $draft->mainFiles, $draft->attachments);

        return $draft;
    }

    /**
     * Unsaved draft of the item carrying the error, see createDraft().
     */
    private static function rejectedDraft(Item $item, string $error): self
    {
        $draft = new static();
        $draft->item_id = $item->id;
        $draft->status = self::STATUS_DRAFT;
        $draft->populateRelation('item', $item);
        $draft->addError('status', $error);

        return $draft;
    }

    /**
     * Creates the draft of a correction of the given published version.
     *
     * The correction starts as copy of the corrected version: text, files
     * (see File::copyToVersion()) and validity period; title, summary and
     * topics are taken from the item, as for createDraft(). The validity
     * period cannot be changed, see validateValidityPeriod(). Publishing the
     * correction withdraws the corrected version, see publish().
     *
     * The reason is stored in `withdraw_reason` of the corrected version
     * until the correction is published, and cleared when the draft of the
     * correction is deleted (discarded), see afterDelete().
     *
     * Only allowed as long as canCorrect() is true. Otherwise, and if saving
     * fails, the returned version is not saved (`getIsNewRecord()` is true)
     * and carries the errors (attribute `status` for the rules).
     */
    public static function createCorrection(self $corrected, ?string $reason = null): self
    {
        $item = $corrected->item;
        if (!$item instanceof Item) {
            throw new InvalidArgumentException('The corrected version has no item.');
        }

        $error = $corrected->correctionError();
        if ($error !== null) {
            $correction = static::rejectedDraft($item, $error);
            $correction->corrects_version_id = $corrected->id;

            return $correction;
        }

        $correction = new static();
        $correction->item_id = $item->id;
        $correction->status = self::STATUS_DRAFT;
        $correction->corrects_version_id = $corrected->id;
        $correction->populateRelation('item', $item);
        $correction->populateRelation('correctedVersion', $corrected);
        $correction->content = $corrected->content;
        $correction->valid_from = $corrected->valid_from;
        $correction->valid_until = $corrected->valid_until;
        $correction->draft_title = $item->title;
        $correction->draft_summary = $item->summary;
        $correction->setDraftTopicIds($item->getTopicIds());

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        $transaction = static::getDb()->beginTransaction();
        try {
            if (!$correction->save()) {
                $transaction->rollBack();

                return $correction;
            }

            foreach ($corrected->files as $file) {
                $copy = $file->copyToVersion($correction);
                if ($copy->hasErrors()) {
                    $transaction->rollBack();
                    $correction->setIsNewRecord(true);
                    $correction->addError('item_id', implode(' ', $copy->getFirstErrors()));

                    return $correction;
                }
            }

            $corrected->updateAttributes(['withdraw_reason' => $reason]);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        unset($correction->files, $correction->mainFiles, $correction->attachments);

        return $correction;
    }

    /**
     * Whether this version corrects another version (`corrects_version_id`).
     */
    public function isCorrection(): bool
    {
        return $this->corrects_version_id !== null && $this->corrects_version_id !== '';
    }

    /**
     * Whether this version can be corrected now, see createCorrection().
     */
    public function canCorrect(): bool
    {
        return $this->correctionError() === null;
    }

    /**
     * Whether this version can be withdrawn now, see withdraw().
     *
     * @param string|null $date date in the format `Y-m-d`, today if null
     */
    public function canWithdraw(?string $date = null): bool
    {
        return $this->withdrawalError($date) === null;
    }

    /**
     * Why this version cannot be corrected, null if it can.
     *
     * Any published version can be corrected, unless the item is archived or
     * has a draft or a version in review. For types without validity period
     * only the version in force can be corrected: there the published version
     * with the highest number is valid, so the correction of an older version
     * would silently replace the version in force.
     */
    private function correctionError(): ?string
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return Yii::t('knowledge-library', 'Only a published version can be corrected.');
        }

        $item = $this->item;
        if ($item instanceof Item && $item->is_archived) {
            return Item::archivedMessage();
        }

        $statuses = static::find()
            ->select('status')
            ->forItem($this->item_id)
            ->andWhere(['status' => [self::STATUS_DRAFT, self::STATUS_IN_REVIEW]])
            ->column();
        if (in_array(self::STATUS_DRAFT, $statuses, true)) {
            return Yii::t('knowledge-library', 'This item already has a draft.');
        }
        if (in_array(self::STATUS_IN_REVIEW, $statuses, true)) {
            return Yii::t('knowledge-library', 'This item already has a version in review.');
        }

        $type = $this->getItemType();
        if ($type !== null && !$type->has_validity_period && $this->getEffectiveState() !== self::STATE_IN_FORCE) {
            return Yii::t(
                'knowledge-library',
                'Only the version in force can be corrected, as this type has no validity period.'
            );
        }

        return null;
    }

    /**
     * Why this version cannot be withdrawn, null if it can: only a published
     * version in force or upcoming at the date can be withdrawn, and not
     * while a correction of it is in progress.
     *
     * @param string|null $date date in the format `Y-m-d`, today if null
     */
    private function withdrawalError(?string $date = null): ?string
    {
        if (!in_array($this->getEffectiveState($date), [self::STATE_IN_FORCE, self::STATE_UPCOMING], true)) {
            return Yii::t('knowledge-library', 'Only a version in force or an upcoming version can be withdrawn.');
        }

        $correctionInProgress = static::find()
            ->forItem($this->item_id)
            ->andWhere([
                'corrects_version_id' => $this->id,
                'status' => [self::STATUS_DRAFT, self::STATUS_IN_REVIEW],
            ])
            ->exists();
        if ($correctionInProgress) {
            return Yii::t('knowledge-library', 'A correction of this version is in progress.');
        }

        return null;
    }

    /**
     * Suggested Valid From of a new version: the day after the start of the
     * predecessor, at least today; today without predecessor. Null for types
     * without validity period.
     *
     * @param string|null $today date in the format `Y-m-d`, today if null
     */
    public function getSuggestedValidFrom(?string $today = null): ?string
    {
        $type = $this->getItemType();
        if ($type !== null && !$type->has_validity_period) {
            return null;
        }

        $today ??= date(self::DATE_FORMAT);
        $predecessor = $this->getPredecessor();
        if ($predecessor === null || $predecessor->valid_from === null) {
            return $today;
        }

        $next = (new DateTimeImmutable($predecessor->valid_from))->modify('+1 day')->format(self::DATE_FORMAT);

        return max($next, $today);
    }

    /**
     * The version a new version follows: the published version of the item
     * with the highest number, except this one.
     */
    public function getPredecessor(): ?self
    {
        if (empty($this->item_id)) {
            return null;
        }

        return $this->findLatestPublishedSibling();
    }

    /**
     * Text compared to the predecessor (for a correction: the corrected
     * version): `changed`, `unchanged` or `none` if this version has no text.
     * Line endings and surrounding whitespace are ignored; without
     * predecessor any text counts as changed.
     */
    public function getContentChange(): string
    {
        $text = static::normalizeText($this->content);
        if ($text === '') {
            return self::CONTENT_NONE;
        }

        $predecessor = $this->isCorrection() ? $this->correctedVersion : $this->getPredecessor();
        if ($predecessor !== null && static::normalizeText($predecessor->content) === $text) {
            return self::CONTENT_UNCHANGED;
        }

        return self::CONTENT_CHANGED;
    }

    /**
     * Short description of the content, e.g. "Text, 1 main document,
     * 2 attachments", based on the stored file rows.
     */
    public function getContentSummary(): string
    {
        $parts = [];
        if (trim((string)$this->content) !== '') {
            $parts[] = Yii::t('knowledge-library', 'Text');
        }

        $counts = [File::KIND_MAIN => 0, File::KIND_ATTACHMENT => 0];
        if (!$this->getIsNewRecord()) {
            $rows = File::find()
                ->select(['kind', 'count' => 'COUNT(*)'])
                ->where(['version_id' => $this->id])
                ->groupBy('kind')
                ->asArray()
                ->all();
            foreach ($rows as $row) {
                $counts[$row['kind']] = (int)$row['count'];
            }
        }

        if ((int)$counts[File::KIND_MAIN] > 0) {
            $parts[] = Yii::t(
                'knowledge-library',
                '{count, plural, =1{# main document} other{# main documents}}',
                ['count' => (int)$counts[File::KIND_MAIN]]
            );
        }
        if ((int)$counts[File::KIND_ATTACHMENT] > 0) {
            $parts[] = Yii::t(
                'knowledge-library',
                '{count, plural, =1{# attachment} other{# attachments}}',
                ['count' => (int)$counts[File::KIND_ATTACHMENT]]
            );
        }

        return $parts === [] ? Yii::t('knowledge-library', 'empty') : implode(', ', $parts);
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
     * Four-eyes principle: the reviewer must be one of the reviewer options of
     * the user provider and must not be the current user. A previous return
     * (`return_note`, `returned_by`, `returned_at`) is cleared. A correction
     * takes over the current period of the corrected version. Writes the
     * history entry `review_requested` with the message as reason.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function submitForReview(string $reviewerId, ?string $message = null): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            $this->addError('status', Yii::t('knowledge-library', 'Only a draft can be submitted for review.'));

            return false;
        }

        if (!$this->checkNotArchived()) {
            return false;
        }

        $users = DefaultUserProvider::resolve();
        $current = $users->getCurrentUserReference();
        $reviewerId = trim($reviewerId);
        if ($reviewerId === '') {
            $this->addError('reviewer_id', Yii::t('knowledge-library', 'Select a reviewer.'));

            return false;
        }
        if ($current !== null && $reviewerId === $current) {
            $this->addError('reviewer_id', Yii::t('knowledge-library', 'You cannot review your own version.'));

            return false;
        }
        if (!$this->checkReviewerOption($reviewerId)) {
            return false;
        }

        $message = $message === null || trim($message) === '' ? null : trim($message);

        return $this->transition([
            'status' => self::STATUS_IN_REVIEW,
            'reviewer_id' => $reviewerId,
            'review_message' => $message,
            'review_requested_by' => $current,
            'review_requested_at' => date(self::DATETIME_FORMAT),
            'return_note' => null,
            'returned_by' => null,
            'returned_at' => null,
        ] + $this->correctionPeriod(), null, fn () => $this->log(
            History::ACTION_REVIEW_REQUESTED,
            $message,
            ['reviewer' => $reviewerId]
        ));
    }

    /**
     * Publishes the version. For types with a validity period the latest
     * published version is ended the day before this version starts.
     *
     * A draft can only be published if the type of the item does not require
     * a review. A version in review can only be published by its reviewer
     * (four-eyes principle), see also approve(). Versions of an archived item
     * cannot be published.
     *
     * If the draft carries details (`draft_title` not null), title, summary
     * and topics are applied to the item and the draft fields are cleared,
     * in the same transaction.
     *
     * Writes the history entry `published`, for a version in review preceded
     * by `approved`.
     *
     * A correction (see createCorrection()) withdraws the corrected version
     * instead of ending the predecessor and writes the history entry
     * `corrected` instead of `published`.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function publish(): bool
    {
        return $this->publishVersion(null);
    }

    /**
     * Approves and publishes a version in review; only the reviewer may
     * approve. The note is stored as reason of the history entry `approved`.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function approve(?string $note = null): bool
    {
        if ($this->status !== self::STATUS_IN_REVIEW) {
            $this->addError('status', Yii::t('knowledge-library', 'Only a version in review can be approved.'));

            return false;
        }

        return $this->publishVersion($note);
    }

    /**
     * Returns a version in review to its author as draft; only the reviewer
     * may return it and a note is required. Reviewer and review message are
     * kept, so the author can submit it again. Writes the history entry
     * `returned` with the note as reason.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function returnToDraft(string $note): bool
    {
        if ($this->status !== self::STATUS_IN_REVIEW) {
            $this->addError('status', Yii::t('knowledge-library', 'Only a version in review can be returned.'));

            return false;
        }

        if (!$this->checkIsReviewer()) {
            return false;
        }

        $note = trim($note);
        if ($note === '') {
            $this->addError('return_note', Yii::t('knowledge-library', 'Enter a note for the author.'));

            return false;
        }

        return $this->transition([
            'status' => self::STATUS_DRAFT,
            'return_note' => $note,
            'returned_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
            'returned_at' => date(self::DATETIME_FORMAT),
        ], null, fn () => $this->log(History::ACTION_RETURNED, $note, ['reviewer' => $this->reviewer_id]));
    }

    /**
     * Hands the review of a version in review over to another reviewer. The
     * new reviewer must be one of the reviewer options, must differ from the
     * current reviewer and must not be the person who submitted the version.
     * Writes the history entry `reviewer_changed` with the reason.
     *
     * @return bool whether the version was saved; see the errors otherwise
     */
    public function changeReviewer(string $reviewerId, ?string $reason = null): bool
    {
        if ($this->status !== self::STATUS_IN_REVIEW) {
            $this->addError(
                'status',
                Yii::t('knowledge-library', 'Only the reviewer of a version in review can be changed.')
            );

            return false;
        }

        $reviewerId = trim($reviewerId);
        if ($reviewerId === '') {
            $this->addError('reviewer_id', Yii::t('knowledge-library', 'Select a reviewer.'));

            return false;
        }
        if ($reviewerId === $this->reviewer_id) {
            $this->addError(
                'reviewer_id',
                Yii::t('knowledge-library', 'The selected person already reviews this version.')
            );

            return false;
        }
        if ($reviewerId === $this->review_requested_by) {
            $this->addError(
                'reviewer_id',
                Yii::t('knowledge-library', 'The person who submitted the version cannot review it.')
            );

            return false;
        }
        if (!$this->checkReviewerOption($reviewerId)) {
            return false;
        }

        $previous = $this->reviewer_id;

        return $this->transition(
            ['reviewer_id' => $reviewerId],
            null,
            fn () => $this->log(History::ACTION_REVIEWER_CHANGED, $reason, [
                'reviewer' => $reviewerId,
                'previous_reviewer' => $previous,
            ])
        );
    }

    /**
     * Withdraws a published version that is in force or upcoming, see
     * canWithdraw(). Also allowed for archived items.
     *
     * The successor says what applies instead:
     *
     * - `previous`: the previous version, see getPreviousForWithdrawal(),
     *   which must exist. For types with validity period its Valid Until is
     *   set to the Valid Until of the withdrawn version (null: open-ended),
     *   so it covers the withdrawn period. For types without validity period
     *   the newest remaining published version is valid anyway, the choice
     *   only documents that in the history, no other data is changed.
     * - `none`: no version applies in the withdrawn period. For types without
     *   validity period this is only possible without previous version, as
     *   the previous version becomes valid anyway (see VersionQuery::validAt()).
     * - `correction` is not handled here: the version stays published until
     *   its correction is published, see createCorrection().
     *
     * If `$previous` is given (the version the user saw as previous version),
     * it must still be the previous version.
     *
     * Sets `withdrawn_by`, `withdrawn_at` and `withdraw_reason` and writes the
     * history entry `withdrawn` with the reason and, for `previous`, the
     * number of the previous version as detail `successor`.
     *
     * Errors: `status` (state of the version), `withdraw_reason` (reason
     * missing), `successor` (invalid successor).
     *
     * @return bool whether the version was withdrawn; see the errors otherwise
     */
    public function withdraw(string $reason, string $successor, ?self $previous = null): bool
    {
        $error = $this->withdrawalError();
        if ($error !== null) {
            $this->addError('status', $error);

            return false;
        }

        $reason = trim($reason);
        if ($reason === '') {
            $this->addError('withdraw_reason', Yii::t('knowledge-library', 'Enter a reason for the withdrawal.'));

            return false;
        }

        if ($successor === self::WITHDRAW_CORRECTION) {
            $this->addError(
                'successor',
                Yii::t('knowledge-library', 'A correction withdraws this version when it is published.')
            );

            return false;
        }
        if (!in_array($successor, [self::WITHDRAW_PREVIOUS, self::WITHDRAW_NONE], true)) {
            $this->addError('successor', Yii::t('knowledge-library', 'Select what applies instead.'));

            return false;
        }

        $type = $this->getItemType();
        $hasValidityPeriod = $type === null || (bool)$type->has_validity_period;
        $actualPrevious = $this->getPreviousForWithdrawal();

        if ($successor === self::WITHDRAW_PREVIOUS) {
            if ($actualPrevious === null) {
                $this->addError('successor', Yii::t('knowledge-library', 'There is no previous version.'));

                return false;
            }
            if ($previous !== null && $previous->id !== $actualPrevious->id) {
                $this->addError('successor', Yii::t('knowledge-library', 'The previous version has changed meanwhile.'));

                return false;
            }
        } elseif (!$hasValidityPeriod && $actualPrevious !== null) {
            $this->addError('successor', Yii::t(
                'knowledge-library',
                'Version {number} remains valid, as this type has no validity period.',
                ['number' => (int)$actualPrevious->number]
            ));

            return false;
        }

        $extendPrevious = $successor === self::WITHDRAW_PREVIOUS && $hasValidityPeriod;

        return $this->transition(
            [
                'status' => self::STATUS_WITHDRAWN,
                'withdrawn_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
                'withdrawn_at' => date(self::DATETIME_FORMAT),
                'withdraw_reason' => $reason,
            ],
            $extendPrevious ? fn () => $this->extendPrevious($actualPrevious) : null,
            fn () => $this->log(
                History::ACTION_WITHDRAWN,
                $reason,
                $successor === self::WITHDRAW_PREVIOUS ? ['successor' => (int)$actualPrevious->number] : null
            )
        );
    }

    /**
     * The version that applies again if this version is withdrawn, null if
     * none.
     *
     * For types with validity period: the published version (except this
     * one) with the latest Valid From before the Valid From of this version.
     * For types without validity period: the published version with the
     * highest number below the number of this version.
     */
    public function getPreviousForWithdrawal(): ?self
    {
        if (empty($this->item_id)) {
            return null;
        }

        $query = static::find()
            ->published()
            ->forItem($this->item_id)
            ->andWhere(['not', ['id' => $this->id]])
            ->limit(1);

        $type = $this->getItemType();
        if ($type !== null && !$type->has_validity_period) {
            return $query
                ->andWhere(['<', 'number', (int)$this->number])
                ->orderBy(['number' => SORT_DESC])
                ->one();
        }

        if ($this->valid_from === null || $this->valid_from === '') {
            return null;
        }

        return $query
            ->andWhere(['<', 'valid_from', $this->valid_from])
            ->orderBy(['valid_from' => SORT_DESC, 'number' => SORT_DESC])
            ->one();
    }

    /**
     * Publishes the version, see publish(); the note is the reason of the
     * history entry `approved` of a version in review.
     *
     * A correction takes over the (current) validity period of the corrected
     * version and withdraws it instead of ending the predecessor; the history
     * entry `corrected` (with the reason stored in the corrected version)
     * replaces the entry `published`.
     */
    private function publishVersion(?string $note): bool
    {
        $type = $this->getItemType();
        $inReview = $this->status === self::STATUS_IN_REVIEW;

        if ($this->status === self::STATUS_DRAFT) {
            if ($type === null || $type->requires_review) {
                $this->addError(
                    'status',
                    Yii::t('knowledge-library', 'This version must be reviewed before it can be published.')
                );

                return false;
            }
        } elseif (!$inReview) {
            $this->addError(
                'status',
                Yii::t('knowledge-library', 'Only a draft or a version in review can be published.')
            );

            return false;
        }

        if (!$this->checkNotArchived()) {
            return false;
        }

        if ($inReview && !$this->checkIsReviewer()) {
            return false;
        }

        $attributes = [
            'status' => self::STATUS_PUBLISHED,
            'published_by' => DefaultUserProvider::resolve()->getCurrentUserReference(),
            'published_at' => date(self::DATETIME_FORMAT),
        ];

        $corrected = null;
        if ($this->isCorrection()) {
            $corrected = static::findOne($this->corrects_version_id);
            if ($corrected === null || $corrected->status !== self::STATUS_PUBLISHED) {
                $this->addError(
                    'status',
                    Yii::t('knowledge-library', 'The corrected version is no longer published.')
                );

                return false;
            }
            $this->populateRelation('correctedVersion', $corrected);
            $attributes += $this->correctionPeriod();
        }

        return $this->transition(
            $attributes,
            fn () => ($corrected !== null ? $this->withdrawCorrected($corrected) : $this->endPredecessor())
                && $this->applyDraftDetails(),
            function () use ($inReview, $note, $corrected) {
                if ($inReview) {
                    $this->log(History::ACTION_APPROVED, $note, ['reviewer' => $this->reviewer_id]);
                }
                if ($corrected !== null) {
                    History::log($this->item, History::ACTION_CORRECTED, $corrected, $corrected->withdraw_reason, [
                        'number' => (int)$corrected->number,
                        'correction' => (int)$this->number,
                    ]);

                    return;
                }
                $this->log(
                    History::ACTION_PUBLISHED,
                    null,
                    $this->valid_from !== null ? ['valid_from' => $this->valid_from] : null
                );
            }
        );
    }

    /**
     * Current validity period of the corrected version as attributes of this
     * version, empty if this version is no correction. The period of the
     * corrected version may have changed since the correction was created,
     * e.g. by the withdrawal of a later version, so submitting and publishing
     * take it over again.
     *
     * @return array{valid_from?: string|null, valid_until?: string|null}
     */
    private function correctionPeriod(): array
    {
        if (!$this->isCorrection()) {
            return [];
        }

        $corrected = static::findOne($this->corrects_version_id);
        if ($corrected === null) {
            return [];
        }
        $this->populateRelation('correctedVersion', $corrected);

        return ['valid_from' => $corrected->valid_from, 'valid_until' => $corrected->valid_until];
    }

    /**
     * Withdraws the version corrected by this version on publication; the
     * reason stored by createCorrection() is kept.
     */
    private function withdrawCorrected(self $corrected): bool
    {
        $corrected->status = self::STATUS_WITHDRAWN;
        $corrected->withdrawn_by = DefaultUserProvider::resolve()->getCurrentUserReference();
        $corrected->withdrawn_at = date(self::DATETIME_FORMAT);

        if (!$corrected->save(false)) {
            $this->addError('status', Yii::t('knowledge-library', 'The corrected version could not be withdrawn.'));

            return false;
        }

        return true;
    }

    /**
     * Extends the previous version to the end of this (withdrawn) version,
     * see withdraw().
     */
    private function extendPrevious(self $previous): bool
    {
        $previous->valid_until = $this->valid_until;

        if (!$previous->save(false)) {
            $this->addError('status', Yii::t('knowledge-library', 'The previous version could not be extended.'));

            return false;
        }

        return true;
    }

    /**
     * Whether the current user is the reviewer of this version; adds an
     * error otherwise.
     */
    private function checkIsReviewer(): bool
    {
        $current = DefaultUserProvider::resolve()->getCurrentUserReference();
        if ($current === null || $current !== $this->reviewer_id) {
            $this->addError('status', Yii::t('knowledge-library', 'You are not the reviewer of this version.'));

            return false;
        }

        return true;
    }

    /**
     * Whether the reference is one of the reviewer options of the user
     * provider; adds an error otherwise.
     */
    private function checkReviewerOption(string $reviewerId): bool
    {
        if (!array_key_exists($reviewerId, DefaultUserProvider::resolve()->getReviewerOptions())) {
            $this->addError(
                'reviewer_id',
                Yii::t('knowledge-library', 'The selected person cannot review this version.')
            );

            return false;
        }

        return true;
    }

    /**
     * Whether the item of this version is not archived; adds an error
     * otherwise.
     */
    private function checkNotArchived(): bool
    {
        $item = $this->item;
        if ($item instanceof Item && $item->is_archived) {
            $this->addError('status', Item::archivedMessage());

            return false;
        }

        return true;
    }

    /**
     * Writes a history entry for this version, see History::log().
     *
     * @param array<string, mixed>|null $details
     */
    private function log(string $action, ?string $reason = null, ?array $details = null): History
    {
        return History::log($this->item, $action, $this, $reason, $details);
    }

    /**
     * Sets the given attributes, validates and saves in a transaction.
     *
     * On failure the previous attribute values are restored and the errors are
     * kept.
     *
     * @param callable|null $beforeSave called after a successful validation,
     * returning false aborts the transition
     * @param callable|null $afterSave called after saving, in the same
     * transaction, e.g. to write history entries; returning false or throwing
     * aborts the transition
     */
    private function transition(array $attributes, ?callable $beforeSave = null, ?callable $afterSave = null): bool
    {
        $previous = $this->getAttributes();
        $previousOld = $this->getOldAttributes();
        $scenario = $this->getScenario();
        // Transitions validate all rules, whatever step was edited last.
        $this->setScenario(self::SCENARIO_DEFAULT);
        $this->setAttributes($attributes, false);

        $transaction = static::getDb()->beginTransaction();
        try {
            if (
                $this->validate()
                && ($beforeSave === null || $beforeSave() !== false)
                && $this->save(false)
                && ($afterSave === null || $afterSave() !== false)
            ) {
                $transaction->commit();
                $this->setScenario($scenario);

                return true;
            }

            $transaction->rollBack();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $this->setAttributes($previous, false);
            $this->setOldAttributes($previousOld);
            $this->setScenario($scenario);

            throw $e;
        }

        // The version may have been saved before the transition failed.
        $this->setAttributes($previous, false);
        $this->setOldAttributes($previousOld);
        $this->setScenario($scenario);

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

        if ($this->isCorrection()) {
            // A correction replaces the corrected version instead of
            // following it, see withdrawCorrected().
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

    /**
     * Applies title, summary and topics of the draft to the item and clears
     * the draft fields; does nothing if the draft carries no details.
     */
    private function applyDraftDetails(): bool
    {
        if ($this->draft_title === null) {
            return true;
        }

        // A fresh instance, so a failed publication leaves the related item
        // of this version untouched.
        $item = Item::findOne($this->item_id);
        if ($item === null) {
            return true;
        }

        $item->title = $this->draft_title;
        $item->summary = $this->draft_summary;
        if ($this->draft_topic_ids !== null) {
            // A topic deleted since the draft was saved (a topic used by a
            // draft only can be deleted) is dropped instead of failing the
            // publication with an error the editor cannot see in the form.
            $topicIds = $this->getDraftTopicIds();
            $item->topicIds = $topicIds === []
                ? []
                : Topic::find()->select('id')->andWhere(['id' => $topicIds])->column();
        }

        if (!$item->validate(['title', 'summary', 'topicIds']) || !$item->save(false)) {
            foreach ($item->getFirstErrors() as $error) {
                $this->addError('draft_title', $error);
            }

            return false;
        }

        $this->populateRelation('item', $item);

        $this->draft_title = null;
        $this->draft_summary = null;
        $this->draft_topic_ids = null;

        return true;
    }

    private static function normalizeText(?string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
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
