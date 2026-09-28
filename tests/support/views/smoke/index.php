<?php

/**
 * Page using the widgets of the backend views, to prove they render in tests.
 *
 * @var yii\web\View $this
 * @var yii\base\DynamicModel $model
 * @var yii\data\ArrayDataProvider $dataProvider
 */

use kartik\select2\Select2;
use yii\bootstrap\ActiveForm;
use yii\grid\GridView;

$this->title = 'Smoke';
?>
<h1 class="smoke-title"><?= $this->title ?></h1>

<?php $form = ActiveForm::begin(['action' => ['save']]) ?>
<?= $form->field($model, 'name')->textInput() ?>
<?= $form->field($model, 'topicIds')->widget(Select2::class, [
    'data' => ['a' => 'Topic A', 'b' => 'Topic B'],
    'theme' => Select2::THEME_BOOTSTRAP,
    'options' => ['multiple' => true],
]) ?>
<?php ActiveForm::end() ?>

<?= GridView::widget([
    'dataProvider' => $dataProvider,
    'columns' => ['name'],
]) ?>
