<?php

/**
 * Version wizard: header with the item title, step indicator, the form of
 * the current step and the footer with the wizard buttons.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var int $step current step
 * @var array<int, string> $steps labels by step
 * @var int $reachable last step that can be opened
 * @var string|null $message why the current step is not complete
 * @var bool $blocked whether "Next" is shown as blocked
 * @var dmstr\knowledgeLibrary\models\ValidityCheck|null $check validity check (steps validity and review)
 * @var string $title title of the wizard
 * @var bool $canPublish whether the draft can be published directly
 * @var array<string, string> $topicOptions map `topic ID => name` (steps details and review)
 * @var array<string, string> $reviewerOptions map `user reference => name` of the possible reviewers without the current user (review step of types with review)
 * @var string $reviewer selected reviewer (user reference)
 * @var string $reviewMessage message to the reviewer
 * @var string|null $returnedByName name of the reviewer who returned the draft, null if it was not returned
 */

use dmstr\knowledgeLibrary\controllers\VersionController;
use yii\bootstrap\ActiveForm;
use yii\helpers\Html;
use yii\web\View;
use yii\web\YiiAsset;

YiiAsset::register($this);

$this->title = $title;
$this->context->setBreadcrumbs([
    ['label' => $item->title, 'url' => ['item/view', 'id' => $item->id]],
    $this->title,
]);

$buttonStyle = 'background: #f4f4f4; border: 1px solid #ddd; color: #444;';
$primaryStyle = 'background: #00a65a; border: 1px solid #008d4c; color: #fff; font-weight: 600;';
$blockedStyle = ' opacity: .45; cursor: not-allowed;';

$partials = [
    VersionController::STEP_CONTENT => '_step_content',
    VersionController::STEP_VALIDITY => '_step_validity',
    VersionController::STEP_DETAILS => '_step_details',
    VersionController::STEP_REVIEW => '_step_review',
];

// "Next" looks blocked while the step is incomplete; the server checks the
// step again on submit and shows the message.
$this->registerJs(<<<'JS'
jQuery(document).on('input change', '#knowledge-library-version-wizard [data-kl-blocks-next]', function () {
    var form = jQuery(this).closest('form');
    var empty = jQuery.trim(form.find('[data-kl-blocks-next]').val() || '') === '';
    var hasMainFile = form.find('[data-kl-main-file]').length > 0;
    form.find('.knowledge-library-wizard-next')
        .css({opacity: empty && !hasMainFile ? .45 : 1, cursor: empty && !hasMainFile ? 'not-allowed' : 'pointer'});
    var textarea = form.find('[data-kl-base-text]');
    var changed = textarea.length > 0 && textarea.val() !== textarea.attr('data-kl-base-text');
    textarea.css({borderColor: changed ? '#f39c12' : '', background: changed ? '#fffbf0' : ''});
});
JS, View::POS_READY, 'knowledge-library-version-wizard');
?>
<div class="knowledge-library-version-wizard">
    <div class="knowledge-library-wizard-header" style="display: flex; align-items: baseline; gap: 12px; padding: 12px 15px; border-bottom: 1px solid #f4f4f4">
        <h3 style="margin: 0; font-size: 22px; font-weight: 600"><?= Html::encode($this->title) ?></h3>
        <span class="knowledge-library-wizard-item" style="color: #777"><?= Html::encode($item->title) ?></span>
    </div>

    <?php if ($returnedByName !== null): ?>
        <?= $this->render('/item/_return_callout', ['model' => $model, 'returnedByName' => $returnedByName]) ?>
    <?php endif ?>

    <?= $this->render('_steps', [
        'model' => $model,
        'step' => $step,
        'steps' => $steps,
        'reachable' => $reachable,
    ]) ?>

    <?php $form = ActiveForm::begin([
        'id' => 'knowledge-library-version-wizard',
        'action' => ['update', 'id' => $model->id, 'step' => $step],
        'enableClientValidation' => false,
        'options' => ['enctype' => 'multipart/form-data'],
    ]) ?>

    <?php if ($step < VersionController::STEP_REVIEW): ?>
        <?php // Default button for the enter key. ?>
        <?= Html::submitButton('', [
            'name' => VersionController::BUTTON_NEXT,
            'value' => '1',
            'tabindex' => '-1',
            'aria-hidden' => 'true',
            'style' => 'position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden',
        ]) ?>
    <?php endif ?>

    <div class="knowledge-library-wizard-body knowledge-library-wizard-step-<?= (int)$step ?>" style="padding: 20px 15px; min-height: 280px">
        <?= $this->render($partials[$step], [
            'form' => $form,
            'model' => $model,
            'item' => $item,
            'message' => $message,
            'check' => $check,
            'canPublish' => $canPublish,
            'topicOptions' => $topicOptions,
            'reviewerOptions' => $reviewerOptions,
            'reviewer' => $reviewer,
            'reviewMessage' => $reviewMessage,
        ]) ?>
    </div>

    <div class="knowledge-library-wizard-footer" style="display: flex; align-items: center; gap: 8px; padding: 12px 15px; border-top: 1px solid #f4f4f4">
        <?= Html::a(
            Html::encode(Yii::t('knowledge-library', 'Cancel')),
            ['item/view', 'id' => $item->id],
            ['class' => 'btn knowledge-library-wizard-cancel', 'style' => $buttonStyle]
        ) ?>
        <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Save as draft')), [
            'name' => VersionController::BUTTON_SAVE,
            'value' => '1',
            'class' => 'btn knowledge-library-wizard-save',
            'style' => $buttonStyle,
        ]) ?>
        <span style="flex: 1"></span>
        <?php if ($step > VersionController::STEP_CONTENT): ?>
            <?= Html::submitButton(
                '<i class="fa fa-arrow-left"></i> ' . Html::encode(Yii::t('knowledge-library', 'Back')),
                [
                    'name' => VersionController::BUTTON_BACK,
                    'value' => '1',
                    'class' => 'btn knowledge-library-wizard-back',
                    'style' => $buttonStyle,
                ]
            ) ?>
        <?php endif ?>
        <?php if ($step < VersionController::STEP_REVIEW): ?>
            <?= Html::submitButton(
                Html::encode(Yii::t('knowledge-library', 'Next')) . ' <i class="fa fa-arrow-right"></i>',
                [
                    'name' => VersionController::BUTTON_NEXT,
                    'value' => '1',
                    'class' => 'btn knowledge-library-wizard-next' . ($blocked ? ' knowledge-library-wizard-blocked' : ''),
                    'style' => $primaryStyle . ($blocked ? $blockedStyle : ''),
                ]
            ) ?>
        <?php elseif ($canPublish): ?>
            <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Publish')), [
                'name' => VersionController::BUTTON_PUBLISH,
                'value' => '1',
                'class' => 'btn knowledge-library-wizard-publish',
                'style' => $primaryStyle,
                'data-confirm' => VersionController::publishConfirmation($model, $check),
            ]) ?>
        <?php else: ?>
            <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Submit for approval')), [
                'name' => VersionController::BUTTON_SUBMIT,
                'value' => '1',
                'class' => 'btn knowledge-library-wizard-submit',
                'style' => $primaryStyle,
            ]) ?>
        <?php endif ?>
    </div>

    <?php ActiveForm::end() ?>
</div>
