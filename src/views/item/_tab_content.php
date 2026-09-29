<?php

/**
 * Tab "content" of the detail page: text (Markdown), main files and
 * attachments of the selected version, with a version select.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var dmstr\knowledgeLibrary\models\Version[] $versions versions without drafts, highest number first
 * @var dmstr\knowledgeLibrary\models\Version|null $selectedVersion
 */

use dmstr\knowledgeLibrary\helpers\MarkdownHelper;
use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\Html;

$stateLabels = Version::effectiveStates();
$statusLabels = Version::statuses();
$statusLabel = static function (Version $version) use ($stateLabels, $statusLabels): string {
    if ($version->status === Version::STATUS_PUBLISHED) {
        return $stateLabels[$version->getEffectiveState()] ?? $statusLabels[$version->status];
    }

    return $statusLabels[$version->status] ?? (string)$version->status;
};
$formatSize = static fn ($size) => $size === null ? '' : Yii::$app->formatter->asShortSize((int)$size, 1);
$monospace = 'font-family: ui-monospace, Menlo, monospace; font-size: 13px';
?>
<?php if ($selectedVersion === null): ?>
    <div class="text-muted knowledge-library-no-versions"><?= Html::encode(Yii::t('knowledge-library', 'No versions yet.')) ?></div>
<?php else: ?>
    <?php
    $options = [];
    foreach ($versions as $version) {
        $options[(int)$version->number] = Yii::t('knowledge-library', 'Version {number}, {status}', [
            'number' => (int)$version->number,
            'status' => $statusLabel($version),
        ]);
    }
    $mainFiles = $selectedVersion->mainFiles;
    $attachments = $selectedVersion->attachments;
    ?>
    <?= Html::beginForm(['view'], 'get', ['class' => 'form-inline knowledge-library-content-version', 'style' => 'margin-bottom: 16px']) ?>
        <?= Html::hiddenInput('id', $model->id) ?>
        <?= Html::hiddenInput('tab', 'content') ?>
        <?= Html::dropDownList('version', (int)$selectedVersion->number, $options, [
            'class' => 'form-control',
            'id' => 'knowledge-library-content-version',
            'onchange' => 'this.form.submit()',
        ]) ?>
        <noscript><?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Show')), ['class' => 'btn btn-default']) ?></noscript>
    <?= Html::endForm() ?>

    <div style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'Text')) ?></div>
    <?php if (trim((string)$selectedVersion->content) === ''): ?>
        <div class="text-muted knowledge-library-content-no-text" style="margin-bottom: 20px"><?= Html::encode(Yii::t('knowledge-library', 'No text available.')) ?></div>
    <?php else: ?>
        <div class="knowledge-library-content-text" style="border: 1px solid #e3e6ea; background: #f9fafb; border-radius: 3px; padding: 12px 16px; line-height: 1.6; max-width: 860px; margin-bottom: 20px">
            <?= MarkdownHelper::render($selectedVersion->content) ?>
        </div>
    <?php endif ?>

    <div style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'Main Files')) ?></div>
    <?php if ($mainFiles === []): ?>
        <div class="text-muted knowledge-library-content-no-main-files" style="margin-bottom: 20px"><?= Html::encode(Yii::t('knowledge-library', 'No main documents.')) ?></div>
    <?php else: ?>
        <table class="table table-striped knowledge-library-content-main-files" style="margin-bottom: 20px">
            <tbody>
            <?php foreach ($mainFiles as $file): ?>
                <tr>
                    <td>
                        <i class="fa fa-file-o" style="width: 20px"></i><?= Html::a(Html::encode($file->getDisplayName()), ['file/download', 'id' => $file->id]) ?>
                    </td>
                    <td style="<?= $monospace ?>; color: #555"><?= Html::encode($file->name) ?></td>
                    <td style="width: 90px; color: #555"><?= Html::encode($formatSize($file->size)) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>

    <div style="margin-bottom: 6px"><?= Html::encode(Yii::t('knowledge-library', 'Attachments')) ?></div>
    <?php if ($attachments === []): ?>
        <div class="text-muted knowledge-library-content-no-attachments"><?= Html::encode(Yii::t('knowledge-library', 'No attachments.')) ?></div>
    <?php else: ?>
        <table class="table table-striped knowledge-library-content-attachments">
            <tbody>
            <?php foreach ($attachments as $file): ?>
                <tr>
                    <td>
                        <i class="fa fa-paperclip" style="width: 20px"></i><?= Html::a(Html::encode($file->getDisplayName()), ['file/download', 'id' => $file->id]) ?>
                    </td>
                    <td style="<?= $monospace ?>; color: #555"><?= Html::encode($file->name) ?></td>
                    <td style="width: 90px; color: #555"><?= Html::encode($formatSize($file->size)) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
<?php endif ?>
