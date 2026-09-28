<?php

namespace dmstr\knowledgeLibrary\controllers;

use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ItemState;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\models\search\ItemSearch;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use Throwable;
use Yii;
use yii\db\Exception as DbException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Library of the knowledge items: list, detail page, master data, source and
 * deletion.
 *
 * @property Module $module
 */
class ItemController extends BaseController
{
    public const TAB_VERSIONS = 'versions';
    public const TAB_CONTENT = 'content';
    public const TAB_RELATIONS = 'relations';
    public const TAB_SOURCE = 'source';
    public const TAB_HISTORY = 'history';

    /**
     * Tabs of the detail page, in display order.
     */
    public const TABS = [
        self::TAB_VERSIONS,
        self::TAB_CONTENT,
        self::TAB_RELATIONS,
        self::TAB_SOURCE,
        self::TAB_HISTORY,
    ];

    /**
     * Master data attributes; a change writes the history entry
     * `master_data_changed`.
     */
    private const MASTER_DATA_ATTRIBUTES = ['title', 'type_id'];

    /**
     * Source attributes; a change writes the history entry `source_changed`.
     */
    private const SOURCE_ATTRIBUTES = ['source_name', 'source_reference', 'source_url', 'source_import_mode'];

    protected function verbs(): array
    {
        return array_merge(parent::verbs(), [
            'archive' => ['POST'],
            'restore' => ['POST'],
        ]);
    }

    /**
     * Lists the items with filters, sorting and the state of each item.
     *
     * The link "Awaiting my approval" filters the items with a version in
     * review by the current user (`ItemSearch[review]=mine`); it shows the
     * number of these (not archived) items and is offered to users who may
     * review and whenever the filter is active.
     */
    public function actionIndex(): string
    {
        $searchModel = new ItemSearch();
        $dataProvider = $searchModel->search($this->request->get());
        $items = $dataProvider->getModels();

        // The hint for an empty library replaces the grid only if nothing is
        // filtered, otherwise the grid shows its empty row.
        $isEmpty = $items === [] && !$searchModel->isFiltered() && !Item::find()->exists();

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'states' => ItemState::forItems($items),
            'typeOptions' => $this->typeOptions(),
            'topicOptions' => ArrayHelper::map(Topic::find()->orderedByName()->all(), 'id', 'name'),
            'isEmpty' => $isEmpty,
            'showAwaitingReview' => $searchModel->review === ItemSearch::REVIEW_MINE || $this->canRoute('version', 'review'),
            'awaitingReviewCount' => $this->countAwaitingReview(),
        ]);
    }

    /**
     * @return string|Response
     */
    public function actionCreate()
    {
        // No loadDefaultValues(): the database defaults apply on insert, and
        // SQLite reports the default of nullable columns as the string 'NULL'.
        $model = new Item(['scenario' => Item::SCENARIO_CREATE]);

        if ($model->load($this->request->post()) && $model->validate() && $this->saveWithHistory($model, History::ACTION_CREATED)) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Knowledge object created.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        // Prefill of the title, e.g. from the duplicate hint of an attachment.
        $title = $this->request->get('title');
        if ($this->request->getIsGet() && is_string($title)) {
            $model->title = trim($title);
        }

        return $this->render('create', [
            'model' => $model,
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    /**
     * Detail page with the header, the validity timeline and the tabs of the
     * item.
     *
     * `tab` selects the active tab (see TABS, default versions), `version`
     * the number of the version shown in the tab content; without or with an
     * unknown number the version valid today is shown, else the highest.
     *
     * The header shows the version in review (with "Change reviewer" for
     * admins and "Review" for its reviewer) and the note of a returned draft;
     * the tab history lists the history entries, newest first. Admins get
     * "Archive" or "Restore"; archived items offer no new version and no
     * correction. The tab versions offers "Correct", "Withdraw" and
     * "Approve" per version, depending on its state and the route rights.
     *
     * @param string|null $version number of the version shown in the tab content
     * @param string|null $tab active tab
     * @throws NotFoundHttpException
     */
    public function actionView(string $id, $version = null, $tab = null): string
    {
        $model = $this->findModel($id);
        $uploadedBy = $model->source_uploaded_by;

        /** @var Version[] $versions */
        $versions = $model->getVersions()
            ->andWhere(['not', ['status' => Version::STATUS_DRAFT]])
            ->orderBy(['number' => SORT_DESC])
            ->all();
        foreach ($versions as $entry) {
            $entry->populateRelation('item', $model);
        }
        $draft = $model->getVersions()->andWhere(['status' => Version::STATUS_DRAFT])->one();
        $pendingVersion = null;
        foreach ($versions as $entry) {
            if ($entry->status === Version::STATUS_IN_REVIEW) {
                $pendingVersion = $entry;
                break;
            }
        }
        $users = $this->module->getUserProvider();
        $currentUser = $users->getCurrentUserReference();

        $outgoing = $model->getOutgoingRelations()->with('targetItem')->all();
        $incoming = $model->getIncomingRelations()->with('sourceItem')->all();
        $excluded = array_merge([$model->id], array_map(static fn (Relation $relation) => $relation->target_item_id, $outgoing));

        return $this->render('view', [
            'model' => $model,
            'canDelete' => $this->canDelete(),
            'canArchive' => $this->canArchive($model),
            'canCorrectRoute' => $this->canRoute('version', 'correct'),
            'canWithdrawRoute' => $this->canRoute('version', 'withdraw'),
            'uploadedByName' => $uploadedBy === null || $uploadedBy === ''
                ? null
                : ($users->getDisplayName($uploadedBy) ?? $uploadedBy),
            'pendingVersion' => $pendingVersion,
            'pendingReviewerName' => $pendingVersion === null ? null : $this->userName($pendingVersion->reviewer_id),
            'isReviewer' => $pendingVersion !== null && $currentUser !== null && $currentUser !== ''
                && $currentUser === $pendingVersion->reviewer_id,
            'canChangeReviewer' => $pendingVersion !== null && $this->canRoute('version', 'reviewer'),
            'returnedByName' => $draft === null || $draft->return_note === null || $draft->return_note === ''
                ? null
                : $this->userName($draft->returned_by),
            'history' => $model->getHistory()->with('version')->all(),
            'userProvider' => $users,
            'activeTab' => is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::TAB_VERSIONS,
            'versions' => $versions,
            'draft' => $draft,
            'selectedVersion' => $this->selectVersion($model, $versions, $version),
            'outgoing' => $outgoing,
            'incoming' => $incoming,
            'targetOptions' => ArrayHelper::map(
                Item::find()
                    ->andWhere(['not', [Item::tableName() . '.[[id]]' => $excluded]])
                    ->orderBy(['title' => SORT_ASC])
                    ->all(),
                'id',
                'title'
            ),
        ]);
    }

    /**
     * Edits the master data (title and type); the type is locked once the
     * item has versions.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionUpdate(string $id)
    {
        $model = $this->findModel($id);
        $model->setScenario(Item::SCENARIO_UPDATE);

        if ($model->load($this->request->post()) && $model->validate() && $this->saveWithHistory(
            $model,
            $this->hasChanged($model, self::MASTER_DATA_ATTRIBUTES) ? History::ACTION_MASTER_DATA_CHANGED : null
        )) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Knowledge object saved.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', [
            'model' => $model,
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    /**
     * Edits source and origin of the item.
     *
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionSource(string $id)
    {
        $model = $this->findModel($id);
        $model->setScenario(Item::SCENARIO_SOURCE);

        if ($model->load($this->request->post()) && $model->validate() && $this->saveWithHistory(
            $model,
            $this->hasChanged($model, self::SOURCE_ATTRIBUTES) ? History::ACTION_SOURCE_CHANGED : null
        )) {
            Yii::$app->getSession()->setFlash('success', Yii::t('knowledge-library', 'Source & origin saved.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('source', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes the item with all versions, file entries, relations, history
     * and topic assignments (cascading foreign keys), then the stored files
     * no other item refers to.
     *
     * The deletion is logged afterwards with the numbers counted before. A
     * stored file that cannot be deleted does not stop the deletion of the
     * item; it is logged as warning and counted as failed.
     *
     * @throws NotFoundHttpException
     */
    public function actionDelete(string $id): Response
    {
        $model = $this->findModel($id);
        $session = Yii::$app->getSession();
        $service = new FileService($this->module);

        $versionCount = (int)$model->getVersions()->count();
        $fileCount = (int)File::find()
            ->innerJoinWith('version', false)
            ->andWhere([Version::tableName() . '.[[item_id]]' => $model->id])
            ->count();
        $storagePaths = $service->collectStoragePaths($model);

        try {
            $deleted = $model->delete() !== false;
        } catch (DbException $e) {
            $deleted = false;
            Yii::warning("Knowledge item $model->id not deleted: {$e->getMessage()}", 'knowledge-library');
        }

        if (!$deleted) {
            $session->setFlash('error', Yii::t('knowledge-library', 'The knowledge object could not be deleted.'));

            return $this->redirect(['view', 'id' => $model->id]);
        }

        $storage = $service->deleteStoragePaths($storagePaths);
        if ($storage['failed'] > 0) {
            Yii::warning(sprintf(
                'Knowledge item %s deleted, but %d stored file(s) could not be deleted',
                $model->id,
                $storage['failed']
            ), 'knowledge-library');
        }

        Yii::info(sprintf(
            'Deleted knowledge item %s "%s" with %d version(s) and %d file row(s);'
            . ' stored files: %d deleted, %d kept, %d failed; user %s, at %s',
            $model->id,
            $model->title,
            $versionCount,
            $fileCount,
            $storage['deleted'],
            $storage['kept'],
            $storage['failed'],
            $this->module->getUserProvider()->getCurrentUserReference() ?? '-',
            date('c')
        ), 'knowledge-library');

        $session->setFlash('success', Yii::t('knowledge-library', 'Knowledge object "{title}" deleted.', [
            'title' => Html::encode($model->title),
        ]));

        return $this->redirect(['index']);
    }

    /**
     * Archives the item (body `reason`, optional), see Item::archive(). Open
     * drafts and reviews are kept; while archived, no new versions can be
     * created, corrected, continued, approved or published.
     *
     * @throws NotFoundHttpException
     */
    public function actionArchive(string $id): Response
    {
        $model = $this->findModel($id);

        return $this->redirectArchiveResult($model, $model->archive($this->postReason()), Yii::t(
            'knowledge-library',
            'Knowledge object archived.'
        ));
    }

    /**
     * Restores an archived item (body `reason`, optional), see
     * Item::restore().
     *
     * @throws NotFoundHttpException
     */
    public function actionRestore(string $id): Response
    {
        $model = $this->findModel($id);

        return $this->redirectArchiveResult($model, $model->restore($this->postReason()), Yii::t(
            'knowledge-library',
            'Knowledge object restored.'
        ));
    }

    /**
     * Flash message of archiving or restoring (the errors of the item on
     * failure) and redirect to the detail page.
     */
    private function redirectArchiveResult(Item $model, bool $done, string $message): Response
    {
        $session = Yii::$app->getSession();
        if ($done) {
            $session->setFlash('success', $message);
        } else {
            $session->setFlash('error', implode(' ', $model->getFirstErrors()));
        }

        return $this->redirect(['view', 'id' => $model->id]);
    }

    /**
     * Body parameter `reason`, null if missing or empty.
     */
    private function postReason(): ?string
    {
        $reason = $this->request->post('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * Version shown in the tab content: the one with the given number, else
     * the version valid today, else the highest.
     *
     * @param Version[] $versions versions without drafts, highest number first
     * @param mixed $number number from the query
     */
    private function selectVersion(Item $item, array $versions, $number): ?Version
    {
        if (is_string($number) && ctype_digit($number)) {
            foreach ($versions as $version) {
                if ((int)$version->number === (int)$number) {
                    return $version;
                }
            }
        }

        $valid = $item->getValidVersion();
        if ($valid !== null) {
            foreach ($versions as $version) {
                if ($version->id === $valid->id) {
                    return $version;
                }
            }
        }

        return $versions[0] ?? null;
    }

    /**
     * Whether the current user may delete items, checked like the access
     * control of the module (`AccessBehaviorTrait`).
     */
    public function canDelete(): bool
    {
        return $this->canRoute($this->id, 'delete');
    }

    /**
     * Whether the current user may archive the item, or restore it if it is
     * archived, checked like the access control of the module.
     */
    public function canArchive(Item $model): bool
    {
        return $this->canRoute($this->id, $model->is_archived ? 'restore' : 'archive');
    }

    /**
     * Number of not archived items with a version in review by the current
     * user, 0 without current user.
     */
    private function countAwaitingReview(): int
    {
        $reference = $this->module->getUserProvider()->getCurrentUserReference();
        if ($reference === null || $reference === '') {
            return 0;
        }

        return (int)Item::find()->active()->awaitingReviewBy($reference)->count();
    }

    /**
     * Saves the validated item and writes the history entry (if any) in one
     * transaction.
     *
     * @param string|null $action history action, null for none
     */
    private function saveWithHistory(Item $model, ?string $action): bool
    {
        $transaction = Item::getDb()->beginTransaction();
        try {
            if (!$model->save(false)) {
                $transaction->rollBack();

                return false;
            }
            if ($action !== null) {
                History::log($model, $action);
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Whether any of the attributes differs from the stored value; null and
     * an empty string count as equal.
     *
     * @param string[] $attributes
     */
    private function hasChanged(Item $model, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if ((string)$model->getOldAttribute($attribute) !== (string)$model->getAttribute($attribute)) {
                return true;
            }
        }

        return false;
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
     * @return array<string, string> map `type ID => name`, ordered by name
     */
    private function typeOptions(): array
    {
        return ArrayHelper::map(Type::find()->orderedByName()->all(), 'id', 'name');
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function findModel(string $id): Item
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
