<?php

/**
 * Tab "relations" of the detail page: outgoing relations (removable) with
 * the form for a new relation, and incoming relations with the inverse
 * label.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Item $model
 * @var dmstr\knowledgeLibrary\models\Relation[] $outgoing relations with loaded target items
 * @var dmstr\knowledgeLibrary\models\Relation[] $incoming relations with loaded source items
 * @var array<string, string> $targetOptions map `item ID => title` of possible targets
 */

use dmstr\knowledgeLibrary\models\Relation;
use yii\helpers\Html;

// The labels are lower case for use within a sentence.
$capitalize = static fn (string $label) => mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);

$typeOptions = [];
foreach (Relation::labels() as $type => $labels) {
    $typeOptions[$type] = $capitalize($labels['forward']);
}

$boxStyle = 'border: 1px solid #d2d6de; border-radius: 3px; margin-bottom: 15px';
$headStyle = 'padding: 10px; border-bottom: 1px solid #f4f4f4; background: #f9f9f9';
$rowStyle = 'display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-bottom: 1px solid #f4f4f4';
$labelStyle = 'width: 150px; color: #777';
?>
<div class="row knowledge-library-item-relations">
    <div class="col-md-6">
        <div class="knowledge-library-relations-outgoing" style="<?= $boxStyle ?>">
            <div style="<?= $headStyle ?>"><?= Html::encode(Yii::t('knowledge-library', 'Outgoing')) ?></div>
            <?php foreach ($outgoing as $relation): ?>
                <div class="knowledge-library-relation" style="<?= $rowStyle ?>">
                    <span style="<?= $labelStyle ?>"><?= Html::encode($capitalize($relation->getLabelFor($model->id))) ?></span>
                    <span style="flex: 1">
                        <?php if ($relation->targetItem !== null): ?>
                            <?= Html::a(Html::encode($relation->targetItem->title), ['view', 'id' => $relation->target_item_id]) ?>
                        <?php endif ?>
                    </span>
                    <?= Html::a('<i class="fa fa-times"></i>', ['relation/delete', 'id' => $relation->id], [
                        'class' => 'knowledge-library-relation-delete',
                        'style' => 'color: #999',
                        'title' => Yii::t('knowledge-library', 'Remove'),
                        'aria-label' => Yii::t('knowledge-library', 'Remove'),
                        'data-method' => 'post',
                    ]) ?>
                </div>
            <?php endforeach ?>
            <?php if ($outgoing === []): ?>
                <div class="text-muted" style="padding: 9px 10px"><?= Html::encode(Yii::t('knowledge-library', 'No relations.')) ?></div>
            <?php endif ?>

            <?= Html::beginForm(['relation/create', 'itemId' => $model->id], 'post', [
                'class' => 'knowledge-library-relation-form',
                'style' => 'display: flex; gap: 6px; padding: 10px',
            ]) ?>
                <?= Html::dropDownList('Relation[type]', null, $typeOptions, [
                    'class' => 'form-control',
                    'id' => 'knowledge-library-relation-type',
                    'style' => 'width: auto',
                ]) ?>
                <?= Html::dropDownList('Relation[target_item_id]', null, $targetOptions, [
                    'class' => 'form-control',
                    'id' => 'knowledge-library-relation-target',
                    'prompt' => Yii::t('knowledge-library', 'Select knowledge object'),
                    'style' => 'flex: 1; min-width: 0',
                ]) ?>
                <?= Html::submitButton(Html::encode(Yii::t('knowledge-library', 'Add')), ['class' => 'btn btn-default']) ?>
            <?= Html::endForm() ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="knowledge-library-relations-incoming" style="<?= $boxStyle ?>">
            <div style="<?= $headStyle ?>"><?= Html::encode(Yii::t('knowledge-library', 'Incoming')) ?></div>
            <?php foreach ($incoming as $relation): ?>
                <div class="knowledge-library-relation" style="<?= $rowStyle ?>">
                    <span style="<?= $labelStyle ?>"><?= Html::encode($capitalize($relation->getLabelFor($model->id))) ?></span>
                    <span style="flex: 1">
                        <?php if ($relation->sourceItem !== null): ?>
                            <?= Html::a(Html::encode($relation->sourceItem->title), ['view', 'id' => $relation->source_item_id]) ?>
                        <?php endif ?>
                    </span>
                </div>
            <?php endforeach ?>
            <?php if ($incoming === []): ?>
                <div class="text-muted" style="padding: 9px 10px"><?= Html::encode(Yii::t('knowledge-library', 'None.')) ?></div>
            <?php endif ?>
        </div>
    </div>
</div>
