<?php

/**
 * Change of the reviewer of a version in review: current reviewer, select
 * of the new reviewer and an optional reason.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model version in review
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var string $reviewerName name of the current reviewer
 * @var array<string, string> $reviewerOptions map `user reference => name` without the current reviewer and the submitter
 * @var string $reviewer selected new reviewer (user reference)
 * @var string $reason entered reason
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Change reviewing person');
$this->context->setBreadcrumbs([
    ['label' => $item->title, 'url' => ['item/view', 'id' => $item->id]],
    $this->title,
]);

$buttonStyle = 'background: #f4f4f4; border: 1px solid #ddd; color: #444;';
$primaryStyle = 'background: #00a65a; border: 1px solid #008d4c; color: #fff; font-weight: 600;';
$errors = $model->getFirstErrors();
$error = $errors === [] ? null : (string)reset($errors);
?>
<div class="knowledge-library-version-reviewer">
    <div style="display: flex; align-items: baseline; gap: 12px; padding: 12px 15px; border-bottom: 1px solid #f4f4f4">
        <h3 style="margin: 0; font-size: 22px; font-weight: 600"><?= Html::encode($this->title) ?></h3>
        <span style="color: #777"><?= Html::encode($item->title) ?> · <?= Html::encode(Yii::t('knowledge-library', 'Version {number}', ['number' => (int)$model->number])) ?></span>
    </div>

    <?= Html::beginForm(['reviewer', 'id' => $model->id], 'post', [
        'id' => 'knowledge-library-reviewer-form',
        'style' => 'padding: 20px 15px; max-width: 600px; display: flex; flex-direction: column; gap: 14px',
    ]) ?>
        <div class="knowledge-library-reviewer-current" style="font-weight: 400">
            <?= Html::encode(Yii::t('knowledge-library', 'Current: {name}', ['name' => $reviewerName])) ?>
        </div>

        <label style="display: flex; flex-direction: column; gap: 5px; margin: 0">
            <?= Html::encode(Yii::t('knowledge-library', 'New reviewing person')) ?>
            <?= Html::dropDownList('reviewer', $reviewer, $reviewerOptions, [
                'id' => 'knowledge-library-reviewer-select',
                'class' => 'form-control',
                'prompt' => Yii::t('knowledge-library', 'Select a person'),
                'style' => $model->hasErrors('reviewer_id') ? 'border: 1px solid #dd4b39; background: #fdf1ef' : null,
            ]) ?>
        </label>

        <label style="display: flex; flex-direction: column; gap: 5px; margin: 0">
            <span>
                <?= Html::encode(Yii::t('knowledge-library', 'Reason')) ?>
                <span style="font-weight: 400; color: #777; font-size: 12px"><?= Html::encode(Yii::t('knowledge-library', '(optional)')) ?></span>
            </span>
            <?= Html::textarea('reason', $reason, [
                'id' => 'knowledge-library-reviewer-reason',
                'class' => 'form-control',
                'rows' => 3,
                'style' => 'resize: vertical',
            ]) ?>
        </label>

        <?php if ($error !== null): ?>
            <div class="knowledge-library-reviewer-error" style="color: #a8281a">
                <i class="fa fa-times-circle"></i> <?= Html::encode($error) ?>
            </div>
        <?php endif ?>

        <div style="display: flex; align-items: center; gap: 8px; padding-top: 6px">
            <?= Html::a(
                Html::encode(Yii::t('knowledge-library', 'Cancel')),
                ['item/view', 'id' => $item->id],
                ['class' => 'btn knowledge-library-reviewer-cancel', 'style' => $buttonStyle]
            ) ?>
            <span style="flex: 1"></span>
            <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Hand over')), [
                'class' => 'btn knowledge-library-reviewer-submit',
                'style' => $primaryStyle,
            ]) ?>
        </div>
    <?= Html::endForm() ?>
</div>
