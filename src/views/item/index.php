<?php

/**
 * Library list: filter row in the table header (title, type, topics,
 * archive filter), state of each item and a whole-row link to the detail
 * page.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\search\ItemSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array<string, dmstr\knowledgeLibrary\models\ItemState> $states
 * @var array<string, string> $typeOptions
 * @var array<string, string> $topicOptions
 * @var bool $isEmpty
 * @var bool $showAwaitingReview whether the link "Awaiting my approval" is shown
 * @var int $awaitingReviewCount number of items with a version in review by the current user
 */

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ItemState;
use dmstr\knowledgeLibrary\models\search\ItemSearch;
use kartik\select2\Select2;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\View;

$this->title = Yii::t('knowledge-library', 'Knowledge Library');
$this->context->setBreadcrumbs([Yii::t('knowledge-library', 'List')]);

// Colours of the states as inline styles, so they do not depend on the
// layout rendering registered CSS.
$badgeStyle = 'display: inline-block; padding: 2px 6px 3px; font-size: 11px; font-weight: 700;'
    . ' border-radius: 3px; line-height: 1.2; border: 1px solid; ';
$stateStyles = [
    ItemState::ARCHIVED => $badgeStyle . 'background: #d2d6de; color: #444; border-color: #d2d6de',
    ItemState::IN_REVIEW => $badgeStyle . 'background: #fff; color: #c87f0a; border-color: #f39c12',
    ItemState::DRAFT => $badgeStyle . 'background: #f39c12; color: #fff; border-color: #f39c12',
    ItemState::NONE => $badgeStyle . 'background: #fff; color: #a8281a; border-color: #dd4b39',
];

$this->registerCss(<<<'CSS'
.knowledge-library-item-index .knowledge-library-item-row { cursor: pointer; }
.knowledge-library-item-index .knowledge-library-item-row:hover > td { background: #eef5fa; }
.knowledge-library-item-index .knowledge-library-last-change { font-weight: normal; margin-left: 6px; }
CSS);

// The whole row opens the detail page; links and filter inputs keep their
// own behavior.
$this->registerJs(<<<'JS'
jQuery(document).on('click', '.knowledge-library-item-row', function (event) {
    if (jQuery(event.target).closest('a, button, input, select, label').length === 0) {
        window.location.href = jQuery(this).data('href');
    }
});
JS, View::POS_READY, 'knowledge-library-item-row');

$sort = $dataProvider->getSort();
?>
<div class="knowledge-library-item-index">
    <h1>
        <?= Html::encode($this->title) ?>
        <small><?= Html::encode(Yii::t('knowledge-library', 'List')) ?></small>
    </h1>
    <?= Html::a(
        '<i class="fa fa-plus"></i> ' . Html::encode(Yii::t('knowledge-library', 'Create knowledge object')),
        ['create'],
        ['class' => 'btn btn-success']
    ) ?>
    <hr>

    <?php if ($showAwaitingReview): ?>
        <?php $reviewActive = $searchModel->review === ItemSearch::REVIEW_MINE ?>
        <div class="knowledge-library-awaiting-review" style="margin-bottom: 12px">
            <?= Html::a(
                '<i class="fa fa-hourglass-half"></i> ' . Html::encode(Yii::t(
                    'knowledge-library',
                    'Awaiting my approval ({count})',
                    ['count' => $awaitingReviewCount]
                )),
                $reviewActive
                    ? ['index']
                    : ['index', $searchModel->formName() => ['review' => ItemSearch::REVIEW_MINE]],
                [
                    'class' => 'btn btn-sm knowledge-library-awaiting-review-link'
                        . ($reviewActive ? ' btn-warning active' : ' btn-default'),
                    'aria-pressed' => $reviewActive ? 'true' : 'false',
                ]
            ) ?>
        </div>
    <?php endif ?>

    <?php if ($isEmpty): ?>
        <div class="callout callout-info">
            <?= Html::encode(Yii::t(
                'knowledge-library',
                'No knowledge objects yet. Create the first one with "Create knowledge object".'
            )) ?>
        </div>
    <?php else: ?>
        <?= GridView::widget([
            'id' => 'knowledge-library-item-grid',
            'dataProvider' => $dataProvider,
            'filterModel' => $searchModel,
            'tableOptions' => ['class' => 'table table-bordered'],
            'emptyText' => Yii::t('knowledge-library', 'No entries for these filters.'),
            'emptyTextOptions' => ['class' => 'text-muted'],
            'rowOptions' => static fn (Item $model) => [
                'class' => 'knowledge-library-item-row',
                'data-href' => Url::to(['view', 'id' => $model->id]),
            ],
            'columns' => [
                [
                    'attribute' => 'title',
                    'label' => Yii::t('knowledge-library', 'Title'),
                    'format' => 'raw',
                    'value' => static fn (Item $model) => Html::a(
                        Html::encode($model->title),
                        ['view', 'id' => $model->id],
                        $model->is_archived ? [
                            'class' => 'knowledge-library-archived',
                            'style' => 'color: #999; text-decoration: line-through',
                        ] : []
                    ),
                ],
                [
                    'attribute' => 'type_id',
                    'label' => Yii::t('knowledge-library', 'Type'),
                    'filter' => $typeOptions,
                    'headerOptions' => ['style' => 'width: 170px'],
                    'value' => static fn (Item $model) => $model->type->name ?? null,
                ],
                [
                    'attribute' => 'topicIds',
                    'label' => Yii::t('knowledge-library', 'Topics'),
                    'enableSorting' => false,
                    'headerOptions' => ['style' => 'width: 300px'],
                    'filter' => Select2::widget([
                        'model' => $searchModel,
                        'attribute' => 'topicIds',
                        'data' => $topicOptions,
                        'theme' => Select2::THEME_BOOTSTRAP,
                        'options' => [
                            'id' => 'knowledge-library-item-topic-filter',
                            'multiple' => true,
                            'placeholder' => '',
                        ],
                        'pluginOptions' => [
                            'allowClear' => true,
                        ],
                    ]),
                    'value' => static function (Item $model) {
                        $names = array_map(static fn ($topic) => $topic->name, $model->topics);
                        sort($names);

                        return $names === [] ? '–' : implode(', ', $names);
                    },
                ],
                [
                    'label' => Yii::t('knowledge-library', 'In Force'),
                    'header' => Html::encode(Yii::t('knowledge-library', 'In Force'))
                        . Html::tag('small', $sort->link('lastChange'), [
                            'class' => 'knowledge-library-last-change',
                        ]),
                    'headerOptions' => ['style' => 'width: 220px'],
                    'filter' => Html::activeDropDownList(
                        $searchModel,
                        'archived',
                        ItemSearch::archivedOptions(),
                        ['class' => 'form-control', 'id' => 'knowledge-library-item-archived-filter']
                    ),
                    'format' => 'raw',
                    'value' => static function (Item $model) use ($states, $stateStyles) {
                        $state = $states[(string)$model->id] ?? null;
                        if ($state === null) {
                            return '';
                        }

                        $since = $state->getSinceLabel();
                        if ($state->getState() === ItemState::VALID) {
                            return Html::tag('span', Html::encode($state->getLabel()))
                                . ($since === null ? '' : ' ' . Html::tag('span', Html::encode($since), [
                                    'class' => 'knowledge-library-since',
                                    'style' => 'color: #777',
                                ]));
                        }

                        return Html::tag('span', Html::encode($state->getLabel()), [
                            'class' => 'knowledge-library-state knowledge-library-state-' . $state->getState(),
                            'style' => $stateStyles[$state->getState()] ?? null,
                        ]);
                    },
                ],
            ],
        ]) ?>
    <?php endif ?>
</div>
