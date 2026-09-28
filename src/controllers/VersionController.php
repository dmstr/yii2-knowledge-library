<?php

namespace dmstr\knowledgeLibrary\controllers;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
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
use yii\web\UploadedFile;

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
    public const BUTTON_SUBMIT = 'submit';

    /**
     * Fields of the review step for submitting a draft for approval.
     */
    public const FIELD_REVIEWER = 'reviewer';
    public const FIELD_MESSAGE = 'message';

    /**
     * Error key of Version under which the step content collects the
     * messages of rejected uploads.
     */
    public const FILES_ERROR_ATTRIBUTE = 'files';

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
            'review' => ['GET'],
            'approve' => ['POST'],
            'return' => ['POST'],
            'reviewer' => ['GET', 'POST'],
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
     * existing one. Not possible for archived items or while a version of the
     * item is in review (flash message on the detail page).
     *
     * @throws NotFoundHttpException
     */
    public function actionCreate(string $itemId): Response
    {
        $item = $this->findItem($itemId);
        $session = Yii::$app->getSession();

        if ($item->is_archived) {
            return $this->redirectArchived($item);
        }

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
     * review step. `submit` submits the draft for approval in the review step
     * (types with review only), with the fields `reviewer` (user reference)
     * and `message` (optional).
     *
     * A step whose preceding steps are incomplete (e.g. a manipulated URL)
     * redirects to the first incomplete step with its message. Drafts of
     * archived items cannot be edited.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionUpdate(string $id, $step = self::STEP_CONTENT)
    {
        $model = $this->findDraft($id);
        if ($model->item->is_archived) {
            return $this->redirectArchived($model->item);
        }
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

            if ($button === self::BUTTON_SUBMIT && $step === self::STEP_REVIEW) {
                $result = $this->submitDraft($model, $post);
                if ($result instanceof Response) {
                    return $result;
                }

                return $this->renderWizard($model, $step, null, $result);
            }

            $saved = $this->saveStep($model, $step, $post);

            if ($button === self::BUTTON_BACK) {
                return $this->redirect(['update', 'id' => $model->id, 'step' => max(self::STEP_CONTENT, $step - 1)]);
            }

            if ($saved && $button === self::BUTTON_SAVE) {
                History::log($model->item, History::ACTION_DRAFT_SAVED, $model);
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
        if ($model->item->is_archived) {
            return $this->redirectArchived($model->item);
        }

        $blocking = $this->findIncompleteStep($model, self::STEP_REVIEW - 1);
        if ($blocking !== null) {
            Yii::$app->getSession()->setFlash('error', $blocking['message']);

            return $this->redirect(['update', 'id' => $model->id, 'step' => $blocking['step']]);
        }

        return $this->publishDraft($model);
    }

    /**
     * Deletes the draft with its files; stored files no other version refers
     * to are deleted as well. Writes the history entry `draft_discarded` with
     * the number of the draft (the entry keeps no reference to the deleted
     * version).
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
            History::log($model->item, History::ACTION_DRAFT_DISCARDED, null, null, [
                'number' => (int)$model->number,
            ]);
            $session->setFlash('success', Yii::t('knowledge-library', 'Draft discarded.'));
        } else {
            $session->setFlash('error', Yii::t('knowledge-library', 'The draft could not be discarded.'));
        }

        return $this->redirect(['item/view', 'id' => $model->item_id]);
    }

    /**
     * Review page of a version in review: message of the submitter, validity,
     * consequences, topics, text and files, with the forms to return or to
     * approve the version. Only for the reviewer of the version.
     *
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException if the current user is not the reviewer
     */
    public function actionReview(string $id): string
    {
        $model = $this->findInReview($id);
        if (!$this->isReviewer($model)) {
            throw new ForbiddenHttpException(
                Yii::t('knowledge-library', 'You are not the reviewer of this version.')
            );
        }

        $topicIds = $model->draft_title === null ? $model->item->getTopicIds() : $model->getDraftTopicIds();

        return $this->render('review', [
            'model' => $model,
            'item' => $model->item,
            'check' => new ValidityCheck($model),
            'topics' => $topicIds === []
                ? []
                : Topic::find()->andWhere(['id' => $topicIds])->orderedByName()->select('name')->column(),
            'requestedByName' => $this->userName($model->review_requested_by),
        ]);
    }

    /**
     * Approves and publishes a version in review (body `note`, optional); only
     * the reviewer may approve, see Version::approve(). Not possible for
     * archived items.
     *
     * @throws NotFoundHttpException
     */
    public function actionApprove(string $id): Response
    {
        $model = $this->findInReview($id);
        if ($model->item->is_archived) {
            return $this->redirectArchived($model->item);
        }

        $session = Yii::$app->getSession();
        if (!$model->approve($this->postString('note'))) {
            $session->setFlash('error', implode(' ', $model->getFirstErrors()));

            return $this->redirectAfterReviewError($model);
        }

        $session->setFlash('success', Yii::t('knowledge-library', 'Version {number} approved and published.', [
            'number' => (int)$model->number,
        ]));

        return $this->redirect(['item/view', 'id' => $model->item_id, 'tab' => ItemController::TAB_VERSIONS]);
    }

    /**
     * Returns a version in review to its submitter as draft (body `note`,
     * required); only the reviewer may return it, see Version::returnToDraft().
     *
     * @throws NotFoundHttpException
     */
    public function actionReturn(string $id): Response
    {
        $model = $this->findInReview($id);
        $session = Yii::$app->getSession();

        if (!$model->returnToDraft($this->postString('note'))) {
            $session->setFlash('error', implode(' ', $model->getFirstErrors()));

            return $this->redirectAfterReviewError($model);
        }

        $session->setFlash('success', Yii::t('knowledge-library', 'Version {number} returned to {name}.', [
            'number' => (int)$model->number,
            'name' => $this->userName($model->review_requested_by),
        ]));

        return $this->redirect(['item/view', 'id' => $model->item_id]);
    }

    /**
     * Renders (GET) or saves (POST) the change of the reviewer of a version in
     * review: body `reviewer` (user reference) and `reason` (optional), see
     * Version::changeReviewer().
     *
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionReviewer(string $id)
    {
        $model = $this->findInReview($id);
        $reviewer = '';
        $reason = '';

        if ($this->request->getIsPost()) {
            $reviewer = $this->postString('reviewer');
            $reason = $this->postString('reason');
            if ($model->changeReviewer($reviewer, $reason)) {
                Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Review handed over to {name}.', [
                    'name' => $this->userName($model->reviewer_id),
                ]));

                return $this->redirect(['item/view', 'id' => $model->item_id]);
            }
        }

        $options = $this->module->getUserProvider()->getReviewerOptions();
        unset($options[(string)$model->reviewer_id], $options[(string)$model->review_requested_by]);

        return $this->render('reviewer', [
            'model' => $model,
            'item' => $model->item,
            'reviewerName' => $this->userName($model->reviewer_id),
            'reviewerOptions' => $options,
            'reviewer' => $reviewer,
            'reason' => $reason,
        ]);
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
     * Saves the step content: the Markdown text (`Version[content]`) and the
     * files of the draft.
     *
     * Files: `remove[<file-id>] = 1` removes a file of the draft (the stored
     * file only if no other version refers to it), `mainFiles[]` are new
     * main files, `attachments[<i>]` new attachments with the title
     * `attachmentTitles[<i>]` of the same index.
     *
     * Invalid text is not saved; the model keeps the errors. Rejected uploads
     * are neither stored nor saved, their messages are added as errors of
     * `files` (see FILES_ERROR_ATTRIBUTE) and the step counts as not saved,
     * so "Next" stays on the step; for "Back" they are shown as flash
     * message.
     */
    protected function saveContentStep(Version $model, array $post): bool
    {
        $model->setScenario(Version::SCENARIO_CONTENT);
        $model->load($post);
        $saved = $model->validate() && $model->save(false);

        $rejected = $this->saveContentFiles($model, $post);
        foreach ($rejected as $error) {
            $model->addError(self::FILES_ERROR_ATTRIBUTE, $error);
        }
        if ($rejected !== [] && $this->resolveButton($post) === self::BUTTON_BACK) {
            Yii::$app->getSession()->setFlash('error', implode(' ', $rejected));
        }

        return $saved && $rejected === [];
    }

    /**
     * Removes the files marked in `remove` and stores the uploads of the
     * step content.
     *
     * @return string[] messages of rejected uploads
     */
    private function saveContentFiles(Version $model, array $post): array
    {
        $service = new FileService($this->module);

        $remove = isset($post['remove']) && is_array($post['remove']) ? $post['remove'] : [];
        $removeIds = [];
        foreach ($remove as $fileId => $flag) {
            if ((string)$flag === '1') {
                $removeIds[] = (string)$fileId;
            }
        }
        if ($removeIds !== []) {
            // Only files of this draft can be removed.
            foreach (File::find()->where(['version_id' => $model->id, 'id' => $removeIds])->all() as $file) {
                $service->remove($file);
            }
        }

        $uploads = [];
        foreach (UploadedFile::getInstancesByName('mainFiles') as $upload) {
            $uploads[] = [$upload, File::KIND_MAIN, null];
        }
        $titles = isset($post['attachmentTitles']) && is_array($post['attachmentTitles']) ? $post['attachmentTitles'] : [];
        foreach (static::uploadsByIndex('attachments') as $index => $upload) {
            $title = $titles[$index] ?? null;
            $uploads[] = [$upload, File::KIND_ATTACHMENT, is_string($title) ? $title : null];
        }

        $rejected = [];
        foreach ($uploads as [$upload, $kind, $title]) {
            $file = $service->store($model, $upload, $kind, $title);
            if ($file->getIsNewRecord()) {
                $rejected[] = $file->getFirstError('name')
                    ?? (string)(array_values($file->getFirstErrors())[0] ?? '');
            }
        }

        if ($removeIds !== [] || $uploads !== []) {
            unset($model->files, $model->mainFiles, $model->attachments);
        }

        return array_values(array_filter($rejected, static fn (string $error) => $error !== ''));
    }

    /**
     * Uploaded files of a field `<name>[<index>]` by index, so they can be
     * matched with inputs of the same index (e.g. attachment titles). Empty
     * file inputs are skipped.
     *
     * @return array<int|string, UploadedFile>
     */
    private static function uploadsByIndex(string $name): array
    {
        $names = $_FILES[$name]['name'] ?? null;
        if (!is_array($names)) {
            return [];
        }

        $uploads = [];
        foreach (array_keys($names) as $index) {
            $upload = UploadedFile::getInstanceByName($name . '[' . $index . ']');
            if ($upload !== null) {
                $uploads[$index] = $upload;
            }
        }

        return $uploads;
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

    /**
     * Submits the draft for approval with the fields of the review step.
     *
     * @return Response|array{reviewer: string, message: string} the redirect
     * after submitting, else the entered values for rendering the step again
     * (the model carries the errors)
     * @throws ForbiddenHttpException for types without review
     */
    private function submitDraft(Version $model, array $post)
    {
        if (static::canPublishDirectly($model)) {
            throw new ForbiddenHttpException(
                Yii::t('knowledge-library', 'This version can be published without review.')
            );
        }

        $reviewer = $post[self::FIELD_REVIEWER] ?? null;
        $reviewer = is_string($reviewer) ? $reviewer : '';
        $message = $post[self::FIELD_MESSAGE] ?? null;
        $message = is_string($message) ? $message : '';

        if (!$model->submitForReview($reviewer, $message)) {
            return ['reviewer' => $reviewer, 'message' => $message];
        }

        Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Version {number} submitted for approval.', [
            'number' => (int)$model->number,
        ]));

        return $this->redirect(['item/view', 'id' => $model->item_id, 'tab' => ItemController::TAB_VERSIONS]);
    }

    /**
     * @param array{reviewer: string, message: string}|null $submit values
     * entered for submitting, null to prefill from the draft
     */
    private function renderWizard(Version $model, int $step, ?string $message, ?array $submit = null): string
    {
        $reachable = self::STEP_REVIEW;
        $incomplete = $this->findIncompleteStep($model, self::STEP_DETAILS);
        if ($incomplete !== null) {
            $reachable = $incomplete['step'];
        }

        $canPublish = static::canPublishDirectly($model);
        $reviewerOptions = [];
        if ($step === self::STEP_REVIEW && !$canPublish) {
            $users = $this->module->getUserProvider();
            $reviewerOptions = $users->getReviewerOptions();
            // Four-eyes principle: nobody reviews their own version.
            unset($reviewerOptions[(string)$users->getCurrentUserReference()]);
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
            'canPublish' => $canPublish,
            'reviewerOptions' => $reviewerOptions,
            'reviewer' => $submit['reviewer'] ?? (string)$model->reviewer_id,
            'reviewMessage' => $submit['message'] ?? (string)$model->review_message,
            'returnedByName' => $model->return_note === null || $model->return_note === ''
                ? null
                : $this->userName($model->returned_by),
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
        foreach ([self::BUTTON_PUBLISH, self::BUTTON_SUBMIT, self::BUTTON_SAVE, self::BUTTON_BACK] as $button) {
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
     * Finds a version in review with its item.
     *
     * @throws NotFoundHttpException
     */
    private function findInReview(string $id): Version
    {
        $model = Version::find()
            ->andWhere([Version::tableName() . '.[[id]]' => $id])
            ->andWhere([Version::tableName() . '.[[status]]' => Version::STATUS_IN_REVIEW])
            ->with('item.type')
            ->one();
        if ($model === null || $model->item === null) {
            throw new NotFoundHttpException(
                Yii::t('knowledge-library', 'The requested version in review does not exist.')
            );
        }

        return $model;
    }

    /**
     * Whether the current user is the reviewer of the version.
     */
    private function isReviewer(Version $model): bool
    {
        $current = $this->module->getUserProvider()->getCurrentUserReference();

        return $current !== null && $current !== '' && $current === $model->reviewer_id;
    }

    /**
     * After a failed approval or return: back to the review page for the
     * reviewer, to the detail page for everybody else.
     */
    private function redirectAfterReviewError(Version $model): Response
    {
        return $this->isReviewer($model)
            ? $this->redirect(['review', 'id' => $model->id])
            : $this->redirect(['item/view', 'id' => $model->item_id]);
    }

    /**
     * Flash "archived" and redirect to the detail page of the item.
     */
    private function redirectArchived(Item $item): Response
    {
        Yii::$app->getSession()->setFlash('error', Item::archivedMessage());

        return $this->redirect(['item/view', 'id' => $item->id]);
    }

    /**
     * Body parameter as string, empty if missing or not a string.
     */
    private function postString(string $name): string
    {
        $value = $this->request->post($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Display name of the user reference, the reference itself if unknown,
     * "–" without reference.
     */
    private function userName(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '–';
        }

        return $this->module->getUserProvider()->getDisplayName($reference) ?? $reference;
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
