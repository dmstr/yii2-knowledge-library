<?php

/**
 * Withdrawal of a published version: required reason and what applies
 * instead in its period (previous version, a correction or nothing).
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model version to withdraw
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var dmstr\knowledgeLibrary\models\Version|null $previous version that applies again, null if none
 * @var bool $hasValidityPeriod whether the type of the item has a validity period
 * @var string $period validity period of the version as text
 * @var bool $canCorrect whether the version can be corrected by the current user
 * @var string $reason entered reason
 * @var string $successor selected option, see Version::WITHDRAW_*
 */

use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\Html;
use yii\web\View;
use yii\web\YiiAsset;

YiiAsset::register($this);

$this->title = Yii::t('knowledge-library', 'Withdraw version {number}', ['number' => (int)$model->number]);
$this->context->setBreadcrumbs([
    ['label' => $item->title, 'url' => ['item/view', 'id' => $item->id]],
    $this->title,
]);

$buttonStyle = 'background: #f4f4f4; border: 1px solid #ddd; color: #444;';
$dangerStyle = 'background: #dd4b39; border: 1px solid #d73925; color: #fff; font-weight: 600;';
$errors = $model->getFirstErrors();
$error = $errors === [] ? null : (string)reset($errors);

// Option => [label, disabled]. For types without validity period "nothing
// applies" is not possible while a previous version exists, as that version
// becomes valid anyway.
$options = [
    Version::WITHDRAW_PREVIOUS => [
        $previous === null
            ? Yii::t('knowledge-library', 'No previous version available')
            : Yii::t('knowledge-library', 'Version {number} remains valid', ['number' => (int)$previous->number]),
        $previous === null,
    ],
    Version::WITHDRAW_CORRECTION => [
        Yii::t('knowledge-library', 'I will publish a corrected version'),
        !$canCorrect,
    ],
];
if ($hasValidityPeriod || $previous === null) {
    $options[Version::WITHDRAW_NONE] = [Yii::t('knowledge-library', 'Nothing applies'), false];
}
if (!isset($options[$successor]) || $options[$successor][1]) {
    $successor = $previous !== null ? Version::WITHDRAW_PREVIOUS : Version::WITHDRAW_NONE;
}

$withdrawLabel = $this->title;
$correctionLabel = Yii::t('knowledge-library', 'Continue to correction');

// The submit button names the effect of the selected option; both labels
// are data attributes of the button.
$this->registerJs(<<<'JS'
jQuery(document).on('change', '#knowledge-library-withdraw-form input[name=successor]', function () {
    var button = jQuery('#knowledge-library-withdraw-submit');
    button.text(button.attr(this.value === 'correction' ? 'data-label-correction' : 'data-label-withdraw'));
});
JS, View::POS_READY, 'knowledge-library-withdraw');
?>
<div class="knowledge-library-version-withdraw">
    <div style="display: flex; align-items: baseline; gap: 12px; padding: 12px 15px; border-bottom: 1px solid #f4f4f4">
        <h3 style="margin: 0; font-size: 22px; font-weight: 600"><?= Html::encode($this->title) ?></h3>
        <span style="color: #777"><?= Html::encode($item->title) ?></span>
    </div>

    <?= Html::beginForm(['withdraw', 'id' => $model->id], 'post', [
        'id' => 'knowledge-library-withdraw-form',
        'style' => 'padding: 20px 15px; max-width: 600px; display: flex; flex-direction: column; gap: 14px',
    ]) ?>
        <?php if ($previous !== null): ?>
            <?= Html::hiddenInput('previous', $previous->id) ?>
        <?php endif ?>

        <label style="display: flex; flex-direction: column; gap: 5px; margin: 0">
            <?= Html::encode(Yii::t('knowledge-library', 'Reason')) ?>
            <?= Html::textarea('reason', $reason, [
                'id' => 'knowledge-library-withdraw-reason',
                'class' => 'form-control',
                'rows' => 3,
                'required' => true,
                'style' => 'resize: vertical' . ($model->hasErrors('withdraw_reason') ? '; border: 1px solid #dd4b39; background: #fdf1ef' : ''),
            ]) ?>
        </label>

        <fieldset class="knowledge-library-withdraw-successor" style="margin: 0">
            <legend style="font-size: 14px; font-weight: 700; border: 0; margin-bottom: 8px">
                <?= Html::encode(Yii::t('knowledge-library', 'What applies instead in the period {period}?', ['period' => $period])) ?>
            </legend>
            <?php foreach ($options as $value => [$label, $disabled]): ?>
                <div class="radio<?= $disabled ? ' disabled' : '' ?>" style="margin: 0 0 6px; padding: 9px 12px; border: 1px solid <?= $value === $successor ? '#3c8dbc' : '#e3e6ea' ?>; border-radius: 3px<?= $disabled ? '; opacity: .5' : '' ?>">
                    <label style="display: block; padding-left: 20px; font-weight: 400">
                        <?= Html::radio('successor', $value === $successor, [
                            'value' => $value,
                            'disabled' => $disabled,
                            'class' => 'knowledge-library-withdraw-successor-' . $value,
                        ]) ?>
                        <?= Html::encode($label) ?>
                    </label>
                </div>
            <?php endforeach ?>
        </fieldset>

        <?php if ($error !== null): ?>
            <div class="knowledge-library-withdraw-error" style="color: #a8281a">
                <i class="fa fa-times-circle"></i> <?= Html::encode($error) ?>
            </div>
        <?php endif ?>

        <div style="display: flex; align-items: center; gap: 8px; padding-top: 6px">
            <?= Html::a(
                Html::encode(Yii::t('knowledge-library', 'Cancel')),
                ['item/view', 'id' => $item->id, 'tab' => 'versions'],
                ['class' => 'btn knowledge-library-withdraw-cancel', 'style' => $buttonStyle]
            ) ?>
            <span style="flex: 1"></span>
            <?= Html::submitButton(
                Html::encode($successor === Version::WITHDRAW_CORRECTION ? $correctionLabel : $withdrawLabel),
                [
                    'id' => 'knowledge-library-withdraw-submit',
                    'class' => 'btn knowledge-library-withdraw-submit',
                    'style' => $dangerStyle,
                    'data-label-withdraw' => $withdrawLabel,
                    'data-label-correction' => $correctionLabel,
                ]
            ) ?>
        </div>
    <?= Html::endForm() ?>
</div>
