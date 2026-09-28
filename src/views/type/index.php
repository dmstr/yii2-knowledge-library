<?php

/**
 * List of the types, see `../master-data/_index.php`.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use dmstr\knowledgeLibrary\models\Type;

$this->title = Yii::t('knowledge-library', 'Types');
$this->context->setBreadcrumbs([$this->title]);

$yesNo = static fn (bool $value) => $value
    ? Yii::t('knowledge-library', 'Yes')
    : Yii::t('knowledge-library', 'No');

echo $this->render('../master-data/_index', [
    'dataProvider' => $dataProvider,
    'cssClass' => 'knowledge-library-type-index',
    'createLabel' => Yii::t('knowledge-library', 'Create type'),
    'emptyText' => Yii::t('knowledge-library', 'No types yet. Create the first one with "Create type".'),
    'confirm' => static fn (Type $model) => Yii::t('knowledge-library', 'Delete type "{name}"?', [
        'name' => $model->name,
    ]),
    'itemFilter' => static fn (Type $model) => ['type_id' => $model->id, 'archived' => 'all'],
    'columns' => [
        [
            'label' => Yii::t('knowledge-library', 'Validity period'),
            'value' => static fn (Type $model) => $yesNo((bool)$model->has_validity_period),
        ],
        [
            'label' => Yii::t('knowledge-library', 'Approval by second person'),
            'value' => static fn (Type $model) => $yesNo((bool)$model->requires_review),
        ],
    ],
]);
