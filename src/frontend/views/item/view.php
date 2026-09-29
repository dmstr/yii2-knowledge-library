<?php

/**
 * Detail page of an item valid today: master data, validity and source, the
 * summary, the text and the files of the valid version and the related
 * items. Empty fields are left out.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $item
 * @var dmstr\knowledgeLibrary\models\Version $version the version valid today
 * @var array<string, dmstr\knowledgeLibrary\models\Item[]> $relations related items shown in the frontend, by label
 */

use dmstr\knowledgeLibrary\helpers\MarkdownHelper;
use dmstr\knowledgeLibrary\models\Relation;
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
// Class of the list => heading and files; a title is shown instead of the
// file name, which follows it.
$fileLists = [
    'knowledge-main-files' => [Yii::t('knowledge-library', 'Main Files'), $mainFiles],
    'knowledge-attachments' => [Yii::t('knowledge-library', 'Attachments'), $attachments],
];
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

    <?php if ($filled($version->content)): ?>
        <section class="knowledge-content">
            <h2><?= Html::encode(Yii::t('knowledge-library', 'Content')) ?></h2>
            <?= MarkdownHelper::render($version->content) ?>
        </section>
    <?php endif ?>

    <?php if ($mainFiles !== [] || $attachments !== []): ?>
        <section class="knowledge-files">
            <h2><?= Html::encode(Yii::t('knowledge-library', 'Files')) ?></h2>

            <?php foreach ($fileLists as $list => [$heading, $files]): ?>
                <?php if ($files !== []): ?>
                    <h3><?= Html::encode($heading) ?></h3>
                    <ul class="<?= $list ?>">
                        <?php foreach ($files as $file): ?>
                            <li>
                                <?= Html::a(Html::encode($file->getDisplayName()), ['file/download', 'id' => $file->id]) ?>
                                <?php if ($file->getDisplayName() !== $file->name): ?>
                                    <span class="knowledge-file-name"><?= Html::encode($file->name) ?></span>
                                <?php endif ?>
                                <?php if ($file->size !== null): ?>
                                    <span class="knowledge-file-size">(<?= Html::encode($formatSize($file->size)) ?>)</span>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            <?php endforeach ?>
        </section>
    <?php endif ?>

    <?php if ($relations !== []): ?>
        <section class="knowledge-relations">
            <h2><?= Html::encode(Yii::t('knowledge-library', 'Relations')) ?></h2>
            <dl class="knowledge-relations-list">
                <?php foreach ($relations as $label => $relatedItems): ?>
                    <dt><?= Html::encode(Relation::capitalize($label)) ?></dt>
                    <?php foreach ($relatedItems as $related): ?>
                        <dd><?= Html::a(Html::encode($related->title), ['item/view', 'id' => $related->id], ['class' => 'knowledge-relation-link']) ?></dd>
                    <?php endforeach ?>
                <?php endforeach ?>
            </dl>
        </section>
    <?php endif ?>
</article>
