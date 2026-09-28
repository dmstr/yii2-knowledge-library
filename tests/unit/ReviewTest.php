<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

/**
 * Tests of the review transitions: submit, approve, return and change of the
 * reviewer, including the four-eyes principle.
 */
class ReviewTest extends TestCase
{
    public function testSubmitToOneselfIsRejected(): void
    {
        $version = $this->createDraftOfReviewItem();

        $this->assertFalse($version->submitForReview('user-1'));

        $this->assertTrue($version->hasErrors('reviewer_id'));
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertNull($version->reviewer_id);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($version->id)->status);
        $this->assertSame(0, $this->countHistory($version));
    }

    public function testSubmitToUnknownReviewerIsRejected(): void
    {
        $version = $this->createDraftOfReviewItem();

        foreach (['stranger', '', '   '] as $reviewer) {
            $this->assertFalse($version->submitForReview($reviewer), "Reviewer '$reviewer' accepted.");
            $this->assertTrue($version->hasErrors('reviewer_id'));
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($version->id)->status);
    }

    public function testSubmitWritesHistoryWithMessageAndReviewer(): void
    {
        $version = $this->createDraftOfReviewItem();

        $this->assertTrue($version->submitForReview('user-2', '  Please check  '));

        $this->assertSame('Please check', $version->review_message);
        $this->assertSame('user-1', $version->review_requested_by);
        $history = $this->findHistory($version, History::ACTION_REVIEW_REQUESTED);
        $this->assertSame('Please check', $history->reason);
        $this->assertSame('user-1', $history->actor_id);
        $this->assertSame(['number' => 1, 'reviewer' => 'user-2'], $history->getDetails());
    }

    public function testSubmitWithEmptyMessageStoresNull(): void
    {
        $version = $this->createDraftOfReviewItem();

        $this->assertTrue($version->submitForReview('user-2', ' '));

        $this->assertNull(Version::findOne($version->id)->review_message);
        $this->assertNull($this->findHistory($version, History::ACTION_REVIEW_REQUESTED)->reason);
    }

    public function testSubmitClearsAPreviousReturn(): void
    {
        $version = $this->createVersionInReview();
        $this->actAs('user-2');
        $this->assertTrue($version->returnToDraft('Fix the dates'));
        $this->actAs('user-1');

        $this->assertTrue($version->submitForReview('user-2'));

        $version = Version::findOne($version->id);
        $this->assertNull($version->return_note);
        $this->assertNull($version->returned_by);
        $this->assertNull($version->returned_at);
    }

    public function testFailedSubmitWritesNoHistory(): void
    {
        $version = $this->createDraftOfReviewItem(['valid_from' => null]);

        $this->assertFalse($version->submitForReview('user-2'));

        $this->assertTrue($version->hasErrors('valid_from'));
        $this->assertSame(0, $this->countHistory($version));
    }

    public function testOnlyTheReviewerMayPublish(): void
    {
        $version = $this->createVersionInReview();

        foreach (['user-1', 'user-3', null] as $reference) {
            $this->actAs($reference);
            $this->assertFalse($version->publish(), "Published as '$reference'.");
            $this->assertSame(
                'You are not the reviewer of this version.',
                $version->getFirstError('status')
            );
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
        $this->assertSame(1, $this->countHistory($version));
    }

    public function testPublishByReviewerWritesApprovedAndPublished(): void
    {
        $version = $this->createVersionInReview();
        $this->actAs('user-2');

        $this->assertTrue($version->publish(), json_encode($version->getErrors()));

        $approved = $this->findHistory($version, History::ACTION_APPROVED);
        $this->assertNull($approved->reason);
        $this->assertSame('user-2', $approved->actor_id);
        $published = $this->findHistory($version, History::ACTION_PUBLISHED);
        $this->assertSame(['number' => 1, 'valid_from' => '2026-01-01'], $published->getDetails());
        $this->assertSame('user-2', $published->actor_id);
    }

    public function testApprovePublishesWithNote(): void
    {
        $item = $this->createReviewItem();
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $version = $this->createVersionInReview($item, ['valid_from' => '2026-03-01']);
        $this->actAs('user-2');

        $this->assertTrue($version->approve('Looks good'), json_encode($version->getErrors()));

        $version = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $version->status);
        $this->assertSame('user-2', $version->published_by);
        $this->assertSame('2026-02-28', Version::findOne($predecessor->id)->valid_until);
        $this->assertSame('Looks good', $this->findHistory($version, History::ACTION_APPROVED)->reason);
        $this->assertNotNull($this->findHistory($version, History::ACTION_PUBLISHED));
    }

    public function testApproveRequiresVersionInReview(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $draft = $this->createVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertFalse($draft->approve());

        $this->assertTrue($draft->hasErrors('status'));
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testApproveBySubmitterOrOtherUserIsRejected(): void
    {
        $version = $this->createVersionInReview();

        foreach (['user-1', 'user-3'] as $reference) {
            $this->actAs($reference);
            $this->assertFalse($version->approve('Approved'));
            $this->assertTrue($version->hasErrors('status'));
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
        $this->assertNull($this->findHistory($version, History::ACTION_APPROVED));
    }

    public function testFailedApproveWritesNoHistory(): void
    {
        $version = $this->createVersionInReview();
        $version->content = '';
        $this->actAs('user-2');

        $this->assertFalse($version->approve('Looks good'));

        $this->assertTrue($version->hasErrors('content'));
        $this->assertSame(Version::STATUS_IN_REVIEW, $version->status);
        $this->assertNull($this->findHistory($version, History::ACTION_APPROVED));
        $this->assertNull($this->findHistory($version, History::ACTION_PUBLISHED));
    }

    public function testReturnToDraft(): void
    {
        $version = $this->createVersionInReview(null, [], 'Please check');
        $this->actAs('user-2');

        $this->assertTrue($version->returnToDraft('  Fix the dates  '), json_encode($version->getErrors()));

        $version = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertSame('Fix the dates', $version->return_note);
        $this->assertSame('user-2', $version->returned_by);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $version->returned_at);
        // Reviewer and message are kept for the next submission.
        $this->assertSame('user-2', $version->reviewer_id);
        $this->assertSame('Please check', $version->review_message);

        $history = $this->findHistory($version, History::ACTION_RETURNED);
        $this->assertSame('Fix the dates', $history->reason);
        $this->assertSame('user-2', $history->actor_id);
        $this->assertSame(['number' => 1, 'reviewer' => 'user-2'], $history->getDetails());
    }

    public function testReturnRequiresNote(): void
    {
        $version = $this->createVersionInReview();
        $this->actAs('user-2');

        foreach (['', '   ', "\n"] as $note) {
            $this->assertFalse($version->returnToDraft($note));
            $this->assertTrue($version->hasErrors('return_note'));
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
        $this->assertNull($this->findHistory($version, History::ACTION_RETURNED));
    }

    public function testOnlyTheReviewerMayReturn(): void
    {
        $version = $this->createVersionInReview();

        foreach (['user-1', 'user-3', null] as $reference) {
            $this->actAs($reference);
            $this->assertFalse($version->returnToDraft('Note'));
            $this->assertSame('You are not the reviewer of this version.', $version->getFirstError('status'));
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testReturnRequiresVersionInReview(): void
    {
        $version = $this->createDraftOfReviewItem();
        $this->actAs('user-2');

        $this->assertFalse($version->returnToDraft('Note'));

        $this->assertTrue($version->hasErrors('status'));
    }

    public function testReturnIsAllowedForArchivedItem(): void
    {
        $item = $this->createReviewItem();
        $version = $this->createVersionInReview($item);
        $this->assertTrue($item->archive());
        $this->actAs('user-2');

        $this->assertTrue($version->returnToDraft('Note'), json_encode($version->getErrors()));
    }

    public function testChangeReviewer(): void
    {
        $version = $this->createVersionInReview();

        $this->assertTrue($version->changeReviewer('user-3', 'On holiday'), json_encode($version->getErrors()));

        $version = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_IN_REVIEW, $version->status);
        $this->assertSame('user-3', $version->reviewer_id);
        $history = $this->findHistory($version, History::ACTION_REVIEWER_CHANGED);
        $this->assertSame('On holiday', $history->reason);
        $this->assertSame(
            ['number' => 1, 'reviewer' => 'user-3', 'previous_reviewer' => 'user-2'],
            $history->getDetails()
        );

        // Only the new reviewer may approve now.
        $this->actAs('user-2');
        $this->assertFalse($version->approve());
        $version->clearErrors();
        $this->actAs('user-3');
        $this->assertTrue($version->approve(), json_encode($version->getErrors()));
    }

    public function testChangeReviewerRejectsSameReviewerSubmitterAndUnknown(): void
    {
        $version = $this->createVersionInReview();

        foreach (['user-2', 'user-1', 'stranger', ''] as $reviewer) {
            $this->assertFalse($version->changeReviewer($reviewer), "Reviewer '$reviewer' accepted.");
            $this->assertTrue($version->hasErrors('reviewer_id'));
            $version->clearErrors();
        }

        $this->assertSame('user-2', Version::findOne($version->id)->reviewer_id);
        $this->assertNull($this->findHistory($version, History::ACTION_REVIEWER_CHANGED));
    }

    public function testChangeReviewerRequiresVersionInReview(): void
    {
        $version = $this->createDraftOfReviewItem();

        $this->assertFalse($version->changeReviewer('user-3'));

        $this->assertTrue($version->hasErrors('status'));
    }

    public function testNoDraftWhileAVersionIsInReview(): void
    {
        $item = $this->createReviewItem();
        $this->createVersionInReview($item);

        $draft = Version::createDraft(Item::findOne($item->id));

        $this->assertTrue($draft->getIsNewRecord());
        $this->assertSame('This item already has a version in review.', $draft->getFirstError('status'));
        $this->assertFalse(
            Version::find()->forItem($item->id)->andWhere(['status' => Version::STATUS_DRAFT])->exists()
        );
    }

    public function testMessagesAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $version = $this->createVersionInReview();

        $this->assertFalse($version->approve());

        $this->assertSame('Sie sind nicht die prüfende Person dieser Version.', $version->getFirstError('status'));
    }

    private function actAs(?string $reference): void
    {
        DummyUserProvider::$currentReference = $reference;
    }

    private function createReviewItem(): Item
    {
        return $this->createItem(['type_id' => $this->createType(['requires_review' => true])->id]);
    }

    private function createDraftOfReviewItem(array $attributes = []): Version
    {
        return $this->createVersion(
            $this->createReviewItem(),
            array_merge(['valid_from' => '2026-01-01'], $attributes)
        );
    }

    /**
     * Creates a version submitted by `user-1` for review by `user-2`.
     */
    private function createVersionInReview(?Item $item = null, array $attributes = [], ?string $message = null): Version
    {
        $version = $this->createVersion(
            $item ?? $this->createReviewItem(),
            array_merge(['valid_from' => '2026-01-01'], $attributes)
        );
        $this->assertTrue(
            $version->submitForReview('user-2', $message),
            json_encode($version->getErrors())
        );

        return $version;
    }

    private function findHistory(Version $version, string $action): ?History
    {
        return History::findOne(['version_id' => $version->id, 'action' => $action]);
    }

    private function countHistory(Version $version): int
    {
        return (int)History::find()->where(['version_id' => $version->id])->count();
    }
}
