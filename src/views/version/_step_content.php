<?php

/**
 * Wizard step "content": Markdown text and the files of the draft.
 *
 * The text is marked orange when it differs from the predecessor. The files
 * section lists the main files and attachments of the draft; each can be
 * marked for removal (`remove[<file-id>]`), files uploaded in this draft
 * (not taken over from the predecessor) are highlighted. New main files are
 * uploaded with `mainFiles[]`, new attachments with `attachments[<i>]` and
 * the title `attachmentTitles[<i>]`. Attachments whose content is attached
 * to another item show a hint with a link to create an item of their own.
 *
 * @var yii\web\View $this
 * @var yii\bootstrap\ActiveForm $form
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var string|null $message why the step is not complete
 */

use dmstr\knowledgeLibrary\controllers\VersionController;
use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use yii\helpers\Html;
use yii\web\View;

/** @var Module $module */
$module = $this->context->module;
$service = new FileService($module);

$predecessor = $model->getPredecessor();
$changed = $predecessor !== null && $model->getContentChange() === Version::CONTENT_CHANGED;
$mainFiles = $model->mainFiles;
$attachments = $model->attachments;
$fileErrors = $model->getErrors(VersionController::FILES_ERROR_ATTRIBUTE);

// Files whose stored file the predecessor does not refer to were uploaded in
// this draft.
$predecessorPaths = $predecessor === null
    ? []
    : File::find()->select('path')->where(['version_id' => $predecessor->id])->column();
$isNew = static fn (File $file) => !in_array($file->path, $predecessorPaths, true);

$formatSize = static fn ($size) => $size === null ? '' : Yii::$app->formatter->asShortSize((int)$size, 1);
$accept = implode(',', array_map(static fn ($extension) => '.' . $extension, $module->allowedExtensions));
$rowStyle = 'display: flex; align-items: center; gap: 12px; padding: 9px 12px; border: 1px solid #e3e6ea;'
    . ' border-radius: 3px; margin-bottom: 6px';
$toggleStyle = 'width: 96px; margin: 0; text-align: center; background: #fff; border: 1px solid #ddd; color: #444;'
    . ' padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 400; cursor: pointer';
$addStyle = 'background: #f4f4f4; border: 1px solid #ddd; color: #444; padding: 5px 10px; border-radius: 3px;'
    . ' font-size: 13px; font-weight: 400; cursor: pointer';
$monospace = 'font-family: ui-monospace, Menlo, monospace';

$removeToggle = static function (File $file) use ($toggleStyle): string {
    $removeLabel = Yii::t('knowledge-library', 'Remove');

    return Html::tag(
        'label',
        Html::checkbox('remove[' . $file->id . ']', false, [
            'value' => '1',
            'class' => 'knowledge-library-wizard-remove',
            'data-kl-label-remove' => $removeLabel,
            'data-kl-label-keep' => Yii::t('knowledge-library', 'Keep'),
        ]) . ' ' . Html::tag('span', Html::encode($removeLabel)),
        ['class' => 'knowledge-library-wizard-file-toggle', 'style' => $toggleStyle]
    );
};
$rowOptions = static function (File $file, array $options) use ($isNew, $rowStyle): array {
    $new = $isNew($file);
    Html::addCssClass($options, 'knowledge-library-wizard-file');
    if ($new) {
        Html::addCssClass($options, 'knowledge-library-wizard-file-new');
    }
    $options['data-kl-file-row'] = '1';
    $options['style'] = $rowStyle . '; background: ' . ($new ? '#fffaeb' : '#fff');

    return $options;
};

$this->registerJs(<<<'JS'
var wizard = '#knowledge-library-version-wizard';
jQuery(wizard + ' .knowledge-library-wizard-remove').hide();
jQuery(wizard + ' .knowledge-library-wizard-add-attachment').show();
jQuery(document).on('change', wizard + ' .knowledge-library-wizard-remove', function () {
    var box = jQuery(this);
    var row = box.closest('[data-kl-file-row]');
    var removed = box.prop('checked');
    row.find('.knowledge-library-wizard-file-name').css('text-decoration', removed ? 'line-through' : '');
    row.css('opacity', removed ? .6 : 1);
    box.siblings('span').text(box.attr(removed ? 'data-kl-label-keep' : 'data-kl-label-remove'));
    if (row.attr('data-kl-kind') === 'main') {
        if (removed) {
            row.removeAttr('data-kl-main-file');
        } else {
            row.attr('data-kl-main-file', '1');
        }
    }
    box.closest('form').find('[data-kl-blocks-next]').trigger('change');
});
jQuery(document).on('change', wizard + ' .knowledge-library-wizard-main-upload', function () {
    if (this.files && this.files.length > 0) {
        jQuery(this).attr('data-kl-main-file', '1');
    } else {
        jQuery(this).removeAttr('data-kl-main-file');
    }
    jQuery(this).closest('form').find('[data-kl-blocks-next]').trigger('change');
});
jQuery(document).on('click', wizard + ' .knowledge-library-wizard-add-attachment', function (event) {
    event.preventDefault();
    var list = jQuery(this).closest('.knowledge-library-wizard-attachments').find('.knowledge-library-wizard-attachment-uploads');
    var index = list.children('.knowledge-library-wizard-attachment-upload').length;
    var row = list.children('.knowledge-library-wizard-attachment-upload').first().clone();
    row.find('input').each(function () {
        var input = jQuery(this);
        input.attr('name', input.attr('name').replace(/\[\d+\]$/, '[' + index + ']'));
        input.val('');
    });
    list.append(row);
});
JS, View::POS_READY, 'knowledge-library-wizard-files');
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

    <?php // Files of the draft with keep/remove and the uploads. ?>
    <div class="knowledge-library-wizard-files">
        <div class="knowledge-library-wizard-main-files">
            <div style="margin-bottom: 6px">
                <?= Html::encode(Yii::t('knowledge-library', 'Main Files')) ?>
                <span style="color: #777; font-weight: 400"><?= Html::encode(Yii::t('knowledge-library', '(optional, several possible)')) ?></span>
            </div>
            <?php foreach ($mainFiles as $file): ?>
                <?= Html::beginTag('div', $rowOptions($file, ['data-kl-main-file' => '1', 'data-kl-kind' => File::KIND_MAIN])) ?>
                    <i class="fa fa-file-o" style="width: 16px"></i>
                    <span class="knowledge-library-wizard-file-name" style="flex: 1; <?= $monospace ?>; font-size: 13px"><?= Html::encode($file->name) ?></span>
                    <span style="color: #777"><?= Html::encode($formatSize($file->size)) ?></span>
                    <?= $removeToggle($file) ?>
                <?= Html::endTag('div') ?>
            <?php endforeach ?>
            <?php if ($mainFiles === []): ?>
                <div class="text-muted" style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'No main documents.')) ?></div>
            <?php endif ?>
            <div class="knowledge-library-wizard-main-upload-row" style="margin: 8px 0 0">
                <?= Html::label(
                    Html::encode(Yii::t('knowledge-library', 'Add main documents')),
                    'knowledge-library-wizard-main-upload',
                    ['style' => 'display: block; font-weight: 400; margin-bottom: 4px']
                ) ?>
                <?php // Native file input: works without JavaScript, several files at once. ?>
                <?= Html::fileInput('mainFiles[]', null, [
                    'id' => 'knowledge-library-wizard-main-upload',
                    'multiple' => true,
                    'accept' => $accept,
                    'class' => 'knowledge-library-wizard-main-upload',
                ]) ?>
            </div>
        </div>

        <div class="knowledge-library-wizard-attachments" style="margin-top: 22px">
            <div style="margin-bottom: 6px">
                <?= Html::encode(Yii::t('knowledge-library', 'Attachments')) ?>
                <span style="color: #777; font-weight: 400"><?= Html::encode(Yii::t('knowledge-library', '(optional)')) ?></span>
            </div>
            <?php foreach ($attachments as $file): ?>
                <?= Html::beginTag('div', $rowOptions($file, ['data-kl-kind' => File::KIND_ATTACHMENT])) ?>
                    <i class="fa fa-paperclip" style="width: 16px"></i>
                    <span class="knowledge-library-wizard-file-name" style="flex: 1">
                        <?= Html::encode($file->title ?? $file->name) ?>
                        <span style="<?= $monospace ?>; font-size: 12px; color: #777; margin-left: 6px"><?= Html::encode($file->name) ?></span>
                    </span>
                    <span style="color: #777"><?= Html::encode($formatSize($file->size)) ?></span>
                    <?= $removeToggle($file) ?>
                <?= Html::endTag('div') ?>
                <?php foreach ($service->findDuplicates($file) as $duplicate): ?>
                    <div class="knowledge-library-wizard-duplicate" style="margin: 0 0 8px; background: #f39c12; border-left: 5px solid #c87f0a; color: #fff; border-radius: 3px; padding: 12px 15px">
                        <?= Html::encode(Yii::t('knowledge-library', 'This file is already attached to "{title}".', [
                            'title' => $duplicate->version->item->title ?? '',
                        ])) ?>
                        <?= Html::a(
                            Html::encode(Yii::t('knowledge-library', 'Create as separate knowledge object')),
                            ['item/create', 'title' => $file->title ?? $file->name],
                            ['class' => 'knowledge-library-wizard-duplicate-create', 'style' => 'color: #fff; text-decoration: underline; margin-left: 6px']
                        ) ?>
                    </div>
                <?php endforeach ?>
            <?php endforeach ?>
            <?php if ($attachments === []): ?>
                <div class="text-muted" style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'No attachments.')) ?></div>
            <?php endif ?>
            <div class="knowledge-library-wizard-attachment-uploads">
                <div class="knowledge-library-wizard-attachment-upload" style="display: flex; align-items: center; gap: 12px; margin: 4px 0 6px">
                    <?= Html::fileInput('attachments[0]', null, [
                        'accept' => $accept,
                        'class' => 'knowledge-library-wizard-attachment-file',
                        'aria-label' => Yii::t('knowledge-library', 'Add attachment'),
                        'style' => 'flex: 1',
                    ]) ?>
                    <?= Html::textInput('attachmentTitles[0]', null, [
                        'class' => 'form-control',
                        'placeholder' => Yii::t('knowledge-library', 'Title of the attachment'),
                        'aria-label' => Yii::t('knowledge-library', 'Title of the attachment'),
                        'style' => 'flex: 1',
                    ]) ?>
                </div>
            </div>
            <?= Html::button(
                '<i class="fa fa-plus"></i> ' . Html::encode(Yii::t('knowledge-library', 'Add attachment')),
                [
                    'type' => 'button',
                    'class' => 'knowledge-library-wizard-add-attachment',
                    // Shown by the inline script; without JavaScript one row is available.
                    'style' => $addStyle . '; display: none',
                ]
            ) ?>
        </div>

        <div class="knowledge-library-wizard-file-limits help-block" style="margin-top: 12px">
            <?= Html::encode(Yii::t('knowledge-library', 'Allowed types: {extensions}. At most {size} per file.', [
                'extensions' => implode(', ', $module->allowedExtensions),
                'size' => $service->getFormattedMaxFileSize(),
            ])) ?>
        </div>

        <?php foreach ($fileErrors as $error): ?>
            <div class="knowledge-library-wizard-file-error" style="color: #a8281a; margin-top: 6px">
                <i class="fa fa-times-circle"></i> <?= Html::encode($error) ?>
            </div>
        <?php endforeach ?>
    </div>

    <?php if ($message !== null): ?>
        <div class="knowledge-library-wizard-error" style="color: #a8281a">
            <i class="fa fa-times-circle"></i> <?= Html::encode($message) ?>
        </div>
    <?php endif ?>
</div>
