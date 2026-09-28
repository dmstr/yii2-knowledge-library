<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;

class PublishTest extends TestCase
{
    public function testPublishFromDraftIsRejectedWhenReviewIsRequired(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => true])->id]);
        $version = $this->createVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertFalse($version->publish());
        $this->assertTrue($version->hasErrors('status'));
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertNull($version->published_at);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($version->id)->status);
    }

    public function testPublishFromDraftIsAllowedWithoutRequiredReview(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $version = $this->createVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertTrue($version->publish(), json_encode($version->getErrors()));
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
    }

    public function testPublishedVersionCannotBePublishedAgain(): void
    {
        $version = $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']);

        $this->assertFalse($version->publish());
        $this->assertTrue($version->hasErrors('status'));
    }

    public function testPublishValidatesTheVersion(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $version = $this->createVersion($item, ['valid_from' => '2026-01-01', 'content' => '']);

        $this->assertFalse($version->publish());
        $this->assertTrue($version->hasErrors('content'));
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertNull($version->published_by);
    }

    public function testPublishSetsUserReferencesAndTimestamps(): void
    {
        $version = $this->createVersion($this->createItem(), ['valid_from' => '2026-01-01']);

        DummyUserProvider::$currentReference = 'author';
        $this->assertTrue($version->submitForReview('reviewer'));
        DummyUserProvider::$currentReference = 'reviewer';
        $this->assertTrue($version->publish());

        $version = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $version->status);
        $this->assertSame('reviewer', $version->reviewer_id);
        $this->assertSame('author', $version->review_requested_by);
        $this->assertSame('reviewer', $version->published_by);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $version->review_requested_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $version->published_at);
    }

    public function testPublishEndsPredecessorTheDayBefore(): void
    {
        $item = $this->createItem();
        $predecessor = $this->createPublishedVersion($item, [
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);

        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame('2026-02-28', Version::findOne($predecessor->id)->valid_until);
    }

    public function testPublishEndsOpenEndedPredecessor(): void
    {
        $item = $this->createItem();
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertNull(Version::findOne($predecessor->id)->valid_until);

        $this->createPublishedVersion($item, ['valid_from' => '2026-01-02']);

        $this->assertSame('2026-01-01', Version::findOne($predecessor->id)->valid_until);
    }

    public function testPredecessorEndingEarlierStaysUnchanged(): void
    {
        $item = $this->createItem();
        $predecessor = $this->createPublishedVersion($item, [
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-01-31',
        ]);
        $before = Version::findOne($predecessor->id)->getAttributes();

        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame($before, Version::findOne($predecessor->id)->getAttributes());
    }

    public function testPredecessorEndingTheDayBeforeStaysUnchanged(): void
    {
        $item = $this->createItem();
        $predecessor = $this->createPublishedVersion($item, [
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-02-28',
        ]);

        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame('2026-02-28', Version::findOne($predecessor->id)->valid_until);
    }

    public function testFailedPublishLeavesPredecessorUnchanged(): void
    {
        $item = $this->createItem();
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $version = $this->createVersion($item, ['valid_from' => '2026-03-01']);
        $this->assertTrue($version->submitForReview('user-2'));

        // Invalid meanwhile, e.g. changed without saving.
        $version->content = '';
        $this->assertFalse($version->publish());

        $this->assertNull(Version::findOne($predecessor->id)->valid_until);
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testTypeWithoutValidityPeriodNewestPublishedWins(): void
    {
        $type = $this->createType(['has_validity_period' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $first = $this->createPublishedVersion($item);
        $before = Version::findOne($first->id)->getAttributes();

        $second = $this->createPublishedVersion($item);

        $this->assertSame($before, Version::findOne($first->id)->getAttributes());
        $this->assertSame($second->id, Item::findOne($item->id)->getLatestPublishedVersion()->id);
        $this->assertSame(Version::STATE_HISTORICAL, Version::findOne($first->id)->getEffectiveState('2026-06-01'));
        $this->assertSame(Version::STATE_IN_FORCE, Version::findOne($second->id)->getEffectiveState('2026-06-01'));
    }
}
