<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

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
    public function testPublishAppliesDraftDetailsToItemAndClearsThem(): void
    {
        $oldTopic = $this->createTopic();
        $newTopic = $this->createTopic();
        $item = $this->createItem([
            'type_id' => $this->createType(['requires_review' => false])->id,
            'title' => 'Old title',
            'summary' => 'Old summary',
            'topicIds' => [$oldTopic->id],
        ]);
        $draft = Version::createDraft(Item::findOne($item->id), '2026-09-28');
        $draft->scenario = Version::SCENARIO_DETAILS;
        $draft->load([
            'draft_title' => 'New title',
            'draft_summary' => 'New summary',
            'draftTopicIds' => [$newTopic->id],
        ], '');
        $this->assertTrue($draft->save(), json_encode($draft->getErrors()));
        $draft->content = 'Text';

        $this->assertTrue($draft->publish(), json_encode($draft->getErrors()));

        $item = Item::findOne($item->id);
        $this->assertSame('New title', $item->title);
        $this->assertSame('New summary', $item->summary);
        $this->assertSame([$newTopic->id], $item->topicIds);
        $this->assertSame('New title', $draft->item->title);
        $this->assertSame(Version::SCENARIO_DETAILS, $draft->scenario);

        $version = Version::findOne($draft->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $version->status);
        $this->assertNull($version->draft_title);
        $this->assertNull($version->draft_summary);
        $this->assertNull($version->draft_topic_ids);
    }

    public function testPublishWithoutDraftDetailsKeepsTheItem(): void
    {
        $topic = $this->createTopic();
        $item = $this->createItem(['title' => 'Title', 'summary' => 'Summary', 'topicIds' => [$topic->id]]);

        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);

        $item = Item::findOne($item->id);
        $this->assertSame('Title', $item->title);
        $this->assertSame('Summary', $item->summary);
        $this->assertSame([$topic->id], $item->topicIds);
    }

    public function testPublishKeepsTopicsIfDraftHasNoTopicList(): void
    {
        $topic = $this->createTopic();
        $item = $this->createItem(['title' => 'Title', 'topicIds' => [$topic->id]]);

        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'draft_title' => 'Renamed']);

        $item = Item::findOne($item->id);
        $this->assertSame('Renamed', $item->title);
        $this->assertNull($item->summary);
        $this->assertSame([$topic->id], $item->topicIds);
    }

    public function testFailedPublishKeepsDraftDetailsAndItem(): void
    {
        $item = $this->createItem([
            'type_id' => $this->createType(['requires_review' => false])->id,
            'title' => 'Title',
        ]);
        $draft = $this->createVersion($item, [
            'valid_from' => '2026-01-01',
            'content' => '',
            'draft_title' => 'New title',
        ]);

        $this->assertFalse($draft->publish());

        $this->assertSame('Title', Item::findOne($item->id)->title);
        $this->assertSame('New title', $draft->draft_title);
        $this->assertSame('New title', Version::findOne($draft->id)->draft_title);
    }

    public function testPublishValidatesAllRulesWhateverTheScenario(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $draft = $this->createVersion($item, ['valid_from' => '2026-01-01', 'content' => '']);
        $draft->scenario = Version::SCENARIO_DETAILS;

        $this->assertFalse($draft->publish());
        $this->assertTrue($draft->hasErrors('content'));
        $this->assertSame(Version::SCENARIO_DETAILS, $draft->scenario);
    }

    public function testRetroactiveMessageNamesVersionAndDate(): void
    {
        Yii::$app->language = 'de';
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);
        $version = $this->createVersion($item, ['valid_from' => '2026-04-01']);
        $this->assertTrue($version->submitForReview('user-2'));

        // The start moved before the predecessor after the submission.
        $version->valid_from = '2026-02-01';
        $this->assertFalse($version->publish());

        $this->assertSame(
            'Das Datum liegt vor dem Beginn von Version 1 (' . Yii::$app->formatter->asDate('2026-03-01')
                . '). Rückwirkende Änderungen gehen nur über „Korrigieren“.',
            $version->getFirstError('valid_from')
        );
    }
}
