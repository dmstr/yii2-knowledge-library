<?php

/**
 * Form of source and origin of an item. The upload fields are set by the
 * upload and only shown on the detail page.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 */

use dmstr\knowledgeLibrary\models\Item;
use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Edit source & origin');
$this->context->setBreadcrumbs([
    ['label' => $model->title, 'url' => ['view', 'id' => $model->id]],
    $this->title,
]);
?>
<div class="knowledge-library-item-source">
    <h1><?= Html::encode($this->title) ?></h1>

    <?php $form = ActiveForm::begin(['id' => 'knowledge-library-item-source-form']) ?>

    <?= $form->field($model, 'source_name')->textInput(['maxlength' => true, 'autofocus' => true]) ?>

    <?= $form->field($model, 'source_reference')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'source_url')->textInput(['maxlength' => true, 'type' => 'url']) ?>

    <?= $form->field($model, 'source_import_mode')->radioList(Item::sourceImportModes()) ?>

    <div class="form-group">
        <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Save')), ['class' => 'btn btn-success']) ?>
        <?= Html::a(
            Html::encode(Yii::t('knowledge-library', 'Cancel')),
            ['view', 'id' => $model->id],
            ['class' => 'btn btn-default']
        ) ?>
    </div>

    <?php ActiveForm::end() ?>
</div>
