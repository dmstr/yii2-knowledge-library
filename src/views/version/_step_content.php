<?php

/**
 * Wizard step "content": Markdown text and the files of the draft.
 *
 * The text is marked orange when it differs from the predecessor. The files
 * section lists the main files and attachments of the draft.
 *
 * @var yii\web\View $this
 * @var yii\bootstrap\ActiveForm $form
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var string|null $message why the step is not complete
 */

use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\Html;

$predecessor = $model->getPredecessor();
$changed = $predecessor !== null && $model->getContentChange() === Version::CONTENT_CHANGED;
$mainFiles = $model->mainFiles;
$attachments = $model->attachments;
$formatSize = static fn ($size) => $size === null ? '' : Yii::$app->formatter->asShortSize((int)$size, 1);
$rowStyle = 'display: flex; align-items: center; gap: 12px; padding: 9px 12px; border: 1px solid #e3e6ea;'
    . ' border-radius: 3px; margin-bottom: 6px; background: #fff';
?>
<div class="knowledge-library-wizard-content" style="max-width: 860px; display: flex; flex-direction: column; gap: 22px">
    <div class="knowledge-library-wizard-text">
        <?= $form->field($model, 'content')
            ->label(Yii::t('knowledge-library', 'Text'))
            ->textarea([
                'rows' => 10,
                'placeholder' => Yii::t('knowledge-library', 'Enter the content as free text'),
                'data-kl-blocks-next' => '1',
                // Text of the predecessor, for marking changes while typing.
                'data-kl-base-text' => $predecessor !== null ? (string)$predecessor->content : null,
                'class' => 'form-control' . ($changed ? ' knowledge-library-text-changed' : ''),
                'style' => $changed ? 'border-color: #f39c12; background: #fffbf0' : null,
            ])
            ->hint($changed
                ? Yii::t('knowledge-library', 'Changed compared to version {number}. Markdown is supported.', [
                    'number' => (int)$predecessor->number,
                ])
                : Yii::t('knowledge-library', 'Markdown is supported.')) ?>
    </div>

    <?php // Files of the draft; uploads and keep/remove are added to this section. ?>
    <div class="knowledge-library-wizard-files">
        <div class="knowledge-library-wizard-main-files">
            <div style="margin-bottom: 6px">
                <?= Html::encode(Yii::t('knowledge-library', 'Main Files')) ?>
                <span style="color: #777; font-weight: 400"><?= Html::encode(Yii::t('knowledge-library', '(optional, several possible)')) ?></span>
            </div>
            <?php foreach ($mainFiles as $file): ?>
                <div class="knowledge-library-wizard-file" data-kl-main-file="1" style="<?= $rowStyle ?>">
                    <i class="fa fa-file-o" style="width: 16px"></i>
                    <span style="flex: 1; font-family: ui-monospace, Menlo, monospace; font-size: 13px"><?= Html::encode($file->name) ?></span>
                    <span style="color: #777"><?= Html::encode($formatSize($file->size)) ?></span>
                </div>
            <?php endforeach ?>
            <?php if ($mainFiles === []): ?>
                <div class="text-muted"><?= Html::encode(Yii::t('knowledge-library', 'No main documents.')) ?></div>
            <?php endif ?>
        </div>

        <div class="knowledge-library-wizard-attachments" style="margin-top: 22px">
            <div style="margin-bottom: 6px">
                <?= Html::encode(Yii::t('knowledge-library', 'Attachments')) ?>
                <span style="color: #777; font-weight: 400"><?= Html::encode(Yii::t('knowledge-library', '(optional)')) ?></span>
            </div>
            <?php foreach ($attachments as $file): ?>
                <div class="knowledge-library-wizard-file" style="<?= $rowStyle ?>">
                    <i class="fa fa-paperclip" style="width: 16px"></i>
                    <span style="flex: 1">
                        <?= Html::encode($file->title ?? $file->name) ?>
                        <span style="font-family: ui-monospace, Menlo, monospace; font-size: 12px; color: #777; margin-left: 6px"><?= Html::encode($file->name) ?></span>
                    </span>
                    <span style="color: #777"><?= Html::encode($formatSize($file->size)) ?></span>
                </div>
            <?php endforeach ?>
            <?php if ($attachments === []): ?>
                <div class="text-muted"><?= Html::encode(Yii::t('knowledge-library', 'No attachments.')) ?></div>
            <?php endif ?>
        </div>
    </div>

    <?php if ($message !== null): ?>
        <div class="knowledge-library-wizard-error" style="color: #a8281a">
            <i class="fa fa-times-circle"></i> <?= Html::encode($message) ?>
        </div>
    <?php endif ?>
</div>
