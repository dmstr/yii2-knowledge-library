<?php

/**
 * Step indicator of the version wizard: a circle per step, done steps with
 * a check mark; reachable steps link to their page.
 *
 * @var yii\web\View $this
 * @var dmstr\knowledgeLibrary\models\Version $model
 * @var int $step current step
 * @var array<int, string> $steps labels by step
 * @var int $reachable last step that can be opened
 */

use yii\helpers\Html;

$green = '#00a65a';
$grey = '#e3e6ea';
$last = max(array_keys($steps));
?>
<div class="knowledge-library-wizard-steps" style="display: flex; padding: 18px 15px 16px; border-bottom: 1px solid #f4f4f4">
    <?php foreach ($steps as $number => $label): ?>
        <?php
        $done = $number < $step;
        $current = $number === $step;
        $circle = Html::tag(
            'span',
            $done ? '<i class="fa fa-check"></i>' : Html::encode((string)$number),
            ['style' => [
                'position' => 'relative',
                'z-index' => '1',
                'width' => '32px',
                'height' => '32px',
                'border-radius' => '50%',
                'box-sizing' => 'border-box',
                'display' => 'flex',
                'align-items' => 'center',
                'justify-content' => 'center',
                'font-weight' => '700',
                'background' => $done ? $green : '#fff',
                'color' => $done ? '#fff' : ($current ? $green : '#999'),
                'border' => '2px solid ' . ($done || $current ? $green : '#d2d6de'),
            ]]
        );
        $lines = Html::tag('span', '', ['style' => [
            'position' => 'absolute', 'top' => '15px', 'left' => '0', 'width' => '50%', 'height' => '2px',
            'background' => $number === 1 ? 'transparent' : ($number <= $step ? $green : $grey),
        ]]) . Html::tag('span', '', ['style' => [
            'position' => 'absolute', 'top' => '15px', 'right' => '0', 'width' => '50%', 'height' => '2px',
            'background' => $number === $last ? 'transparent' : ($number < $step ? $green : $grey),
        ]]);
        $text = Html::tag('span', Html::encode($label), [
            'style' => ['font-size' => '13px', 'color' => $current ? '#333' : ($done ? '#555' : '#999')],
        ]);
        $options = [
            'class' => 'knowledge-library-wizard-step' . ($current ? ' active' : '') . ($done ? ' done' : ''),
            'data-step' => $number,
            'style' => [
                'flex' => '1',
                'display' => 'flex',
                'flex-direction' => 'column',
                'align-items' => 'center',
                'gap' => '6px',
                'position' => 'relative',
                'text-decoration' => 'none',
                'cursor' => $number <= $reachable && !$current ? 'pointer' : 'default',
            ],
        ];
        ?>
        <?php if ($number <= $reachable && !$current): ?>
            <?= Html::a($lines . $circle . $text, ['update', 'id' => $model->id, 'step' => $number], $options) ?>
        <?php else: ?>
            <?= Html::tag('div', $lines . $circle . $text, $options) ?>
        <?php endif ?>
    <?php endforeach ?>
</div>
