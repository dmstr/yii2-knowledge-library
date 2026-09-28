<?php

/**
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Topic $model
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Edit topic');
$this->context->setBreadcrumbs([
    ['label' => Yii::t('knowledge-library', 'Topics'), 'url' => ['index']],
    $this->title,
]);
?>
<div class="knowledge-library-topic-update">
    <h1><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_form', ['model' => $model]) ?>
</div>
