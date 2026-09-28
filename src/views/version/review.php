<?php

/**
 * Review page of a version in review: message of the submitter, validity,
 * consequences, topics, text and files, and the form to return the version
 * (note required) or to approve and publish it.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model version in review
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var dmstr\knowledgeLibrary\models\ValidityCheck $check
 * @var string[] $topics names of the topics of the version, ordered by name
 * @var string $requestedByName name of the person who submitted the version
 */

use dmstr\knowledgeLibrary\controllers\VersionController;
use dmstr\knowledgeLibrary\models\File;
use yii\helpers\Html;
use yii\helpers\Markdown;
use yii\helpers\Url;

$this->title = Yii::t('knowledge-library', 'Review version {number}', ['number' => (int)$model->number]);
$this->context->setBreadcrumbs([
    ['label' => $item->title, 'url' => ['item/view', 'id' => $item->id]],
    $this->title,
]);

$formatter = Yii::$app->formatter;
$buttonStyle = 'background: #f4f4f4; border: 1px solid #ddd; color: #444;';
$primaryStyle = 'background: #00a65a; border: 1px solid #008d4c; color: #fff; font-weight: 600;';
$labelStyle = 'color: #777; font-weight: 400';

$requestedAt = $model->review_requested_at === null || $model->review_requested_at === ''
    ? null
    : $formatter->asDatetime($model->review_requested_at, 'short');
$from = $requestedAt === null ? $requestedByName : $requestedByName . ', ' . $requestedAt;

$validity = '–';
if ($check->isValid()) {
    $validity = $formatter->asDate($check->getValidFrom()) . ' – '
        . ($check->getValidUntil() === null
            ? Yii::t('knowledge-library', 'open-ended')
            : $formatter->asDate($check->getValidUntil()));
}

$files = [];
foreach ($model->mainFiles as $file) {
    $files[] = [$file, Yii::t('knowledge-library', 'Main File'), 'fa-file-o'];
}
foreach ($model->attachments as $file) {
    $files[] = [$file, Yii::t('knowledge-library', 'Attachment'), 'fa-paperclip'];
}
?>
<div class="knowledge-library-version-review">
    <div style="display: flex; align-items: baseline; gap: 12px; padding: 12px 15px; border-bottom: 1px solid #f4f4f4">
        <h3 style="margin: 0; font-size: 22px; font-weight: 600"><?= Html::encode($this->title) ?></h3>
        <span style="color: #777"><?= Html::encode($item->title) ?></span>
    </div>

    <div style="padding: 20px 15px; max-width: 860px; display: flex; flex-direction: column; gap: 16px">
        <?php if ($model->review_message !== null && trim($model->review_message) !== ''): ?>
            <div class="knowledge-library-review-message" style="border-left: 3px solid #3c8dbc; background: #f2f8fc; padding: 10px 14px">
                <div style="font-size: 12px; color: #777; margin-bottom: 3px"><?= Html::encode(Yii::t('knowledge-library', 'Message from {name}', ['name' => $from])) ?></div>
                <div style="font-weight: 400"><?= nl2br(Html::encode($model->review_message)) ?></div>
            </div>
        <?php else: ?>
            <div class="knowledge-library-review-no-message" style="font-size: 13px; color: #777; font-weight: 400">
                <?= Html::encode(Yii::t('knowledge-library', 'Sent for review by {name}, without message.', ['name' => $from])) ?>
            </div>
        <?php endif ?>

        <?php if ($item->is_archived): ?>
            <div class="knowledge-library-review-archived" style="color: #a8281a">
                <i class="fa fa-archive"></i> <?= Html::encode(Yii::t('knowledge-library', 'The knowledge object is archived.')) ?>
            </div>
        <?php endif ?>

        <div class="knowledge-library-review-facts" style="display: grid; grid-template-columns: 110px 1fr; gap: 8px 12px">
            <span style="<?= $labelStyle ?>"><?= Html::encode(Yii::t('knowledge-library', 'Validity')) ?></span>
            <span class="knowledge-library-review-validity"><?= Html::encode($validity) ?></span>
            <span style="<?= $labelStyle ?>"><?= Html::encode(Yii::t('knowledge-library', 'Consequences')) ?></span>
            <div class="knowledge-library-review-consequences" style="display: flex; flex-direction: column; gap: 3px; font-weight: 400">
                <?php if (!$check->isValid()): ?>
                    <div class="knowledge-library-wizard-error" style="color: #a8281a"><i class="fa fa-times-circle"></i> <?= Html::encode($check->getErrors()[0] ?? '') ?></div>
                <?php endif ?>
                <?php foreach ($check->getConsequences() as $consequence): ?>
                    <div class="knowledge-library-review-consequence"><?= Html::encode($consequence) ?></div>
                <?php endforeach ?>
            </div>
            <span style="<?= $labelStyle ?>"><?= Html::encode(Yii::t('knowledge-library', 'Topics')) ?></span>
            <span class="knowledge-library-review-topics" style="font-weight: 400"><?= Html::encode($topics === [] ? Yii::t('knowledge-library', 'none') : implode(', ', $topics)) ?></span>
        </div>

        <div>
            <div style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'Text')) ?></div>
            <?php if (trim((string)$model->content) === ''): ?>
                <div class="text-muted knowledge-library-review-no-text"><?= Html::encode(Yii::t('knowledge-library', 'No text available.')) ?></div>
            <?php else: ?>
                <div class="knowledge-library-review-text" style="border: 1px solid #e3e6ea; background: #f9fafb; border-radius: 3px; padding: 10px 14px; line-height: 1.6">
                    <?= Markdown::process(Html::encode($model->content), 'gfm') ?>
                </div>
            <?php endif ?>
        </div>

        <div>
            <div style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'Files')) ?></div>
            <?php if ($files === []): ?>
                <div class="text-muted knowledge-library-review-no-files"><?= Html::encode(Yii::t('knowledge-library', 'No files.')) ?></div>
            <?php else: ?>
                <?php /** @var File $file */ foreach ($files as [$file, $role, $icon]): ?>
                    <div class="knowledge-library-review-file" style="display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f4f4f4">
                        <i class="fa <?= $icon ?>" style="width: 16px"></i>
                        <span style="font-family: ui-monospace, Menlo, monospace; font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis"><?= Html::encode($file->kind === File::KIND_ATTACHMENT && $file->title !== null && $file->title !== '' ? $file->title . ' (' . $file->name . ')' : $file->name) ?></span>
                        <span style="color: #777"><?= Html::encode($role) ?></span>
                        <?= Html::a(
                            '<i class="fa fa-download"></i> ' . Html::encode(Yii::t('knowledge-library', 'Download')),
                            ['file/download', 'id' => $file->id],
                            ['class' => 'btn btn-default btn-xs knowledge-library-review-download']
                        ) ?>
                    </div>
                <?php endforeach ?>
            <?php endif ?>
        </div>

        <?= Html::beginForm(['approve', 'id' => $model->id], 'post', ['id' => 'knowledge-library-review-form']) ?>
            <label style="display: flex; flex-direction: column; gap: 5px; margin: 0">
                <span>
                    <?= Html::encode(Yii::t('knowledge-library', 'Note')) ?>
                    <span style="font-weight: 400; color: #777; font-size: 12px"><?= Html::encode(Yii::t('knowledge-library', '(required for returning)')) ?></span>
                </span>
                <?= Html::textarea('note', '', [
                    'id' => 'knowledge-library-review-note',
                    'class' => 'form-control',
                    'rows' => 3,
                    'style' => 'resize: vertical',
                ]) ?>
            </label>

            <div class="knowledge-library-review-footer" style="display: flex; align-items: center; gap: 8px; padding-top: 16px">
                <?= Html::a(
                    Html::encode(Yii::t('knowledge-library', 'Cancel')),
                    ['item/view', 'id' => $item->id],
                    ['class' => 'btn knowledge-library-review-cancel', 'style' => $buttonStyle]
                ) ?>
                <span style="flex: 1"></span>
                <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Return')), [
                    'class' => 'btn knowledge-library-review-return',
                    'style' => $buttonStyle,
                    'formaction' => Url::to(['return', 'id' => $model->id]),
                ]) ?>
                <?php if (!$item->is_archived): ?>
                    <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Approve and publish')), [
                        'class' => 'btn knowledge-library-review-approve',
                        'style' => $primaryStyle,
                        'data-confirm' => VersionController::publishConfirmation($model, $check),
                    ]) ?>
                <?php endif ?>
            </div>
        <?= Html::endForm() ?>
    </div>
</div>
