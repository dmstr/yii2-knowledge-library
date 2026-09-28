<?php

/**
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var array<string, string> $typeOptions
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Edit knowledge object');
$this->context->setBreadcrumbs([
    ['label' => $model->getOldAttribute('title'), 'url' => ['view', 'id' => $model->id]],
    $this->title,
]);
?>
<div class="knowledge-library-item-update box box-default">
    <div class="box-body">
        <h1><?= Html::encode($this->title) ?></h1>
        <?= $this->render('_form', [
            'model' => $model,
            'typeOptions' => $typeOptions,
            'submitLabel' => Yii::t('knowledge-library', 'Save'),
            'cancelUrl' => ['view', 'id' => $model->id],
        ]) ?>
    </div>
</div>
