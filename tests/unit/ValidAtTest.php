<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;

class ValidAtTest extends TestCase
{
    private Item $item;
    private Version $predecessor;
    private Version $successor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->item = $this->createItem();
        $this->predecessor = $this->createPublishedVersion($this->item, ['valid_from' => '2026-01-01']);
        $this->successor = $this->createPublishedVersion($this->item, ['valid_from' => '2026-03-01']);
        $this->predecessor->refresh();
    }

    public function testPredecessorIsValidOnItsLastDay(): void
    {
        $this->assertSame('2026-02-28', $this->predecessor->valid_until);
        $this->assertSame($this->predecessor->id, $this->item->getValidVersion('2026-02-28')?->id);
        $this->assertSame($this->predecessor->id, $this->item->getValidVersion('2026-01-01')?->id);
    }

    public function testSuccessorIsValidFromItsFirstDay(): void
    {
        $this->assertSame($this->successor->id, $this->item->getValidVersion('2026-03-01')?->id);
    }

    public function testNothingIsValidBeforeTheFirstVersion(): void
    {
        $this->assertNull($this->item->getValidVersion('2025-12-31'));
    }

    public function testOpenEndedVersionStaysValid(): void
    {
        $this->assertNull($this->successor->valid_until);
        $this->assertSame($this->successor->id, $this->item->getValidVersion('2099-12-31')?->id);
    }

    public function testNothingIsValidInAGap(): void
    {
        $item = $this->createItem();
        $a = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);
        $b = $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame($a->id, $item->getValidVersion('2026-01-31')?->id);
        $this->assertNull($item->getValidVersion('2026-02-15'));
        $this->assertSame($b->id, $item->getValidVersion('2026-03-01')?->id);
    }

    public function testDraftsAndVersionsInReviewAreNeverValid(): void
    {
        $item = $this->createItem();
        $this->createVersion($item, ['valid_from' => '2026-01-01'])->submitForReview('user-2');
        $this->createVersion($item, ['valid_from' => '2026-02-01']);

        $this->assertNull($item->getValidVersion('2026-06-01'));
    }

    public function testEffectiveStates(): void
    {
        $predecessor = Version::findOne($this->predecessor->id);
        $successor = Version::findOne($this->successor->id);

        $this->assertSame(Version::STATE_IN_FORCE, $predecessor->getEffectiveState('2026-02-28'));
        $this->assertSame(Version::STATE_UPCOMING, $successor->getEffectiveState('2026-02-28'));
        $this->assertSame(Version::STATE_HISTORICAL, $predecessor->getEffectiveState('2026-03-01'));
        $this->assertSame(Version::STATE_IN_FORCE, $successor->getEffectiveState('2026-03-01'));
        $this->assertSame(Version::STATE_UPCOMING, $predecessor->getEffectiveState('2025-12-31'));
    }

    public function testEffectiveStateDefaultsToToday(): void
    {
        $this->assertSame(
            Version::findOne($this->successor->id)->getEffectiveState(date('Y-m-d')),
            Version::findOne($this->successor->id)->getEffectiveState()
        );
        $this->assertSame(
            $this->item->getValidVersion(date('Y-m-d'))?->id,
            $this->item->getValidVersion()?->id
        );
    }

    public function testEffectiveStateOfDraftIsNull(): void
    {
        $draft = $this->createVersion($this->item, ['valid_from' => '2026-06-01']);

        $this->assertNull($draft->getEffectiveState('2026-06-01'));
    }

    public function testTypeWithoutValidityPeriodReturnsLatestPublishedRegardlessOfDate(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $this->createPublishedVersion($item);
        $latest = $this->createPublishedVersion($item);
        $this->createVersion($item);

        foreach (['1990-01-01', '2026-06-01', '2099-12-31'] as $date) {
            $this->assertSame($latest->id, $item->getValidVersion($date)?->id, $date);
        }
    }

    public function testAtMostOneVersionPerItem(): void
    {
        $noValidity = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $this->createPublishedVersion($noValidity);
        $noValidityLatest = $this->createPublishedVersion($noValidity);

        $other = $this->createItem();
        $otherVersion = $this->createPublishedVersion($other, ['valid_from' => '2026-02-01']);

        $valid = Version::find()->validAt('2026-02-15')->all();
        $this->assertEqualsCanonicalizing(
            [$this->predecessor->id, $noValidityLatest->id, $otherVersion->id],
            array_column($valid, 'id')
        );

        $valid = Version::find()->validAt('2026-03-15')->all();
        $this->assertEqualsCanonicalizing(
            [$this->successor->id, $noValidityLatest->id, $otherVersion->id],
            array_column($valid, 'id')
        );
    }

    public function testOverlappingVersionsYieldTheHighestNumber(): void
    {
        // Overlaps cannot be created by publish(), e.g. by a correction.
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $second = $this->createPublishedVersion($item, ['valid_from' => '2026-02-01']);
        $first->updateAttributes(['valid_until' => null]);

        $valid = Version::find()->validAt('2026-03-01')->forItem($item->id)->all();
        $this->assertSame([$second->id], array_column($valid, 'id'));
        $this->assertSame($first->id, $item->getValidVersion('2026-01-15')?->id);
    }
}
