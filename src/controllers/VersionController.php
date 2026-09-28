<?php

namespace dmstr\knowledgeLibrary\controllers;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\ValidityCheck;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use Throwable;
use Yii;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Wizard for a new version of a knowledge item: content, validity, details
 * and review, followed by publishing or saving as draft.
 *
 * The wizard works on the draft of the item (at most one per item). Each
 * step is saved in its own scenario of Version, so only the attributes of
 * the step can be changed. Only drafts are reachable, other versions are not
 * found.
 *
 * @property Module $module
 */
class VersionController extends BaseController
{
    public const STEP_CONTENT = 1;
    public const STEP_VALIDITY = 2;
    public const STEP_DETAILS = 3;
    public const STEP_REVIEW = 4;

    /**
     * Buttons of the wizard form, submitted as `<name>=1`.
     */
    public const BUTTON_SAVE = 'save';
    public const BUTTON_NEXT = 'next';
    public const BUTTON_BACK = 'back';
    public const BUTTON_PUBLISH = 'publish';

    /**
     * Scenario of Version by step; the review step has no fields.
     */
    private const STEP_SCENARIOS = [
        self::STEP_CONTENT => Version::SCENARIO_CONTENT,
        self::STEP_VALIDITY => Version::SCENARIO_VALIDITY,
        self::STEP_DETAILS => Version::SCENARIO_DETAILS,
    ];

    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'create' => ['POST'],
            'update' => ['GET', 'POST'],
            'publish' => ['POST'],
            'discard' => ['POST'],
        ]);
    }

    /**
     * @return array<int, string> map `step => label`
     */
    public static function steps(): array
    {
        return [
            self::STEP_CONTENT => Yii::t('knowledge-library', 'Content'),
            self::STEP_VALIDITY => Yii::t('knowledge-library', 'Validity'),
            self::STEP_DETAILS => Yii::t('knowledge-library', 'Details'),
            self::STEP_REVIEW => Yii::t('knowledge-library', 'Check'),
        ];
    }

    /**
     * Starts the wizard: creates the draft of the item or continues the
     * existing one.
     *
     * @throws NotFoundHttpException
     */
    public function actionCreate(string $itemId): Response
    {
        $item = $this->findItem($itemId);
        $session = Yii::$app->getSession();

        $existing = Version::find()
            ->forItem($item->id)
            ->andWhere(['status' => Version::STATUS_DRAFT])
            ->one();
        if ($existing !== null) {
            $session->setFlash('info', Yii::t('knowledge-library', 'This item already has a draft.'));

            return $this->redirect(['update', 'id' => $existing->id, 'step' => self::STEP_CONTENT]);
        }

        $draft = Version::createDraft($item);
        if ($draft->getIsNewRecord()) {
            $session->setFlash('error', implode(' ', $draft->getFirstErrors()));

            return $this->redirect(['item/view', 'id' => $item->id]);
        }

        return $this->redirect(['update', 'id' => $draft->id, 'step' => self::STEP_CONTENT]);
    }

    /**
     * Renders (GET) or saves (POST) a step of the wizard.
     *
     * POST buttons: `save` saves the step and returns to the detail page,
     * `next` saves and continues if the step is complete, `back` returns to
     * the previous step (saving only valid input), `publish` publishes in the
     * review step.
     *
     * A step whose preceding steps are incomplete (e.g. a manipulated URL)
     * redirects to the first incomplete step with its message.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionUpdate(string $id, $step = self::STEP_CONTENT)
    {
        $model = $this->findDraft($id);
        $step = static::normalizeStep($step);

        $blocking = $this->findIncompleteStep($model, $step - 1);
        if ($blocking !== null) {
            Yii::$app->getSession()->setFlash('error', $blocking['message']);

            return $this->redirect(['update', 'id' => $model->id, 'step' => $blocking['step']]);
        }

        $message = null;
        if ($this->request->getIsPost()) {
            $post = $this->request->post();
            $button = $this->resolveButton($post);

            if ($button === self::BUTTON_PUBLISH && $step === self::STEP_REVIEW) {
                return $this->publishDraft($model);
            }

            $saved = $this->saveStep($model, $step, $post);

            if ($button === self::BUTTON_BACK) {
                return $this->redirect(['update', 'id' => $model->id, 'step' => max(self::STEP_CONTENT, $step - 1)]);
            }

            if ($saved && $button === self::BUTTON_SAVE) {
                Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Draft saved.'));

                return $this->redirect(['item/view', 'id' => $model->item_id]);
            }

            if ($saved && $button === self::BUTTON_NEXT && $step < self::STEP_REVIEW) {
                $message = $this->stepMessage($model, $step);
                if ($message === null) {
                    return $this->redirect(['update', 'id' => $model->id, 'step' => $step + 1]);
                }
            }
        }

        return $this->renderWizard($model, $step, $message);
    }

    /**
     * Publishes the draft; only for types without review.
     *
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionPublish(string $id): Response
    {
        $model = $this->findDraft($id);

        $blocking = $this->findIncompleteStep($model, self::STEP_REVIEW - 1);
        if ($blocking !== null) {
            Yii::$app->getSession()->setFlash('error', $blocking['message']);

            return $this->redirect(['update', 'id' => $model->id, 'step' => $blocking['step']]);
        }

        return $this->publishDraft($model);
    }

    /**
     * Deletes the draft with its files; stored files no other version refers
     * to are deleted as well.
     *
     * @throws NotFoundHttpException
     */
    public function actionDiscard(string $id): Response
    {
        $model = $this->findDraft($id);
        $session = Yii::$app->getSession();
        $service = new FileService($this->module);

        try {
            foreach ($model->files as $file) {
                $service->remove($file);
            }
            $deleted = $model->delete() !== false;
        } catch (Throwable $e) {
            $deleted = false;
            Yii::warning("Draft $model->id not discarded: {$e->getMessage()}", 'knowledge-library');
        }

        if ($deleted) {
            $session->setFlash('success', Yii::t('knowledge-library', 'Draft discarded.'));
        } else {
            $session->setFlash('error', Yii::t('knowledge-library', 'The draft could not be discarded.'));
        }

        return $this->redirect(['item/view', 'id' => $model->item_id]);
    }

    /**
     * Title of the wizard: "Create first version" for an item without other
     * versions, "Create version n" otherwise.
     */
    public static function wizardTitle(Version $model): string
    {
        $hasOtherVersions = Version::find()
            ->forItem($model->item_id)
            ->andWhere(['not', ['id' => $model->id]])
            ->andWhere(['not', ['status' => Version::STATUS_DRAFT]])
            ->exists();

        return $hasOtherVersions
            ? Yii::t('knowledge-library', 'Create version {number}', ['number' => (int)$model->number])
            : Yii::t('knowledge-library', 'Create first version');
    }

    /**
     * Confirmation text of "Publish": Valid From, consequences and content,
     * e.g. "Publish version 2? / Valid From: 01.01.2027 / Consequences: ends
     * version 1 on 31.12.2026, becomes the version "in force" from
     * 01.01.2027 / Content: Text, 1 main document".
     *
     * @param string|null $today date in the format `Y-m-d`, today if null
     */
    public static function publishConfirmation(Version $model, ?ValidityCheck $check = null, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        $check ??= new ValidityCheck($model, $today);
        $formatter = Yii::$app->formatter;

        $lines = [Yii::t('knowledge-library', 'Publish version {number}?', ['number' => (int)$model->number])];
        if ($check->isValid()) {
            $validFrom = (string)$check->getValidFrom();
            $lines[] = Yii::t('knowledge-library', 'Valid From') . ': ' . $formatter->asDate($validFrom);

            $consequences = [];
            $predecessor = $check->getPredecessor();
            if (
                $predecessor !== null
                && $predecessor->valid_from !== null
                && $predecessor->valid_from < $validFrom
                && ($predecessor->valid_until === null || $predecessor->valid_until >= $validFrom)
            ) {
                $consequences[] = Yii::t('knowledge-library', 'ends version {number} on {date}', [
                    'number' => (int)$predecessor->number,
                    'date' => $formatter->asDate((new DateTimeImmutable($validFrom))->modify('-1 day')->format('Y-m-d')),
                ]);
            }
            $consequences[] = $validFrom > $today
                ? Yii::t('knowledge-library', 'becomes the version "in force" from {date}', [
                    'date' => $formatter->asDate($validFrom),
                ])
                : Yii::t('knowledge-library', 'is in force immediately');
            $lines[] = Yii::t('knowledge-library', 'Consequences') . ': ' . implode(', ', $consequences);
        }
        $lines[] = Yii::t('knowledge-library', 'Content') . ': ' . $model->getContentSummary();

        return implode("\n", $lines);
    }

    /**
     * Whether the draft may be published directly: its type requires no
     * review.
     */
    public static function canPublishDirectly(Version $model): bool
    {
        $type = $model->item instanceof Item ? $model->item->type : null;

        return $type !== null && !$type->requires_review;
    }

    /**
     * Loads and saves the fields of the step in its scenario. Invalid input
     * is not saved, the model keeps the errors.
     */
    protected function saveStep(Version $model, int $step, array $post): bool
    {
        if ($step === self::STEP_CONTENT) {
            return $this->saveContentStep($model, $post);
        }

        $scenario = self::STEP_SCENARIOS[$step] ?? null;
        if ($scenario === null) {
            return true;
        }

        $model->setScenario($scenario);
        $model->load($post);
        if ($step === self::STEP_DETAILS && isset($post[$model->formName()]) && is_array($post[$model->formName()])
            && !array_key_exists('draftTopicIds', $post[$model->formName()])) {
            // An empty multiple select submits nothing.
            $model->setDraftTopicIds([]);
        }

        if (!$model->validate()) {
            return false;
        }

        return $model->save(false);
    }

    /**
     * Saves the step content: the Markdown text (`Version[content]`).
     *
     * Invalid text is not saved; the model keeps the errors.
     */
    protected function saveContentStep(Version $model, array $post): bool
    {
        $model->setScenario(Version::SCENARIO_CONTENT);
        $model->load($post);
        if (!$model->validate()) {
            return false;
        }

        return $model->save(false);
    }

    /**
     * Message why the step is not complete, null if it is.
     *
     * Content: text or main file; validity: dates valid for the type and
     * the predecessor; details: title, summary and topics valid.
     */
    protected function stepMessage(Version $model, int $step): ?string
    {
        switch ($step) {
            case self::STEP_CONTENT:
                return $model->hasContent() ? null : Version::noContentMessage();
            case self::STEP_VALIDITY:
                $errors = (new ValidityCheck($model))->getErrors();
                if ($errors !== []) {
                    return $errors[0];
                }

                return $this->firstValidationError($model, Version::SCENARIO_VALIDITY);
            case self::STEP_DETAILS:
                return $this->firstValidationError($model, Version::SCENARIO_DETAILS);
        }

        return null;
    }

    /**
     * First step up to the given one that is not complete.
     *
     * @return array{step: int, message: string}|null
     */
    protected function findIncompleteStep(Version $model, int $lastStep): ?array
    {
        for ($step = self::STEP_CONTENT; $step <= min($lastStep, self::STEP_DETAILS); $step++) {
            $message = $this->stepMessage($model, $step);
            if ($message !== null) {
                return ['step' => $step, 'message' => $message];
            }
        }

        return null;
    }

    /**
     * @throws ForbiddenHttpException for types that require a review
     */
    private function publishDraft(Version $model): Response
    {
        if (!static::canPublishDirectly($model)) {
            throw new ForbiddenHttpException(
                Yii::t('knowledge-library', 'This version must be reviewed before it can be published.')
            );
        }

        $session = Yii::$app->getSession();
        if (!$model->publish()) {
            $session->setFlash('error', implode(' ', $model->getFirstErrors()));

            return $this->redirect(['update', 'id' => $model->id, 'step' => self::STEP_REVIEW]);
        }

        $session->setFlash('success', Yii::t('knowledge-library', 'Version {number} published.', [
            'number' => (int)$model->number,
        ]));

        return $this->redirect(['item/view', 'id' => $model->item_id, 'tab' => 'versions']);
    }

    private function renderWizard(Version $model, int $step, ?string $message): string
    {
        $reachable = self::STEP_REVIEW;
        $incomplete = $this->findIncompleteStep($model, self::STEP_DETAILS);
        if ($incomplete !== null) {
            $reachable = $incomplete['step'];
        }

        return $this->render('wizard', [
            'model' => $model,
            'item' => $model->item,
            'step' => $step,
            'steps' => static::steps(),
            'reachable' => max($reachable, $step),
            'message' => $message,
            'blocked' => $step < self::STEP_REVIEW && $this->stepMessage($model, $step) !== null,
            'check' => $step === self::STEP_VALIDITY || $step === self::STEP_REVIEW ? new ValidityCheck($model) : null,
            'title' => static::wizardTitle($model),
            'canPublish' => static::canPublishDirectly($model),
            'topicOptions' => $step === self::STEP_DETAILS || $step === self::STEP_REVIEW
                ? ArrayHelper::map(Topic::find()->orderedByName()->all(), 'id', 'name')
                : [],
        ]);
    }

    /**
     * Validates the attributes of the scenario on a copy, so the model keeps
     * its state and errors.
     */
    private function firstValidationError(Version $model, string $scenario): ?string
    {
        $copy = clone $model;
        $copy->clearErrors();
        $copy->setScenario($scenario);
        if ($copy->validate()) {
            return null;
        }

        $errors = $copy->getFirstErrors();

        return $errors === [] ? null : (string)reset($errors);
    }

    private function resolveButton(array $post): string
    {
        foreach ([self::BUTTON_PUBLISH, self::BUTTON_SAVE, self::BUTTON_BACK] as $button) {
            if (isset($post[$button])) {
                return $button;
            }
        }

        return self::BUTTON_NEXT;
    }

    private static function normalizeStep($step): int
    {
        $step = is_scalar($step) && ctype_digit((string)$step) ? (int)$step : self::STEP_CONTENT;

        return min(self::STEP_REVIEW, max(self::STEP_CONTENT, $step));
    }

    /**
     * Finds a draft; the details are prefilled from the item if the draft
     * has none yet.
     *
     * @throws NotFoundHttpException
     */
    private function findDraft(string $id): Version
    {
        $model = Version::find()
            ->andWhere([Version::tableName() . '.[[id]]' => $id])
            ->andWhere([Version::tableName() . '.[[status]]' => Version::STATUS_DRAFT])
            ->with('item.type')
            ->one();
        if ($model === null || $model->item === null) {
            throw new NotFoundHttpException(Yii::t('knowledge-library', 'The requested draft does not exist.'));
        }

        if ($model->draft_title === null) {
            $model->draft_title = $model->item->title;
            $model->draft_summary = $model->item->summary;
            $model->setDraftTopicIds($model->item->getTopicIds());
        }

        return $model;
    }

    /**
     * @throws NotFoundHttpException
     */
    private function findItem(string $id): Item
    {
        $model = Item::find()->andWhere([Item::tableName() . '.[[id]]' => $id])->with('type')->one();
        if ($model === null) {
            throw new NotFoundHttpException(
                Yii::t('knowledge-library', 'The requested knowledge object does not exist.')
            );
        }

        return $model;
    }
}
