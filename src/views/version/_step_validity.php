<?php

/**
 * Wizard step "validity": Valid From and Valid Until (or the fixed text for
 * types without validity period), the first error, the consequences of
 * publishing and the preview of the timeline.
 *
 * A correction keeps the validity period of the corrected version: the
 * dates are shown as text, with a hint if the correction changes the past.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var string|null $message why the step is not complete
 * @var dmstr\knowledgeLibrary\models\ValidityCheck $check
 */

use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\widgets\ValidityTimeline;
use yii\helpers\Html;

$hasValidityPeriod = $item->type === null || (bool)$item->type->has_validity_period;

// One error at a time: validation errors of the input first, then the
// result of the check.
$errors = array_values(array_unique(array_filter(array_merge(
    array_values(array_intersect_key($model->getFirstErrors(), ['valid_from' => true, 'valid_until' => true])),
    [$message],
    $check->getErrors()
))));
$error = $errors[0] ?? null;

$inputStyle = static fn (bool $invalid) => 'height: 34px; box-sizing: border-box; padding: 4px 10px; font-size: 14px;'
    . ($invalid ? ' border: 1px solid #dd4b39; background: #fdf1ef' : ' border: 1px solid #d2d6de');
?>
<div class="knowledge-library-wizard-validity">
    <?php if ($check->isCorrection()): ?>
        <div class="knowledge-library-wizard-correction-period" style="display: flex; flex-wrap: wrap; gap: 6px 26px; font-size: 16px">
            <?php if ($hasValidityPeriod): ?>
                <span>
                    <span style="color: #777"><?= Html::encode(Yii::t('knowledge-library', 'Valid From')) ?></span>
                    <b><?= Html::encode($check->getValidFrom() === null ? '–' : Yii::$app->formatter->asDate($check->getValidFrom())) ?></b>
                </span>
                <span>
                    <span style="color: #777"><?= Html::encode(Yii::t('knowledge-library', 'Valid Until')) ?></span>
                    <b><?= Html::encode($check->getValidUntil() === null
                        ? Yii::t('knowledge-library', 'open-ended')
                        : Yii::$app->formatter->asDate($check->getValidUntil())) ?></b>
                </span>
            <?php else: ?>
                <span><?= Html::encode(Yii::t('knowledge-library', 'Valid from publication, open-ended.')) ?></span>
            <?php endif ?>
        </div>
        <div class="knowledge-library-wizard-correction-note" style="margin-top: 8px; color: #777">
            <?= Html::encode(Version::correctionPeriodMessage()) ?>
        </div>
        <?php $pastHint = $check->getPastHint() ?>
        <?php if ($pastHint !== null): ?>
            <div class="knowledge-library-wizard-past-hint" style="margin-top: 12px; background: #f39c12; border-left: 5px solid #c87f0a; color: #fff; border-radius: 3px; padding: 11px 15px">
                <?= Html::encode($pastHint) ?>
            </div>
        <?php endif ?>
    <?php elseif ($hasValidityPeriod): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start">
            <label style="display: flex; flex-direction: column; gap: 5px; width: 220px">
                <?= Html::encode(Yii::t('knowledge-library', 'Valid From')) ?>
                <?= Html::activeInput('date', $model, 'valid_from', [
                    'class' => 'form-control',
                    'style' => $inputStyle($error !== null && !$model->hasErrors('valid_until')),
                ]) ?>
            </label>
            <label style="display: flex; flex-direction: column; gap: 5px; width: 220px">
                <?= Html::encode(Yii::t('knowledge-library', 'Valid Until')) ?>
                <?= Html::activeInput('date', $model, 'valid_until', [
                    'class' => 'form-control',
                    'placeholder' => Yii::t('knowledge-library', 'open-ended'),
                    'style' => $inputStyle($model->hasErrors('valid_until')),
                ]) ?>
            </label>
        </div>
    <?php else: ?>
        <div class="knowledge-library-wizard-no-period" style="font-size: 16px">
            <?= Html::encode(Yii::t('knowledge-library', 'Valid from publication, open-ended.')) ?>
        </div>
    <?php endif ?>

    <?php if ($error !== null): ?>
        <div class="knowledge-library-wizard-error" style="margin-top: 12px; color: #a8281a">
            <i class="fa fa-times-circle"></i> <?= Html::encode($error) ?>
        </div>
    <?php else: ?>
        <?php foreach ($check->getConsequences() as $consequence): ?>
            <div class="knowledge-library-wizard-consequence" style="margin-top: 10px; color: #555">
                <i class="fa fa-long-arrow-right" style="color: #f39c12; margin-right: 6px"></i><?= Html::encode($consequence) ?>
            </div>
        <?php endforeach ?>
        <?php $preview = ValidityTimeline::widget(['data' => $check->getPreview()]) ?>
        <?php if ($preview !== ''): ?>
            <div class="knowledge-library-wizard-preview" style="margin-top: 18px; border: 1px solid #e3e6ea; border-radius: 3px; padding: 0 14px 8px">
                <?= $preview ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</div>
