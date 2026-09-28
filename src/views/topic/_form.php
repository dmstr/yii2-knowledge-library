<?php

/**
 * Form of a topic, used by the create and update pages.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Topic $model
 */

use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

?>
<?php $form = ActiveForm::begin(['id' => 'knowledge-library-topic-form']) ?>

<?= $form->field($model, 'name')->textInput(['maxlength' => true, 'autofocus' => true]) ?>

<div class="form-group">
    <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Save')), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Html::encode(Yii::t('knowledge-library', 'Cancel')), ['index'], ['class' => 'btn btn-default']) ?>
</div>

<?php ActiveForm::end() ?>
