<?php

/**
 * Wizard step "check": summary of the draft before publishing; for types with
 * review the reviewer select and the message for submitting it for approval.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\ValidityCheck $check
 * @var bool $canPublish whether the draft can be published directly
 * @var array<string, string> $topicOptions map `topic ID => name`
 * @var array<string, string> $reviewerOptions map `user reference => name` without the current user
 * @var string $reviewer selected reviewer (user reference)
 * @var string $reviewMessage message to the reviewer
 */

use dmstr\knowledgeLibrary\controllers\VersionController;
use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\Html;

$formatter = Yii::$app->formatter;
$none = Yii::t('knowledge-library', 'none');
$names = static fn (array $files) => $files === []
    ? $none
    : implode(', ', array_map(static fn ($file) => $file->name, $files));

$textStates = [
    Version::CONTENT_CHANGED => Yii::t('knowledge-library', 'changed'),
    Version::CONTENT_UNCHANGED => Yii::t('knowledge-library', 'unchanged'),
    Version::CONTENT_NONE => Yii::t('knowledge-library', 'no text'),
];

$validity = '';
if ($check->isValid()) {
    $validity = $formatter->asDate($check->getValidFrom()) . ' – '
        . ($check->getValidUntil() === null
            ? Yii::t('knowledge-library', 'open-ended')
            : $formatter->asDate($check->getValidUntil()));
}

$topics = array_values(array_filter(array_map(
    static fn ($id) => $topicOptions[$id] ?? null,
    $model->getDraftTopicIds()
)));
sort($topics);

$rows = [
    'text' => [Yii::t('knowledge-library', 'Text'), $textStates[$model->getContentChange()]],
    'main-files' => [Yii::t('knowledge-library', 'Main Files'), $names($model->mainFiles)],
    'attachments' => [Yii::t('knowledge-library', 'Attachments'), $names($model->attachments)],
    'validity' => [Yii::t('knowledge-library', 'Validity'), $validity],
    'topics' => [Yii::t('knowledge-library', 'Topics'), $topics === [] ? $none : implode(', ', $topics)],
];
?>
<div class="knowledge-library-wizard-review" style="max-width: 760px">
    <?php foreach ($rows as $key => [$label, $value]): ?>
        <div class="knowledge-library-review-<?= $key ?>" style="display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f4f4f4">
            <span style="width: 140px; color: #777; font-weight: 400"><?= Html::encode($label) ?></span>
            <span class="knowledge-library-review-value"><?= Html::encode($value) ?></span>
        </div>
    <?php endforeach ?>

    <?php if (!$check->isValid()): ?>
        <div class="knowledge-library-wizard-error" style="margin-top: 12px; color: #a8281a">
            <i class="fa fa-times-circle"></i> <?= Html::encode($check->getErrors()[0]) ?>
        </div>
    <?php endif ?>

    <?php if (!$canPublish): ?>
        <div class="knowledge-library-wizard-approval" style="margin-top: 16px; background: #f39c12; border-left: 5px solid #c87f0a; color: #fff; border-radius: 3px; padding: 12px 15px">
            <?= Html::encode(Yii::t('knowledge-library', 'Approval by a second person required.')) ?>
        </div>

        <?php
        $submitError = $model->getFirstError('reviewer_id') ?? $model->getFirstError('status');
        if ($submitError === null && $model->hasErrors()) {
            $submitError = (string)array_values($model->getFirstErrors())[0];
        }
        ?>
        <div class="knowledge-library-wizard-submit-fields" style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 14px; align-items: flex-start">
            <label style="display: flex; flex-direction: column; gap: 5px; width: 240px">
                <?= Html::encode(Yii::t('knowledge-library', 'Reviewing person')) ?>
                <?= Html::dropDownList(VersionController::FIELD_REVIEWER, $reviewer, $reviewerOptions, [
                    'id' => 'knowledge-library-wizard-reviewer',
                    'class' => 'form-control',
                    'prompt' => Yii::t('knowledge-library', 'Select a person'),
                    'style' => $model->hasErrors('reviewer_id')
                        ?'border: 1px solid #dd4b39; background: #fdf1ef'
                        : null,
                ]) ?>
            </label>
            <label style="display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 240px">
                <span>
                    <?= Html::encode(Yii::t('knowledge-library', 'Message')) ?>
                    <span style="font-weight: 400; color: #777; font-size: 12px"><?= Html::encode(Yii::t('knowledge-library', '(optional)')) ?></span>
                </span>
                <?= Html::textarea(VersionController::FIELD_MESSAGE, $reviewMessage, [
                    'id' => 'knowledge-library-wizard-message',
                    'class' => 'form-control',
                    'rows' => 3,
                    'style' => 'resize: vertical',
                ]) ?>
            </label>
        </div>
        <?php if ($submitError !== null): ?>
            <div class="knowledge-library-wizard-error knowledge-library-wizard-submit-error" style="margin-top: 12px; color: #a8281a">
                <i class="fa fa-times-circle"></i> <?= Html::encode($submitError) ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</div>
