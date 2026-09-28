<?php

/**
 * Tab "versions" of the detail page: all versions except drafts, highest
 * number first, with validity, state and the actions "View", "Correct"
 * (published, no draft or review, not archived), "Withdraw" (in force or
 * upcoming) and "Approve" (in review, for its reviewer only).
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var dmstr\knowledgeLibrary\models\Version[] $versions versions without drafts, highest number first
 * @var dmstr\knowledgeLibrary\models\Version|null $draft draft of the item
 * @var bool $isReviewer whether the current user reviews the version in review
 * @var bool $canCorrectRoute whether the current user may use version/correct
 * @var bool $canWithdrawRoute whether the current user may use version/withdraw
 */

use dmstr\knowledgeLibrary\controllers\VersionController;
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

$hasVersionInReview = false;
foreach ($versions as $version) {
    $hasVersionInReview = $hasVersionInReview || $version->status === Version::STATUS_IN_REVIEW;
}
// Corrections need a free draft slot; the model checks again on submit.
$mayCorrect = $canCorrectRoute && $draft === null && !$hasVersionInReview && !$model->is_archived;
$actionButton = 'btn btn-xs ';
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
            $range = VersionController::periodText($from, $until);
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
                    <?php if ($mayCorrect && $version->canCorrect()): ?>
                        <?= Html::a(
                            Html::encode(Yii::t('knowledge-library', 'Correct')),
                            ['version/correct', 'id' => $version->id],
                            [
                                'class' => 'btn btn-default btn-xs knowledge-library-version-correct',
                                'data-method' => 'post',
                                'data-confirm' => VersionController::correctionConfirmation($version, $range),
                            ]
                        ) ?>
                    <?php endif ?>
                    <?php if ($canWithdrawRoute && $version->status === Version::STATUS_PUBLISHED && $version->canWithdraw()): ?>
                        <?= Html::a(
                            Html::encode(Yii::t('knowledge-library', 'Withdraw')),
                            ['version/withdraw', 'id' => $version->id],
                            [
                                'class' => $actionButton . 'knowledge-library-version-withdraw',
                                'style' => 'background: #fff; border: 1px solid #dd4b39; color: #dd4b39; font-weight: 600',
                            ]
                        ) ?>
                    <?php endif ?>
                    <?php if ($isReviewer && $version->status === Version::STATUS_IN_REVIEW): ?>
                        <?= Html::a(
                            Html::encode(Yii::t('knowledge-library', 'Approve')),
                            ['version/review', 'id' => $version->id],
                            [
                                'class' => $actionButton . 'knowledge-library-version-approve',
                                'style' => 'background: #f39c12; border: 1px solid #e08e0b; color: #fff; font-weight: 600',
                            ]
                        ) ?>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>
