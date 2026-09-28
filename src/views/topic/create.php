<?php

/**
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Topic $model
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Create topic');
$this->context->setBreadcrumbs([
    ['label' => Yii::t('knowledge-library', 'Topics'), 'url' => ['index']],
    $this->title,
]);
?>
<div class="knowledge-library-topic-create">
    <h1><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_form', ['model' => $model]) ?>
</div>
