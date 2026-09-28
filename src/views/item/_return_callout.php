<?php

/**
 * Callout of a draft returned by its reviewer: who returned it when, and the
 * note. Shown on the detail page and in the wizard while `return_note` is set.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model the returned draft
 * @var string $returnedByName name of the reviewer who returned the draft
 */

use yii\helpers\Html;

$returnedAt = $model->returned_at === null || $model->returned_at === ''
    ? '–'
    : Yii::$app->formatter->asDate($model->returned_at);
?>
<div class="knowledge-library-return-callout" style="display: flex; gap: 12px; align-items: flex-start; background: #fdf1ef; border: 1px solid #f0b8b0; border-left: 5px solid #dd4b39; color: #a8281a; border-radius: 3px; padding: 11px 15px; margin: 12px 0 14px">
    <i class="fa fa-undo" style="margin-top: 3px"></i>
    <div>
        <div class="knowledge-library-return-by"><?= Html::encode(Yii::t('knowledge-library', 'Returned by {name} on {date}', [
            'name' => $returnedByName,
            'date' => $returnedAt,
        ])) ?></div>
        <div class="knowledge-library-return-note" style="font-weight: 400; color: #333; margin-top: 2px"><?= nl2br(Html::encode($model->return_note)) ?></div>
    </div>
</div>
