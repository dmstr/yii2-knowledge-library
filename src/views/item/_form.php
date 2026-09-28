<?php

/**
 * Master data form of an item (title and type), used by the create and
 * update pages. The type is disabled once the item has versions.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var array<string, string> $typeOptions
 * @var string $submitLabel
 * @var array $cancelUrl
 */

use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

$typeLocked = $model->isTypeLocked();
?>
<?php $form = ActiveForm::begin(['id' => 'knowledge-library-item-form']) ?>

<?= $form->field($model, 'title')->textInput(['maxlength' => true, 'autofocus' => true]) ?>

<?php $typeField = $form->field($model, 'type_id')->dropDownList($typeOptions, [
    'prompt' => '',
    'disabled' => $typeLocked,
]) ?>
<?php if ($typeLocked) {
    $typeField->hint(Yii::t('knowledge-library', 'The type cannot be changed once the item has versions.'));
} ?>
<?= $typeField ?>

<div class="form-group">
    <?= Html::submitButton(Html::encode($submitLabel), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Html::encode(Yii::t('knowledge-library', 'Cancel')), $cancelUrl, ['class' => 'btn btn-default']) ?>
</div>

<?php ActiveForm::end() ?>
