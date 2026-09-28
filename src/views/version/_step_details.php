<?php

/**
 * Wizard step "details": title, topics and summary of the item, stored in
 * the draft until publication.
 *
 * @var yii\web\View $this
 * @var yii\bootstrap\ActiveForm $form
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var array<string, string> $topicOptions map `topic ID => name`
 */

use kartik\select2\Select2;

?>
<div class="knowledge-library-wizard-details" style="max-width: 860px">
    <?= $form->field($model, 'draft_title')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'draftTopicIds')->widget(Select2::class, [
        'data' => $topicOptions,
        'theme' => Select2::THEME_BOOTSTRAP,
        'options' => [
            'id' => 'knowledge-library-version-topics',
            'multiple' => true,
            'placeholder' => '',
        ],
        'pluginOptions' => [
            'allowClear' => true,
        ],
    ]) ?>

    <?= $form->field($model, 'draft_summary')->textarea(['rows' => 4]) ?>
</div>
