<?php

/**
 * List of the items valid today: one list entry per item with the title as
 * link to the detail page, the type and the topics.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item[] $items sorted by title and ID
 */

use yii\helpers\Html;

$this->title = Yii::t('knowledge-library', 'Valid knowledge objects');
$this->context->setBreadcrumbs([$this->title]);
?>
<div class="knowledge-item-index">
    <h1><?= Html::encode($this->title) ?></h1>

    <?php if ($items === []): ?>
        <p class="text-muted knowledge-items-empty"><?= Html::encode(Yii::t('knowledge-library', 'No valid knowledge objects.')) ?></p>
    <?php else: ?>
        <ul class="knowledge-items">
            <?php foreach ($items as $item): ?>
                <?php
                $topicNames = array_map(static fn ($topic) => (string)$topic->name, $item->topics);
                sort($topicNames);
                ?>
                <li data-item-id="<?= Html::encode($item->id) ?>">
                    <?= Html::a(Html::encode($item->title), ['view', 'id' => $item->id], ['class' => 'knowledge-item-link']) ?>
                    <?php if ($item->type !== null): ?>
                        <span class="knowledge-item-type"><?= Html::encode(Yii::t('knowledge-library', 'Type')) ?>: <?= Html::encode($item->type->name) ?></span>
                    <?php endif ?>
                    <?php if ($topicNames !== []): ?>
                        <span class="knowledge-item-topics"><?= Html::encode(Yii::t('knowledge-library', 'Topics')) ?>: <?= Html::encode(implode(', ', $topicNames)) ?></span>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>
