<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;

/**
 * Tests of ItemQuery::validAt(), alone and combined with active().
 */
class ItemQueryValidAtTest extends TestCase
{
    private const DATE = '2026-06-01';

    public function testDraftIsNotValid(): void
    {
        $item = $this->createItem();
        $this->createVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertNotValid($item, self::DATE);
    }

    public function testVersionInReviewIsNotValid(): void
    {
        $item = $this->createItem();
        $this->assertTrue($this->createVersion($item, ['valid_from' => '2026-01-01'])->submitForReview('user-2'));

        $this->assertNotValid($item, self::DATE);
    }

    public function testVersionInForceIsValid(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertValid($item, self::DATE);
        $this->assertValid($item, '2026-01-01');
        $this->assertNotValid($item, '2025-12-31');
    }

    public function testItemWithOnlyHistoricalVersionIsNotValid(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-03-31']);

        $this->assertValid($item, '2026-03-31');
        $this->assertNotValid($item, self::DATE);
    }

    public function testItemWithHistoricalAndCurrentVersionIsValidOnce(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2026-05-01']);

        $this->assertSame([$item->id], $this->validIds(self::DATE));
    }

    public function testItemWithOnlyUpcomingVersionIsNotValid(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-09-01']);

        $this->assertNotValid($item, self::DATE);
        $this->assertValid($item, '2026-09-01');
    }

    public function testWithdrawnVersionIsNotValid(): void
    {
        $item = $this->createItem();
        $version = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $this->assertValid($item, $this->day(0));

        $this->assertTrue($version->withdraw('Reason', Version::WITHDRAW_NONE), json_encode($version->getErrors()));

        $this->assertNotValid($item, $this->day(0));
    }

    public function testWithdrawnVersionWithPreviousKeepsTheItemValid(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);

        $this->assertTrue($second->withdraw('Reason', Version::WITHDRAW_PREVIOUS), json_encode($second->getErrors()));

        $this->assertValid($item, $this->day(0));
    }

    public function testArchivedItemIsExcludedByActiveOnly(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue($item->archive(), json_encode($item->getErrors()));

        $this->assertValid($item, self::DATE);
        $this->assertNotContains($item->id, $this->validIds(self::DATE, true));

        $this->assertTrue(Item::findOne($item->id)->restore());
        $this->assertContains($item->id, $this->validIds(self::DATE, true));
    }

    public function testTypeWithoutValidityPeriodIsValidRegardlessOfDate(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $this->createPublishedVersion($item);

        foreach (['1990-01-01', self::DATE, '2099-12-31'] as $date) {
            $this->assertValid($item, $date);
        }
    }

    public function testTypeWithoutValidityPeriodWithOnlyDraftIsNotValid(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $this->createVersion($item);

        $this->assertNotValid($item, self::DATE);
    }

    public function testNothingIsValidInAGap(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertValid($item, '2026-01-31');
        $this->assertNotValid($item, '2026-02-15');
        $this->assertValid($item, '2026-03-01');
    }

    public function testCombinesWithFiltersEagerLoadingAndOrder(): void
    {
        $type = $this->createType();
        $topic = $this->createTopic();
        $bravo = $this->createItem(['title' => 'Bravo', 'type_id' => $type->id, 'topicIds' => [$topic->id]]);
        $alpha = $this->createItem(['title' => 'Alpha', 'type_id' => $type->id]);
        $draft = $this->createItem(['title' => 'Aardvark', 'type_id' => $type->id]);
        $this->createPublishedVersion($bravo, ['valid_from' => '2026-01-01']);
        $this->createPublishedVersion($alpha, ['valid_from' => '2026-01-01']);
        $this->createVersion($draft, ['valid_from' => '2026-01-01']);

        $items = Item::find()
            ->active()
            ->validAt(self::DATE)
            ->with(['type', 'topics'])
            ->orderBy(['title' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $this->assertSame(['Alpha', 'Bravo'], array_column($items, 'title'));
        $this->assertTrue($items[1]->isRelationPopulated('topics'));
        $this->assertSame([$topic->id], array_column($items[1]->topics, 'id'));
        $this->assertSame($type->id, $items[0]->type->id);
    }

    public function testWorksWithAnAlias(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);

        $ids = Item::find()->alias('i')->validAt(self::DATE)->select('i.id')->column();

        $this->assertSame([$item->id], $ids);
    }

    private function assertValid(Item $item, string $date): void
    {
        $this->assertContains($item->id, $this->validIds($date), "Item is not valid at $date.");
    }

    private function assertNotValid(Item $item, string $date): void
    {
        $this->assertNotContains($item->id, $this->validIds($date), "Item is valid at $date.");
    }

    /**
     * @return string[]
     */
    private function validIds(string $date, bool $activeOnly = false): array
    {
        $query = Item::find()->validAt($date);
        if ($activeOnly) {
            $query->active();
        }

        return $query->select(Item::tableName() . '.[[id]]')->column();
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }
}
