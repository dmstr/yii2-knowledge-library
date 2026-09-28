<?php

/**
 * List of master data entries (types, topics) with edit and delete actions,
 * the name, optional further columns and the number of knowledge items per
 * entry. The calling view sets title and breadcrumbs and passes the texts.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var string $cssClass CSS class of the page container
 * @var string $createLabel Label of the create button
 * @var string $emptyText Hint shown instead of the grid when there are no entries
 * @var callable(dmstr\knowledgeLibrary\models\ActiveRecord): string $confirm Delete confirmation per entry
 * @var callable(dmstr\knowledgeLibrary\models\ActiveRecord): array $itemFilter `ItemSearch` params of the count link
 * @var array $columns Further GridView columns after the name
 */

use dmstr\knowledgeLibrary\models\ActiveRecord;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\YiiAsset;

YiiAsset::register($this);

$itemRoute = '/' . $this->context->module->getUniqueId() . '/item/index';
?>
<div class="<?= Html::encode($cssClass) ?> box box-default">
    <div class="box-body">
        <h1>
            <?= Html::encode($this->title) ?>
            <small><?= Html::encode(Yii::t('knowledge-library', 'List')) ?></small>
        </h1>
        <?= Html::a(
            '<i class="fa fa-plus"></i> ' . Html::encode($createLabel),
            ['create'],
            ['class' => 'btn btn-success']
        ) ?>
        <hr>

        <?php if ($dataProvider->getTotalCount() === 0): ?>
            <div class="callout callout-info">
                <?= Html::encode($emptyText) ?>
            </div>
        <?php else: ?>
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped table-bordered'],
                'columns' => array_merge([
                    [
                        'class' => ActionColumn::class,
                        'template' => '{update} {delete}',
                        'headerOptions' => ['style' => 'width: 100px'],
                        'contentOptions' => ['class' => 'text-nowrap'],
                        'buttons' => [
                            'update' => static fn (string $url) => Html::a(
                                '<i class="fa fa-pencil"></i>',
                                $url,
                                [
                                    'class' => 'btn btn-default btn-sm',
                                    'title' => Yii::t('knowledge-library', 'Edit'),
                                    'aria-label' => Yii::t('knowledge-library', 'Edit'),
                                    'data-pjax' => '0',
                                ]
                            ),
                            'delete' => static function (string $url, ActiveRecord $model) use ($confirm) {
                                if ($model->itemCount > 0) {
                                    $title = Yii::t('knowledge-library', 'In use, cannot be deleted');

                                    // A disabled button shows no tooltip, the wrapper does.
                                    return Html::tag('span', Html::button('<i class="fa fa-trash"></i>', [
                                        'class' => 'btn btn-default btn-sm',
                                        'disabled' => true,
                                        'aria-label' => $title,
                                        'style' => 'pointer-events: none',
                                    ]), [
                                        'class' => 'knowledge-library-delete-disabled',
                                        'title' => $title,
                                        'style' => 'display: inline-block; cursor: not-allowed',
                                    ]);
                                }

                                return Html::a('<i class="fa fa-trash"></i>', $url, [
                                    'class' => 'btn btn-default btn-sm',
                                    'title' => Yii::t('knowledge-library', 'Delete'),
                                    'aria-label' => Yii::t('knowledge-library', 'Delete'),
                                    'data-method' => 'post',
                                    'data-confirm' => $confirm($model),
                                    'data-pjax' => '0',
                                ]);
                            },
                        ],
                    ],
                    'name',
                ], $columns, [
                    [
                        'label' => Yii::t('knowledge-library', 'Knowledge objects'),
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width: 160px'],
                        'value' => static fn (ActiveRecord $model) => Html::a(
                            (string)(int)$model->itemCount,
                            Url::to([$itemRoute, 'ItemSearch' => $itemFilter($model)]),
                            ['class' => 'knowledge-library-item-count']
                        ),
                    ],
                ]),
            ]) ?>
        <?php endif ?>
    </div>
</div>
