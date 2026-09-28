<?php

/**
 * Detail page of an item: header with type and archive badge, actions and
 * the tabs versions, content, relations, source & origin and history.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var bool $canDelete
 * @var string|null $uploadedByName display name of the user who uploaded the source
 * @var string $activeTab key of the active tab
 * @var dmstr\knowledgeLibrary\models\Version[] $versions versions without drafts, highest number first
 * @var dmstr\knowledgeLibrary\models\Version|null $draft
 * @var dmstr\knowledgeLibrary\models\Version|null $selectedVersion version of the tab content
 * @var dmstr\knowledgeLibrary\models\Relation[] $outgoing
 * @var dmstr\knowledgeLibrary\models\Relation[] $incoming
 * @var array<string, string> $targetOptions items that can become relation targets
 */

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\widgets\ValidityTimeline;
use yii\bootstrap\BootstrapPluginAsset;
use yii\helpers\Html;
use yii\web\YiiAsset;

YiiAsset::register($this);
BootstrapPluginAsset::register($this);

$this->title = $model->title;
$this->context->setBreadcrumbs([$this->title]);

$this->registerCss(<<<'CSS'
.knowledge-library-item-view .knowledge-library-item-header { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; }
.knowledge-library-item-view .knowledge-library-item-header h1 { margin: 0; }
.knowledge-library-item-view .knowledge-library-item-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 16px; }
.knowledge-library-item-view .knowledge-library-item-actions .knowledge-library-spacer { flex: 1; }
.knowledge-library-item-view .knowledge-library-item-head { margin-bottom: 20px; }
.knowledge-library-item-view .tab-content { padding-top: 15px; }
CSS);

$badgeStyle = 'display: inline-block; padding: 3px 7px 4px; font-size: 12px; font-weight: 700;'
    . ' border-radius: 3px; line-height: 1.2; ';
$empty = '–';
$display = static fn ($value) => $value === null || $value === '' ? $empty : Html::encode($value);
$importModes = Item::sourceImportModes();

$tabs = [
    'versions' => Yii::t('knowledge-library', 'Versions'),
    'content' => Yii::t('knowledge-library', 'Content'),
    'relations' => Yii::t('knowledge-library', 'Relations'),
    'source' => Yii::t('knowledge-library', 'Source & origin'),
    'history' => Yii::t('knowledge-library', 'History'),
];
$placeholders = [
    'history' => Yii::t('knowledge-library', 'The change history will be available in a later release.'),
];

$hasVersionInReview = false;
foreach ($versions as $version) {
    $hasVersionInReview = $hasVersionInReview || $version->status === Version::STATUS_IN_REVIEW;
}
$topicNames = array_map(static fn ($topic) => $topic->name, $model->topics);
sort($topicNames);
$timeline = $versions === [] ? '' : ValidityTimeline::widget(['item' => $model]);
$actionStyle = 'display: inline-flex; align-items: center; gap: 6px; color: #fff; font-weight: 600; ';
?>
<div class="knowledge-library-item-view">
<div class="knowledge-library-item-head">
    <div class="knowledge-library-item-header">
        <h1><?= Html::encode($model->title) ?></h1>
        <?php if ($model->type !== null): ?>
            <?= Html::tag('span', Html::encode($model->type->name), [
                'class' => 'knowledge-library-badge knowledge-library-badge-type',
                'style' => $badgeStyle . 'background: #061d42; color: #fff',
            ]) ?>
        <?php endif ?>
        <?php if ($model->is_archived): ?>
            <?= Html::tag('span', Html::encode(Yii::t('knowledge-library', 'Archived')), [
                'class' => 'knowledge-library-badge knowledge-library-badge-archived',
                'style' => $badgeStyle . 'background: #d2d6de; color: #444',
            ]) ?>
        <?php endif ?>
    </div>

    <div class="knowledge-library-item-actions">
        <?php if ($draft !== null): ?>
            <?= Html::a(
                '<i class="fa fa-pencil"></i> ' . Html::encode(Yii::t('knowledge-library', 'Continue draft')),
                ['version/update', 'id' => $draft->id, 'step' => 1],
                [
                    'class' => 'btn knowledge-library-item-continue-draft',
                    'style' => $actionStyle . 'background: #f39c12; border: 1px solid #e08e0b',
                ]
            ) ?>
            <?= Html::a(
                '<i class="fa fa-times"></i> ' . Html::encode(Yii::t('knowledge-library', 'Discard draft')),
                ['version/discard', 'id' => $draft->id],
                [
                    'class' => 'btn btn-default knowledge-library-item-discard-draft',
                    'data-method' => 'post',
                    'data-confirm' => Yii::t('knowledge-library', 'Discard the draft of version {number}?', [
                        'number' => (int)$draft->number,
                    ]),
                ]
            ) ?>
        <?php elseif (!$hasVersionInReview): ?>
            <?= Html::a(
                '<i class="fa fa-plus"></i> ' . Html::encode($versions === []
                    ? Yii::t('knowledge-library', 'Create first version')
                    : Yii::t('knowledge-library', 'New version')),
                ['version/create', 'itemId' => $model->id],
                [
                    'class' => 'btn knowledge-library-item-new-version',
                    'style' => $actionStyle . 'background: #00a65a; border: 1px solid #008d4c',
                    'data-method' => 'post',
                ]
            ) ?>
        <?php endif ?>
        <?= Html::a(
            '<i class="fa fa-pencil"></i> ' . Html::encode(Yii::t('knowledge-library', 'Edit')),
            ['update', 'id' => $model->id],
            ['class' => 'btn btn-default knowledge-library-item-edit']
        ) ?>
        <?php if ($canDelete): ?>
            <?= Html::a(
                '<i class="fa fa-trash"></i> ' . Html::encode(Yii::t('knowledge-library', 'Delete')),
                ['delete', 'id' => $model->id],
                [
                    'class' => 'btn btn-danger knowledge-library-item-delete',
                    'data-method' => 'post',
                    'data-confirm' => Yii::t(
                        'knowledge-library',
                        'Delete "{title}" together with all versions, file entries, relations and history?',
                        ['title' => $model->title]
                    ),
                ]
            ) ?>
        <?php endif ?>
        <span class="knowledge-library-spacer"></span>
        <?= Html::a(
            '<i class="fa fa-list"></i> ' . Html::encode(Yii::t('knowledge-library', 'Full list')),
            ['index'],
            ['class' => 'btn btn-default knowledge-library-item-list']
        ) ?>
    </div>
    <hr>
    <div class="knowledge-library-item-meta" style="display: flex; flex-wrap: wrap; gap: 6px 26px; color: #777">
        <span class="knowledge-library-item-topics">
            <?= Html::encode(Yii::t('knowledge-library', 'Topics')) ?>
            <b style="color: #333"><?= Html::encode($topicNames === [] ? Yii::t('knowledge-library', 'none') : implode(', ', $topicNames)) ?></b>
        </span>
        <span class="knowledge-library-item-source-name">
            <?= Html::encode(Yii::t('knowledge-library', 'Source')) ?>
            <b style="color: #333"><?= $display($model->source_name) ?></b>
        </span>
    </div>
</div>

<?php if ($timeline !== ''): ?>
    <div class="knowledge-library-item-validity" style="margin-bottom: 20px">
        <h3 style="margin: 0 0 10px; font-size: 18px"><?= Html::encode(Yii::t('knowledge-library', 'Validity')) ?></h3>
        <?= $timeline ?>
    </div>
<?php elseif ($versions === []): ?>
    <div class="callout callout-info knowledge-library-item-no-versions" style="background: #00c0ef; border-left: 5px solid #0097bc; color: #fff">
        <?= Html::encode(Yii::t(
            'knowledge-library',
            'This knowledge object has no version yet. Use "Create first version" to enter the content.'
        )) ?>
    </div>
<?php endif ?>

<div class="knowledge-library-item-tabs">
    <ul class="nav nav-tabs" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <li role="presentation"<?= $key === $activeTab ? ' class="active"' : '' ?>>
                <?= Html::a(Html::encode($label), '#knowledge-library-tab-' . $key, [
                    'role' => 'tab',
                    'data-toggle' => 'tab',
                    'aria-controls' => 'knowledge-library-tab-' . $key,
                ]) ?>
            </li>
        <?php endforeach ?>
    </ul>

    <div class="tab-content">
        <?php foreach ($tabs as $key => $label): ?>
            <div role="tabpanel" class="tab-pane<?= $key === $activeTab ? ' active' : '' ?>" id="knowledge-library-tab-<?= $key ?>">
                <?php if ($key === 'versions'): ?>
                    <?= $this->render('_tab_versions', ['model' => $model, 'versions' => $versions]) ?>
                <?php elseif ($key === 'content'): ?>
                    <?= $this->render('_tab_content', [
                        'model' => $model,
                        'versions' => $versions,
                        'selectedVersion' => $selectedVersion,
                    ]) ?>
                <?php elseif ($key === 'relations'): ?>
                    <?= $this->render('_tab_relations', [
                        'model' => $model,
                        'outgoing' => $outgoing,
                        'incoming' => $incoming,
                        'targetOptions' => $targetOptions,
                    ]) ?>
                <?php elseif ($key === 'source'): ?>
                    <div class="clearfix" style="margin-bottom: 10px">
                        <?= Html::a(
                            '<i class="fa fa-pencil"></i> ' . Html::encode(Yii::t('knowledge-library', 'Edit')),
                            ['source', 'id' => $model->id],
                            ['class' => 'btn btn-info pull-right knowledge-library-item-source-edit']
                        ) ?>
                    </div>
                    <table class="table table-striped table-bordered knowledge-library-item-source">
                        <tbody>
                        <tr>
                            <th style="width: 200px"><?= Html::encode($model->getAttributeLabel('source_name')) ?></th>
                            <td><?= $display($model->source_name) ?></td>
                        </tr>
                        <tr>
                            <th><?= Html::encode($model->getAttributeLabel('source_reference')) ?></th>
                            <td><?= $display($model->source_reference) ?></td>
                        </tr>
                        <tr>
                            <th><?= Html::encode($model->getAttributeLabel('source_url')) ?></th>
                            <td><?= $model->source_url === null || $model->source_url === ''
                                ? $empty
                                : Html::a(Html::encode($model->source_url), $model->source_url, [
                                    'target' => '_blank',
                                    'rel' => 'noopener noreferrer',
                                ]) ?></td>
                        </tr>
                        <tr>
                            <th><?= Html::encode($model->getAttributeLabel('source_import_mode')) ?></th>
                            <td><?= $display($importModes[$model->source_import_mode] ?? $model->source_import_mode) ?></td>
                        </tr>
                        <tr>
                            <th><?= Html::encode($model->getAttributeLabel('source_uploaded_at')) ?></th>
                            <td><?= $model->source_uploaded_at === null || $model->source_uploaded_at === ''
                                ? $empty
                                : Html::encode(Yii::$app->formatter->asDatetime($model->source_uploaded_at)) ?></td>
                        </tr>
                        <tr>
                            <th><?= Html::encode($model->getAttributeLabel('source_uploaded_by')) ?></th>
                            <td><?= $display($uploadedByName) ?></td>
                        </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted knowledge-library-placeholder"><?= Html::encode($placeholders[$key]) ?></p>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    </div>
</div>
</div>
