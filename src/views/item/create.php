<?php

/**
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var array<string, string> $typeOptions
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Create knowledge object');
$this->context->setBreadcrumbs([$this->title]);
?>
<div class="knowledge-library-item-create box box-default">
    <div class="box-body">
        <h1><?= Html::encode($this->title) ?></h1>
        <?= $this->render('_form', [
            'model' => $model,
            'typeOptions' => $typeOptions,
            'submitLabel' => Yii::t('knowledge-library', 'Create'),
            'cancelUrl' => ['index'],
        ]) ?>
    </div>
</div>
