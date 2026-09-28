<?php

/**
 * Wizard step "check": summary of the draft before publishing.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\ValidityCheck $check
 * @var bool $canPublish whether the draft can be published directly
 * @var array<string, string> $topicOptions map `topic ID => name`
 */

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
    <?php endif ?>
</div>
