<?php

/**
 * List of the topics with edit and delete actions and the number of
 * knowledge items per topic.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use dmstr\knowledgeLibrary\models\Topic;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\YiiAsset;

YiiAsset::register($this);

$this->title = Yii::t('knowledge-library', 'Topics');
$this->context->setBreadcrumbs([$this->title]);
$itemRoute = '/' . $this->context->module->getUniqueId() . '/item/index';
?>
<div class="knowledge-library-topic-index box box-default">
    <div class="box-body">
        <h1>
            <?= Html::encode($this->title) ?>
            <small><?= Html::encode(Yii::t('knowledge-library', 'List')) ?></small>
        </h1>
        <?= Html::a(
            '<i class="fa fa-plus"></i> ' . Html::encode(Yii::t('knowledge-library', 'Create topic')),
            ['create'],
            ['class' => 'btn btn-success']
        ) ?>
        <hr>

        <?php if ($dataProvider->getTotalCount() === 0): ?>
            <div class="callout callout-info">
                <?= Html::encode(Yii::t('knowledge-library', 'No topics yet. Create the first one with "Create topic".')) ?>
            </div>
        <?php else: ?>
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped table-bordered'],
                'columns' => [
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
                            'delete' => static function (string $url, Topic $model) {
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
                                    'data-confirm' => Yii::t('knowledge-library', 'Delete topic "{name}"?', [
                                        'name' => $model->name,
                                    ]),
                                    'data-pjax' => '0',
                                ]);
                            },
                        ],
                    ],
                    'name',
                    [
                        'label' => Yii::t('knowledge-library', 'Knowledge objects'),
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width: 160px'],
                        'value' => static fn (Topic $model) => Html::a(
                            (string)(int)$model->itemCount,
                            Url::to([$itemRoute, 'ItemSearch' => ['topicIds' => [$model->id], 'archived' => 'all']]),
                            ['class' => 'knowledge-library-item-count']
                        ),
                    ],
                ],
            ]) ?>
        <?php endif ?>
    </div>
</div>
