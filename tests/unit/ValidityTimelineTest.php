<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ValidityCheck;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;
use dmstr\knowledgeLibrary\widgets\ValidityTimeline;
use yii\base\InvalidConfigException;

class ValidityTimelineTest extends TestCase
{
    private const TODAY = '2026-09-28';

    public function testRendersBarsWithPositionsAndColors(): void
    {
        $html = ValidityTimeline::widget([
            'today' => self::TODAY,
            'data' => [
                'y0' => 2024,
                'y1' => 2027,
                'v' => [
                    ['n' => 1, 'a' => '2024-01-01', 'b' => '2025-12-31', 's' => 'hist', 'hl' => false, 'lane' => 0],
                    ['n' => 2, 'a' => '2026-01-01', 'b' => null, 's' => 'kraft', 'hl' => true, 'lane' => 0],
                    ['n' => 3, 'a' => '2027-01-01', 'b' => null, 's' => 'neu', 'hl' => false, 'lane' => 0],
                    ['n' => 4, 'a' => '2025-01-01', 'b' => '2025-06-30', 's' => 'zur', 'hl' => false, 'lane' => 1],
                ],
            ],
        ]);

        foreach (['2024', '2025', '2026', '2027'] as $year) {
            $this->assertStringContainsString(">$year</div>", $html);
        }
        $this->assertSame(4, substr_count($html, 'kl-validity-timeline-bar '));
        $this->assertSame(2, substr_count($html, 'class="kl-validity-timeline-lane"'));

        // Four years of 1461 days: version 1 covers 731 days from the start.
        $this->assertMatchesRegularExpression(
            '/data-number="1" data-state="hist" style="[^"]*left: 0%; width: 50\.0342%;[^"]*background: #d2d6de; color: #444;/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-number="2" data-state="kraft" style="[^"]*left: 50\.0342%; width: 49\.9658%;[^"]*background: #00a65a;[^"]*outline: 2px dashed #f39c12;/',
            $html
        );
        $this->assertMatchesRegularExpression('/data-state="neu" style="[^"]*background: #f39c12;/', $html);
        $this->assertMatchesRegularExpression('/data-state="zur" style="[^"]*background: #dd4b39;[^"]*line-through/', $html);
        $this->assertStringContainsString('kl-validity-timeline-changed', $html);
        $this->assertStringContainsString('kl-validity-timeline-today', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('New version', $html);
    }

    public function testRendersVersionsOfItem(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2027-01-01']);
        $this->assertTrue($this->createVersion($item, ['valid_from' => '2027-06-01'])->submitForReview('user-2'));
        $this->createVersion($item, ['valid_from' => '2027-07-01']);

        $html = ValidityTimeline::widget(['item' => Item::findOne($item->id), 'today' => self::TODAY]);

        $this->assertStringContainsString('data-number="1" data-state="kraft"', $html);
        $this->assertStringContainsString('data-number="2" data-state="bev"', $html);
        $this->assertStringNotContainsString('data-number="3"', $html);
        $this->assertStringNotContainsString('data-number="4"', $html);
        $this->assertStringContainsString('In Force', $html);
        $this->assertStringContainsString('Upcoming', $html);
    }

    public function testRendersPreviewOfValidityCheck(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-10-01']);

        $html = ValidityTimeline::widget([
            'data' => (new ValidityCheck($draft, self::TODAY))->getPreview(),
            'today' => self::TODAY,
        ]);

        $this->assertStringContainsString('data-number="2" data-state="neu"', $html);
        $this->assertMatchesRegularExpression('/data-number="1" data-state="kraft" style="[^"]*outline/', $html);
    }

    public function testRendersNothingWithoutVersions(): void
    {
        $this->assertSame('', ValidityTimeline::widget(['item' => $this->createItem()]));
        $this->assertSame('', ValidityTimeline::widget(['data' => ['y0' => 2024, 'y1' => 2028, 'v' => []]]));
    }

    public function testEncodesOutputAndIgnoresInvalidBars(): void
    {
        $html = ValidityTimeline::widget([
            'options' => ['data-test' => '"><script>'],
            'data' => [
                'y0' => 2024,
                'y1' => 2028,
                'v' => [
                    ['n' => '<b>1</b>', 'a' => '2025-01-01', 'b' => '<i>', 's' => '<script>alert(1)</script>', 'hl' => false, 'lane' => 0],
                    ['n' => 2, 'a' => 'invalid', 'b' => null, 's' => 'kraft', 'hl' => false, 'lane' => 0],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('data-state="hist"', $html);
        $this->assertStringNotContainsString('data-number="2"', $html);
    }

    public function testRequiresItemOrData(): void
    {
        $this->expectException(InvalidConfigException::class);

        ValidityTimeline::widget();
    }

    public function testWithdrawnVersionIsShownOnSecondLane(): void
    {
        $item = $this->createItem();
        $version = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $version->updateAttributes(['status' => Version::STATUS_WITHDRAWN]);

        $html = ValidityTimeline::widget(['item' => Item::findOne($item->id), 'today' => self::TODAY]);

        $this->assertStringContainsString('data-state="zur"', $html);
        $this->assertStringContainsString('Withdrawn', $html);
    }
}
