<?php

/**
 * Form of a type, used by the create and update pages. The validity period
 * is disabled once items of the type have versions.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Type $model
 */

use dmstr\knowledgeLibrary\models\Type;
use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

$validityLocked = $model->isValidityPeriodLocked();
?>
<?php $form = ActiveForm::begin(['id' => 'knowledge-library-type-form']) ?>

<?= $form->field($model, 'name')->textInput(['maxlength' => true, 'autofocus' => true]) ?>

<?php $validityField = $form->field($model, 'has_validity_period')->checkbox([
    'label' => Yii::t('knowledge-library', 'Has a validity period (Valid From, Valid Until)'),
    'disabled' => $validityLocked,
]) ?>
<?php if ($validityLocked) {
    $validityField->hint(Type::validityPeriodLockedMessage());
} ?>
<?= $validityField ?>

<?= $form->field($model, 'requires_review')->checkbox([
    'label' => Yii::t('knowledge-library', 'Publication requires approval by a second person'),
]) ?>

<div class="form-group">
    <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Save')), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Html::encode(Yii::t('knowledge-library', 'Cancel')), ['index'], ['class' => 'btn btn-default']) ?>
</div>

<?php ActiveForm::end() ?>
