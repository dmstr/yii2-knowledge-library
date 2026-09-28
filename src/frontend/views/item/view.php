<?php

/**
 * Detail page of an item valid today: master data, validity and source, the
 * summary, the text and the files of the valid version. Empty fields are
 * left out.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var dmstr\knowledgeLibrary\models\Version $version the version valid today
 */

use dmstr\knowledgeLibrary\helpers\MarkdownHelper;
use yii\helpers\Html;

$this->title = $item->title;
$this->context->setBreadcrumbs([$this->title]);

$formatter = Yii::$app->formatter;
$formatSize = static fn ($size) => $size === null ? '' : $formatter->asShortSize((int)$size, 1);
$filled = static fn ($value) => $value !== null && trim((string)$value) !== '';

$topicNames = array_map(static fn ($topic) => (string)$topic->name, $item->topics);
sort($topicNames);

if ($item->type !== null && !$item->type->has_validity_period) {
    $validity = $version->published_at === null ? null : Yii::t('knowledge-library', 'Valid since {date}', [
        'date' => $formatter->asDate($version->published_at),
    ]);
} else {
    $validity = $version->valid_from === null ? null : Yii::t('knowledge-library', 'Valid from {from} until {until}', [
        'from' => $formatter->asDate($version->valid_from),
        'until' => $version->valid_until === null
            ? Yii::t('knowledge-library', 'open-ended')
            : $formatter->asDate($version->valid_until),
    ]);
}

$mainFiles = $version->mainFiles;
$attachments = $version->attachments;
?>
<article class="knowledge-item" data-item-id="<?= Html::encode($item->id) ?>">
    <h1><?= Html::encode($item->title) ?></h1>

    <dl class="dl-horizontal knowledge-item-details">
        <?php if ($item->type !== null): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Type')) ?></dt>
            <dd class="knowledge-item-type"><?= Html::encode($item->type->name) ?></dd>
        <?php endif ?>
        <?php if ($topicNames !== []): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Topics')) ?></dt>
            <dd class="knowledge-item-topics"><?= Html::encode(implode(', ', $topicNames)) ?></dd>
        <?php endif ?>
        <?php if ($validity !== null): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Validity')) ?></dt>
            <dd class="knowledge-item-validity"><?= Html::encode($validity) ?></dd>
        <?php endif ?>
        <?php if ($filled($item->source_name)): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Source')) ?></dt>
            <dd class="knowledge-item-source"><?= Html::encode($item->source_name) ?></dd>
        <?php endif ?>
        <?php if ($filled($item->source_reference)): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Source Reference')) ?></dt>
            <dd class="knowledge-item-source-reference"><?= Html::encode($item->source_reference) ?></dd>
        <?php endif ?>
        <?php if ($filled($item->source_url)): ?>
            <dt><?= Html::encode(Yii::t('knowledge-library', 'Source URL')) ?></dt>
            <dd class="knowledge-item-source-url"><?= Html::a(Html::encode($item->source_url), $item->source_url, ['rel' => 'noopener']) ?></dd>
        <?php endif ?>
    </dl>

    <?php if ($filled($item->summary)): ?>
        <section class="knowledge-summary">
            <h2><?= Html::encode(Yii::t('knowledge-library', 'Summary')) ?></h2>
            <p><?= nl2br(Html::encode($item->summary)) ?></p>
        </section>
    <?php endif ?>

    <section class="knowledge-content">
        <h2><?= Html::encode(Yii::t('knowledge-library', 'Content')) ?></h2>
        <?php if (!$filled($version->content)): ?>
            <p class="text-muted knowledge-content-empty"><?= Html::encode(Yii::t('knowledge-library', 'No text available.')) ?></p>
        <?php else: ?>
            <?= MarkdownHelper::render($version->content) ?>
        <?php endif ?>
    </section>

    <section class="knowledge-files">
        <h2><?= Html::encode(Yii::t('knowledge-library', 'Files')) ?></h2>

        <h3><?= Html::encode(Yii::t('knowledge-library', 'Main Files')) ?></h3>
        <?php if ($mainFiles === []): ?>
            <p class="text-muted knowledge-files-no-main-files"><?= Html::encode(Yii::t('knowledge-library', 'No main documents.')) ?></p>
        <?php else: ?>
            <ul class="knowledge-main-files">
                <?php foreach ($mainFiles as $file): ?>
                    <li>
                        <?= Html::a(Html::encode($file->name), ['file/download', 'id' => $file->id]) ?>
                        <?php if ($file->size !== null): ?>
                            <span class="knowledge-file-size">(<?= Html::encode($formatSize($file->size)) ?>)</span>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <h3><?= Html::encode(Yii::t('knowledge-library', 'Attachments')) ?></h3>
        <?php if ($attachments === []): ?>
            <p class="text-muted knowledge-files-no-attachments"><?= Html::encode(Yii::t('knowledge-library', 'No attachments.')) ?></p>
        <?php else: ?>
            <ul class="knowledge-attachments">
                <?php foreach ($attachments as $file): ?>
                    <li>
                        <?= Html::a(Html::encode($filled($file->title) ? $file->title : $file->name), ['file/download', 'id' => $file->id]) ?>
                        <?php if ($filled($file->title)): ?>
                            <span class="knowledge-file-name"><?= Html::encode($file->name) ?></span>
                        <?php endif ?>
                        <?php if ($file->size !== null): ?>
                            <span class="knowledge-file-size">(<?= Html::encode($formatSize($file->size)) ?>)</span>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</article>
