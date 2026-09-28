<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ItemState;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class ItemStateTest extends TestCase
{
    private const DATE = '2026-06-01';

    private function stateOf(Item $item, string $date = self::DATE): ItemState
    {
        $states = ItemState::forItems([Item::findOne($item->id)], $date);
        $this->assertArrayHasKey($item->id, $states);

        return $states[$item->id];
    }

    public function testArchivedWinsOverValidVersion(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $item->updateAttributes(['is_archived' => true]);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::ARCHIVED, $state->state);
        $this->assertSame('Archived', $state->label);
        $this->assertNull($state->sinceLabel);
    }

    public function testArchivedWithoutVersions(): void
    {
        $item = $this->createItem(['is_archived' => true]);

        $this->assertSame(ItemState::ARCHIVED, $this->stateOf($item)->state);
    }

    public function testInReviewWinsOverDraft(): void
    {
        $item = $this->createItem();
        $this->createVersion($item, ['valid_from' => '2026-01-01'])->submitForReview('user-2');
        $this->createVersion($item, ['valid_from' => '2026-02-01']);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::IN_REVIEW, $state->state);
        $this->assertSame('In Review', $state->label);
        $this->assertNull($state->version);
        $this->assertNull($state->number);
        $this->assertNull($state->since);
    }

    public function testOnlyDraft(): void
    {
        $item = $this->createItem();
        $this->createVersion($item);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::DRAFT, $state->state);
        $this->assertSame('Draft', $state->label);
    }

    public function testNoVersionsIsDraft(): void
    {
        $this->assertSame(ItemState::DRAFT, $this->stateOf($this->createItem())->state);
    }

    public function testOnlyExpiredVersionIsNone(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::NONE, $state->state);
        $this->assertSame('No valid version', $state->label);
        $this->assertNull($state->sinceLabel);
    }

    public function testOnlyUpcomingVersionIsNone(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-12-01']);

        $this->assertSame(ItemState::NONE, $this->stateOf($item)->state);
    }

    public function testWithdrawnVersionIsNone(): void
    {
        $item = $this->createItem();
        $version = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $version->updateAttributes(['status' => Version::STATUS_WITHDRAWN]);

        $this->assertSame(ItemState::NONE, $this->stateOf($item)->state);
    }

    public function testValidVersionSinceValidFrom(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $valid = $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);
        // A newer draft does not hide the valid version.
        $this->createVersion($item, ['valid_from' => '2026-09-01']);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::VALID, $state->state);
        $this->assertSame($valid->id, $state->version->id);
        $this->assertSame(2, $state->number);
        $this->assertSame('2026-03-01', $state->since);
        $this->assertSame('Version 2', $state->label);
        $this->assertSame('since ' . Yii::$app->formatter->asDate('2026-03-01'), $state->sinceLabel);
    }

    public function testTypeWithoutValidityPeriodSincePublishedAt(): void
    {
        $type = $this->createType(['has_validity_period' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $version = $this->createPublishedVersion($item);
        $version->updateAttributes(['published_at' => '2026-04-15 13:45:00']);

        $state = $this->stateOf($item);
        $this->assertSame(ItemState::VALID, $state->state);
        $this->assertNull($state->version->valid_from);
        $this->assertSame(1, $state->number);
        $this->assertSame('2026-04-15', $state->since);
        $this->assertSame('since ' . Yii::$app->formatter->asDate('2026-04-15'), $state->sinceLabel);
    }

    public function testDateDefaultsToToday(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => date('Y-m-d')]);

        $states = ItemState::forItems([$item]);
        $this->assertSame(ItemState::VALID, $states[$item->id]->state);
    }

    public function testEmptyInputRunsNoQuery(): void
    {
        $this->assertSame(0, $this->countQueries(fn () => $this->assertSame([], ItemState::forItems([]))));
    }

    public function testQueryCountDoesNotDependOnItemCount(): void
    {
        $type = $this->createType();
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $item = $this->createItem(['type_id' => $type->id]);
            if ($i % 3 === 0) {
                $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
            } elseif ($i % 3 === 1) {
                $this->createVersion($item);
            }
            $items[] = $item;
        }
        $published = $items[0]->id;
        $draft = $items[1]->id;
        $empty = $items[2]->id;
        $items = Item::find()->where(['id' => array_map(static fn (Item $item) => $item->id, $items)])->all();

        $states = [];
        $many = $this->countQueries(function () use ($items, &$states) {
            $states = ItemState::forItems($items, self::DATE);
        });
        $one = $this->countQueries(fn () => ItemState::forItems([$items[0]], self::DATE));

        $this->assertCount(25, $states);
        $this->assertSame(2, $many);
        $this->assertSame($one, $many);
        $this->assertSame(ItemState::VALID, $states[$published]->state);
        $this->assertSame(ItemState::DRAFT, $states[$draft]->state);
        $this->assertSame(ItemState::DRAFT, $states[$empty]->state);
    }

    /**
     * Number of SQL queries executed by the callback.
     */
    private function countQueries(callable $callback): int
    {
        $logger = Yii::getLogger();
        $logger->flushInterval = PHP_INT_MAX;
        $logger->messages = [];

        $callback();

        return count($logger->getProfiling(['yii\db\Command::query', 'yii\db\Command::execute']));
    }
}
