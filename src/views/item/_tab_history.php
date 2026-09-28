<?php

/**
 * Tab "history" of the detail page: when, who, what and why, newest first.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\History[] $history newest first
 * @var dmstr\knowledgeLibrary\users\UserProviderInterface $userProvider
 */

use yii\helpers\Html;

$formatter = Yii::$app->formatter;
$empty = '–';
?>
<?php if ($history === []): ?>
    <div class="text-muted knowledge-library-history-empty"><?= Html::encode(Yii::t('knowledge-library', 'No entries yet.')) ?></div>
<?php else: ?>
    <table class="table table-striped knowledge-library-item-history">
        <thead>
        <tr>
            <th style="width: 150px"><?= Html::encode(Yii::t('knowledge-library', 'When')) ?></th>
            <th style="width: 160px"><?= Html::encode(Yii::t('knowledge-library', 'Who')) ?></th>
            <th><?= Html::encode(Yii::t('knowledge-library', 'What')) ?></th>
            <th><?= Html::encode(Yii::t('knowledge-library', 'Reason')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $entry): ?>
            <?php
            $actor = $entry->actor_id === null || $entry->actor_id === ''
                ? $empty
                : ($userProvider->getDisplayName($entry->actor_id) ?? $entry->actor_id);
            ?>
            <tr class="knowledge-library-history-row" data-action="<?= Html::encode($entry->action) ?>">
                <td class="knowledge-library-history-when" style="white-space: nowrap"><?= Html::encode($entry->created_at === null ? $empty : $formatter->asDatetime($entry->created_at, 'short')) ?></td>
                <td class="knowledge-library-history-who"><?= Html::encode($actor) ?></td>
                <td class="knowledge-library-history-what"><?= Html::encode($entry->describe($userProvider)) ?></td>
                <td class="knowledge-library-history-reason" style="color: #555"><?= $entry->reason === null || trim($entry->reason) === ''
                    ? $empty
                    : nl2br(Html::encode($entry->reason)) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
