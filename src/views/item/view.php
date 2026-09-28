<?php

/**
 * Detail page of an item: header with type and archive badge, actions and
 * the tabs versions, content, relations, source & origin and history.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var bool $canDelete
 * @var string|null $uploadedByName display name of the user who uploaded the source
 */

use dmstr\knowledgeLibrary\models\Item;
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
// Source & origin is the only tab with content so far.
$activeTab = 'source';
$placeholders = [
    'versions' => Yii::t('knowledge-library', 'Versions will be available in a later release.'),
    'content' => Yii::t('knowledge-library', 'The content of the valid version will be shown here in a later release.'),
    'relations' => Yii::t('knowledge-library', 'Relations to other knowledge objects will be available in a later release.'),
    'history' => Yii::t('knowledge-library', 'The change history will be available in a later release.'),
];
?>
<div class="knowledge-library-item-view box box-default">
    <div class="box-body">
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
                    <?php if ($key === 'source'): ?>
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
