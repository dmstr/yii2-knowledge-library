<?php

/**
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Type $model
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Edit type');
$this->context->setBreadcrumbs([
    ['label' => Yii::t('knowledge-library', 'Types'), 'url' => ['index']],
    $this->title,
]);
?>
<div class="knowledge-library-type-update">
    <h1><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_form', ['model' => $model]) ?>
</div>
