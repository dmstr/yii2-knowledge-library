<?php

namespace dmstr\knowledgeLibrary\widgets;

use DateTimeImmutable;
use DateTimeZone;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ValidityCheck;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Timeline of the validity periods of the versions of an item, rendered as
 * plain HTML and CSS (bars with percentage offsets and widths on a year
 * axis), without JavaScript.
 *
 * Either pass the item (all published and withdrawn versions, see
 * ValidityCheck::timeline()) or explicit timeline data, e.g. the preview of
 * a ValidityCheck:
 *
 * ```php
 * echo ValidityTimeline::widget(['item' => $item]);
 * echo ValidityTimeline::widget(['data' => $check->getPreview()]);
 * ```
 *
 * Renders an empty string if there are no versions.
 */
class ValidityTimeline extends Widget
{
    /**
     * Colors by state: background, text.
     */
    public const COLORS = [
        ValidityCheck::STATE_IN_FORCE => ['#00a65a', '#fff'],
        ValidityCheck::STATE_HISTORICAL => ['#d2d6de', '#444'],
        ValidityCheck::STATE_UPCOMING => ['#00c0ef', '#fff'],
        ValidityCheck::STATE_WITHDRAWN => ['#dd4b39', '#fff'],
        ValidityCheck::STATE_NEW => ['#f39c12', '#fff'],
    ];

    /**
     * Color of the outline of versions whose end changes (`hl`).
     */
    public const HIGHLIGHT_COLOR = '#f39c12';

    public ?Item $item = null;

    /**
     * Timeline data `{y0, y1, v: [{n, a, b, s, hl, lane}]}`, see
     * ValidityCheck::timeline(). Takes precedence over $item.
     */
    public ?array $data = null;

    /**
     * Date of the "today" marker and of the states when built from $item
     * (`Y-m-d`), today if null.
     */
    public ?string $today = null;

    /**
     * HTML attributes of the container.
     */
    public array $options = [];

    public function run(): string
    {
        $data = $this->data;
        if ($data === null) {
            if ($this->item === null) {
                throw new InvalidConfigException('Either "item" or "data" must be set.');
            }
            $data = ValidityCheck::timeline($this->item, $this->today);
        }

        $bars = array_values(array_filter(
            $data['v'] ?? [],
            static fn ($bar) => is_array($bar) && ValidityCheck::isDate($bar['a'] ?? null)
        ));
        if ($bars === []) {
            return '';
        }

        $firstYear = (int)($data['y0'] ?? ValidityCheck::MIN_FIRST_YEAR);
        $lastYear = max($firstYear, (int)($data['y1'] ?? ValidityCheck::MIN_LAST_YEAR));
        $start = $this->timestamp($firstYear . '-01-01');
        $end = $this->timestamp(($lastYear + 1) . '-01-01');

        $options = $this->options;
        Html::addCssClass($options, 'kl-validity-timeline');
        Html::addCssStyle($options, ['position' => 'relative', 'margin' => '10px 0', 'font-size' => '12px']);
        $options['id'] ??= $this->getId();

        $lanes = [];
        foreach ($bars as $bar) {
            $lanes[(int)($bar['lane'] ?? 0)][] = $bar;
        }
        ksort($lanes);

        $laneRows = [];
        foreach ($lanes as $laneBars) {
            $laneRows[] = $this->renderLane($laneBars, $start, $end);
        }
        $laneRows[] = $this->renderToday($start, $end);

        $rows = [
            $this->renderAxis($firstYear, $lastYear),
            Html::tag('div', implode("\n", $laneRows), [
                'class' => 'kl-validity-timeline-lanes',
                'style' => ['position' => 'relative'],
            ]),
            $this->renderLegend($bars),
        ];

        return Html::tag('div', implode("\n", $rows), $options);
    }

    private function renderAxis(int $firstYear, int $lastYear): string
    {
        $count = $lastYear - $firstYear + 1;
        $cells = [];
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $cells[] = Html::tag('div', Html::encode((string)$year), [
                'class' => 'kl-validity-timeline-year',
                'style' => [
                    'float' => 'left',
                    'box-sizing' => 'border-box',
                    'width' => $this->percent(100 / $count),
                    'padding-left' => '3px',
                    'border-left' => '1px solid #d2d6de',
                    'color' => '#777',
                ],
            ]);
        }

        return Html::tag('div', implode('', $cells), [
            'class' => 'kl-validity-timeline-axis',
            'style' => ['overflow' => 'hidden', 'margin-bottom' => '4px'],
        ]);
    }

    private function renderLane(array $bars, int $start, int $end): string
    {
        $items = [];
        foreach ($bars as $bar) {
            $from = max($start, $this->timestamp($bar['a']));
            $until = $bar['b'] !== null && ValidityCheck::isDate($bar['b'])
                ? min($end, $this->timestamp($bar['b']) + 86400)
                : $end;
            if ($until <= $from) {
                continue;
            }

            $state = isset(self::COLORS[$bar['s'] ?? null]) ? $bar['s'] : ValidityCheck::STATE_HISTORICAL;
            [$background, $color] = self::COLORS[$state];
            $label = Yii::t('knowledge-library', 'Version {number}', ['number' => (int)$bar['n']]);

            $style = [
                'position' => 'absolute',
                'top' => '2px',
                'bottom' => '2px',
                'left' => $this->percent(($from - $start) / ($end - $start) * 100),
                'width' => $this->percent(max(0.5, ($until - $from) / ($end - $start) * 100)),
                'box-sizing' => 'border-box',
                'overflow' => 'hidden',
                'white-space' => 'nowrap',
                'padding' => '0 4px',
                'line-height' => '20px',
                'border-radius' => '3px',
                'border-right' => '1px solid #fff',
                'background' => $background,
                'color' => $color,
            ];
            if ($state === ValidityCheck::STATE_WITHDRAWN) {
                $style['text-decoration'] = 'line-through';
            }
            if ($state === ValidityCheck::STATE_NEW) {
                $style['font-weight'] = 'bold';
            }
            if (!empty($bar['hl'])) {
                $style['outline'] = '2px dashed ' . self::HIGHLIGHT_COLOR;
                $style['outline-offset'] = '1px';
            }

            $classes = ['kl-validity-timeline-bar', 'kl-validity-timeline-' . $state];
            if (!empty($bar['hl'])) {
                $classes[] = 'kl-validity-timeline-changed';
            }

            $items[] = Html::tag('div', Html::encode($label), [
                'class' => $classes,
                'title' => $label . ': ' . $this->range($bar) . ' (' . $this->stateLabel($state) . ')',
                'data-number' => (int)$bar['n'],
                'data-state' => $state,
                'style' => $style,
            ]);
        }

        return Html::tag('div', implode('', $items), [
            'class' => 'kl-validity-timeline-lane',
            'style' => ['position' => 'relative', 'height' => '24px', 'background' => '#f9f9f9'],
        ]);
    }

    private function renderToday(int $start, int $end): string
    {
        $today = $this->timestamp($this->today ?? date('Y-m-d'));
        if ($today < $start || $today >= $end) {
            return '';
        }

        return Html::tag('div', '', [
            'class' => 'kl-validity-timeline-today',
            'title' => Yii::t('knowledge-library', 'Today'),
            'style' => [
                'position' => 'absolute',
                'top' => '0',
                'bottom' => '0',
                'left' => $this->percent(($today - $start) / ($end - $start) * 100),
                'border-left' => '2px solid #333',
            ],
        ]);
    }

    private function renderLegend(array $bars): string
    {
        $states = array_unique(array_map(
            static fn (array $bar) => isset(self::COLORS[$bar['s'] ?? null]) ? $bar['s'] : ValidityCheck::STATE_HISTORICAL,
            $bars
        ));
        $entries = [];
        foreach (array_keys(self::COLORS) as $state) {
            if (!in_array($state, $states, true)) {
                continue;
            }
            $swatch = Html::tag('span', '', ['style' => [
                'display' => 'inline-block',
                'width' => '10px',
                'height' => '10px',
                'margin-right' => '4px',
                'border-radius' => '2px',
                'background' => self::COLORS[$state][0],
            ]]);
            $entries[] = Html::tag('span', $swatch . Html::encode($this->stateLabel($state)), [
                'style' => ['margin-right' => '12px'],
            ]);
        }

        return Html::tag('div', implode('', $entries), [
            'class' => 'kl-validity-timeline-legend',
            'style' => ['margin-top' => '6px', 'color' => '#555'],
        ]);
    }

    private function stateLabel(string $state): string
    {
        switch ($state) {
            case ValidityCheck::STATE_IN_FORCE:
                return Yii::t('knowledge-library', 'In Force');
            case ValidityCheck::STATE_UPCOMING:
                return Yii::t('knowledge-library', 'Upcoming');
            case ValidityCheck::STATE_WITHDRAWN:
                return Yii::t('knowledge-library', 'Withdrawn');
            case ValidityCheck::STATE_NEW:
                return Yii::t('knowledge-library', 'New version');
            default:
                return Yii::t('knowledge-library', 'Historical');
        }
    }

    private function range(array $bar): string
    {
        $formatter = Yii::$app->formatter;
        $until = $bar['b'] !== null && ValidityCheck::isDate($bar['b'])
            ? $formatter->asDate($bar['b'])
            : Yii::t('knowledge-library', 'open-ended');

        return $formatter->asDate($bar['a']) . ' – ' . $until;
    }

    private function timestamp(string $date): int
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') . '%';
    }
}
