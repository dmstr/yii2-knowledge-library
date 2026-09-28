<?php

/**
 * List of the types with edit and delete actions and the number of
 * knowledge items per type.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use dmstr\knowledgeLibrary\models\Type;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\YiiAsset;

YiiAsset::register($this);

$this->title = Yii::t('knowledge-library', 'Types');
$this->context->setBreadcrumbs([$this->title]);
$itemRoute = '/' . $this->context->module->getUniqueId() . '/item/index';
?>
<div class="knowledge-library-type-index box box-default">
    <div class="box-body">
        <h1>
            <?= Html::encode($this->title) ?>
            <small><?= Html::encode(Yii::t('knowledge-library', 'List')) ?></small>
        </h1>
        <?= Html::a(
            '<i class="fa fa-plus"></i> ' . Html::encode(Yii::t('knowledge-library', 'Create type')),
            ['create'],
            ['class' => 'btn btn-success']
        ) ?>
        <hr>

        <?php if ($dataProvider->getTotalCount() === 0): ?>
            <div class="callout callout-info">
                <?= Html::encode(Yii::t('knowledge-library', 'No types yet. Create the first one with "Create type".')) ?>
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
                            'delete' => static function (string $url, Type $model) {
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
                                    'data-confirm' => Yii::t('knowledge-library', 'Delete type "{name}"?', [
                                        'name' => $model->name,
                                    ]),
                                    'data-pjax' => '0',
                                ]);
                            },
                        ],
                    ],
                    'name',
                    [
                        'label' => Yii::t('knowledge-library', 'Validity period'),
                        'value' => static fn (Type $model) => $model->has_validity_period
                            ? Yii::t('knowledge-library', 'Yes')
                            : Yii::t('knowledge-library', 'No'),
                    ],
                    [
                        'label' => Yii::t('knowledge-library', 'Approval by second person'),
                        'value' => static fn (Type $model) => $model->requires_review
                            ? Yii::t('knowledge-library', 'Yes')
                            : Yii::t('knowledge-library', 'No'),
                    ],
                    [
                        'label' => Yii::t('knowledge-library', 'Knowledge objects'),
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width: 160px'],
                        'value' => static fn (Type $model) => Html::a(
                            (string)(int)$model->itemCount,
                            Url::to([$itemRoute, 'ItemSearch' => ['type_id' => $model->id, 'archived' => 'all']]),
                            ['class' => 'knowledge-library-item-count']
                        ),
                    ],
                ],
            ]) ?>
        <?php endif ?>
    </div>
</div>
