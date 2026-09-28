<?php

/**
 * Tab "versions" of the detail page: all versions except drafts, highest
 * number first, with validity and state.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var dmstr\knowledgeLibrary\models\Version[] $versions versions without drafts, highest number first
 */

use dmstr\knowledgeLibrary\models\ValidityCheck;
use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

$formatter = Yii::$app->formatter;
// Periods as in the timeline, also for types without validity period.
$bars = ArrayHelper::index(ValidityCheck::timeline($model)['v'], 'n');

$badgeStyle = 'display: inline-block; padding: 2px 6px 3px; font-size: 11px; font-weight: 700;'
    . ' border-radius: 3px; line-height: 1.2; border: 1px solid; ';
// Label, background, text colour and border colour of the click dummy.
$badges = [
    Version::STATE_IN_FORCE => [Yii::t('knowledge-library', 'In Force'), '#00a65a', '#fff', '#00a65a'],
    Version::STATE_HISTORICAL => [Yii::t('knowledge-library', 'Historical'), '#d2d6de', '#444', '#d2d6de'],
    Version::STATE_UPCOMING => [Yii::t('knowledge-library', 'Upcoming'), '#00c0ef', '#fff', '#00c0ef'],
    Version::STATUS_WITHDRAWN => [Yii::t('knowledge-library', 'Withdrawn'), '#dd4b39', '#fff', '#dd4b39'],
    Version::STATUS_IN_REVIEW => [Yii::t('knowledge-library', 'In Review'), '#fff', '#c87f0a', '#f39c12'],
];
?>
<?php if ($versions === []): ?>
    <div class="text-muted knowledge-library-no-versions"><?= Html::encode(Yii::t('knowledge-library', 'No versions yet.')) ?></div>
<?php else: ?>
    <table class="table table-striped knowledge-library-item-versions">
        <thead>
        <tr>
            <th style="width: 60px"><?= Html::encode(Yii::t('knowledge-library', 'No.')) ?></th>
            <th><?= Html::encode(Yii::t('knowledge-library', 'Validity')) ?></th>
            <th style="width: 140px"><?= Html::encode(Yii::t('knowledge-library', 'Status')) ?></th>
            <th><span class="sr-only"><?= Html::encode(Yii::t('knowledge-library', 'Actions')) ?></span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($versions as $version): ?>
            <?php
            $state = in_array($version->status, [Version::STATUS_WITHDRAWN, Version::STATUS_IN_REVIEW], true)
                ? $version->status
                : ($version->getEffectiveState() ?? Version::STATE_HISTORICAL);
            [$label, $background, $color, $border] = $badges[$state];
            $bar = $bars[(int)$version->number] ?? null;
            $from = $bar['a'] ?? $version->valid_from;
            $until = $bar !== null ? $bar['b'] : $version->valid_until;
            $range = $from === null || $from === ''
                ? '–'
                : $formatter->asDate($from) . ' – '
                . ($until === null || $until === '' ? Yii::t('knowledge-library', 'open-ended') : $formatter->asDate($until));
            ?>
            <tr class="knowledge-library-version-row" data-number="<?= (int)$version->number ?>">
                <td><?= (int)$version->number ?></td>
                <td style="white-space: nowrap<?= $state === Version::STATUS_WITHDRAWN ? '; text-decoration: line-through' : '' ?>"><?= Html::encode($range) ?></td>
                <td>
                    <?= Html::tag('span', Html::encode($label), [
                        'class' => 'knowledge-library-version-state knowledge-library-version-state-' . $state,
                        'style' => $badgeStyle . "background: $background; color: $color; border-color: $border",
                    ]) ?>
                </td>
                <td class="text-right">
                    <?= Html::a(
                        Html::encode(Yii::t('knowledge-library', 'View')),
                        ['view', 'id' => $model->id, 'version' => (int)$version->number, 'tab' => 'content'],
                        ['class' => 'btn btn-default btn-xs knowledge-library-version-view']
                    ) ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
