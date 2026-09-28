<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;

/**
 * Tests of corrections: Version::createCorrection(), the validity rule of
 * corrections and publishing a correction.
 */
class CorrectionTest extends TestCase
{
    public function testCreateCorrectionCopiesTextFilesAndPeriod(): void
    {
        $topic = $this->createTopic();
        $item = $this->createItem(['title' => 'Guideline', 'summary' => 'Summary', 'topicIds' => [$topic->id]]);
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $faulty = $this->createPublishedVersion($item, [
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'content' => 'Faulty',
        ]);
        $main = $this->createFile($faulty, ['kind' => File::KIND_MAIN, 'name' => 'main.pdf', 'position' => 0]);
        $this->createFile($faulty, ['title' => 'Form', 'position' => 1]);

        $this->assertTrue($faulty->canCorrect());
        $correction = Version::createCorrection($faulty, '  Wrong amount  ');

        $this->assertFalse($correction->getIsNewRecord(), json_encode($correction->getErrors()));
        $this->assertTrue($correction->isCorrection());
        $this->assertFalse($faulty->isCorrection());

        $correction = Version::findOne($correction->id);
        $this->assertSame(Version::STATUS_DRAFT, $correction->status);
        $this->assertSame(3, $correction->number);
        $this->assertSame($faulty->id, $correction->corrects_version_id);
        $this->assertSame('Faulty', $correction->content);
        $this->assertSame('2026-01-01', $correction->valid_from);
        $this->assertSame('2026-12-31', $correction->valid_until);
        $this->assertSame('Guideline', $correction->draft_title);
        $this->assertSame('Summary', $correction->draft_summary);
        $this->assertSame([$topic->id], $correction->draftTopicIds);
        $this->assertSame(Version::CONTENT_UNCHANGED, $correction->getContentChange());

        $files = $correction->files;
        $this->assertCount(2, $files);
        $this->assertSame($main->path, $files[0]->path);
        $this->assertSame(File::KIND_MAIN, $files[0]->kind);
        $this->assertSame('Form', $files[1]->title);
        $this->assertCount(2, Version::findOne($faulty->id)->files);

        $faulty = Version::findOne($faulty->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $faulty->status);
        $this->assertSame('Wrong amount', $faulty->withdraw_reason);
        $this->assertNull($faulty->withdrawn_at);
    }

    public function testCorrectionWithoutReasonStoresNull(): void
    {
        $faulty = $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']);

        $this->assertFalse(Version::createCorrection($faulty, ' ')->getIsNewRecord());

        $this->assertNull(Version::findOne($faulty->id)->withdraw_reason);
    }

    public function testPublishingTheCorrectionWithdrawsTheCorrectedVersion(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => '2024-01-01']);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $third = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $faulty = Version::findOne($faulty->id);
        $this->assertSame('2025-12-31', $faulty->valid_until);

        // Historical versions can be corrected as well.
        $correction = Version::createCorrection($faulty, 'Wrong amount');
        $correction->content = 'Corrected';
        $this->assertTrue($correction->save());
        $this->publishAsReviewer($correction);

        $faulty = Version::findOne($faulty->id);
        $this->assertSame(Version::STATUS_WITHDRAWN, $faulty->status);
        $this->assertSame('user-2', $faulty->withdrawn_by);
        $this->assertNotNull($faulty->withdrawn_at);
        $this->assertSame('Wrong amount', $faulty->withdraw_reason);

        $correction = Version::findOne($correction->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $correction->status);
        $this->assertSame('2025-01-01', $correction->valid_from);
        $this->assertSame('2025-12-31', $correction->valid_until);

        // Neither the predecessor nor the successor changes.
        $this->assertSame('2024-12-31', Version::findOne($first->id)->valid_until);
        $this->assertNull(Version::findOne($third->id)->valid_until);

        $item = Item::findOne($item->id);
        $this->assertSame($correction->id, $item->getValidVersion('2025-06-01')->id);
        $this->assertSame($third->id, $item->getValidVersion('2026-06-01')->id);

        $history = History::findOne(['action' => History::ACTION_CORRECTED]);
        $this->assertSame($faulty->id, $history->version_id);
        $this->assertSame('Wrong amount', $history->reason);
        $this->assertSame('user-2', $history->actor_id);
        $this->assertSame(['number' => 2, 'correction' => 4], $history->getDetails());
        $this->assertSame('Version 2 corrected by version 4', $history->describe(new DummyUserProvider()));
        $this->assertNull(History::findOne(['version_id' => $correction->id, 'action' => History::ACTION_PUBLISHED]));
        $this->assertNotNull(History::findOne(['version_id' => $correction->id, 'action' => History::ACTION_APPROVED]));
    }

    public function testPublishingACorrectionWithoutReview(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $correction = Version::createCorrection($faulty);

        $this->assertTrue($correction->publish(), json_encode($correction->getErrors()));

        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($faulty->id)->status);
        $this->assertNull(History::findOne(['action' => History::ACTION_CORRECTED])->reason);
    }

    public function testPublishingTakesOverTheCurrentPeriodOfTheCorrectedVersion(): void
    {
        $item = $this->createItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $correction = Version::createCorrection($faulty);
        // E.g. a later version was withdrawn and this one extended meanwhile.
        $faulty->updateAttributes(['valid_until' => '2027-06-30']);

        $correction = Version::findOne($correction->id);
        $this->assertTrue($correction->submitForReview('user-2'), json_encode($correction->getErrors()));
        $this->assertSame('2027-06-30', Version::findOne($correction->id)->valid_until);

        $faulty->updateAttributes(['valid_until' => null]);
        DummyUserProvider::$currentReference = 'user-2';
        $this->assertTrue($correction->approve(), json_encode($correction->getErrors()));

        $this->assertNull(Version::findOne($correction->id)->valid_until);
    }

    public function testCorrectionKeepsThePeriodOfTheCorrectedVersion(): void
    {
        $faulty = $this->createPublishedVersion($this->createItem(), [
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);
        $correction = Version::findOne(Version::createCorrection($faulty)->id);
        $correction->scenario = Version::SCENARIO_VALIDITY;

        foreach ([
            ['valid_from' => '2026-02-01', 'valid_until' => '2026-12-31'],
            ['valid_from' => '2026-01-01', 'valid_until' => ''],
            ['valid_from' => '2026-01-01', 'valid_until' => '2027-12-31'],
        ] as $dates) {
            $correction->setAttributes($dates);
            $this->assertFalse($correction->validate(), json_encode($dates));
            $this->assertSame(
                ['A correction keeps the validity period of the corrected version.'],
                $correction->getErrors('valid_from')
            );
        }

        $correction->setAttributes(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        $this->assertTrue($correction->validate(), json_encode($correction->getErrors()));
    }

    public function testCorrectionIsRejectedWithDraftReviewOrArchive(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);

        $draft = $this->createVersion($item, ['valid_from' => '2026-06-01']);
        $this->assertRejected($published, 'This item already has a draft.');

        $this->assertTrue($draft->submitForReview('user-2'));
        $this->assertRejected($published, 'This item already has a version in review.');

        $draft->delete();
        $this->assertTrue($published->canCorrect());
        $this->assertTrue($item->archive());
        $this->assertRejected(Version::findOne($published->id), 'The knowledge object is archived.');

        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());
    }

    public function testOnlyPublishedVersionsCanBeCorrected(): void
    {
        $item = $this->createItem();
        $withdrawn = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue($withdrawn->withdraw('Reason', Version::WITHDRAW_NONE));

        $this->assertRejected($withdrawn, 'Only a published version can be corrected.');

        $draft = $this->createVersion($item, ['valid_from' => '2026-06-01']);
        $this->assertRejected($draft, 'Only a published version can be corrected.');
    }

    public function testSecondCorrectionIsRejected(): void
    {
        $published = $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']);
        $this->assertFalse(Version::createCorrection($published)->getIsNewRecord());

        $this->assertRejected($published, 'This item already has a draft.');
    }

    public function testTypeWithoutValidityPeriodOnlyCorrectsTheVersionInForce(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $old = $this->createPublishedVersion($item);
        $current = $this->createPublishedVersion($item);

        $this->assertRejected(
            Version::findOne($old->id),
            'Only the version in force can be corrected, as this type has no validity period.'
        );

        $correction = Version::createCorrection($current);
        $this->assertFalse($correction->getIsNewRecord());
        $this->assertNull($correction->valid_from);
        $this->assertNull($correction->valid_until);

        $this->publishAsReviewer(Version::findOne($correction->id));

        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($current->id)->status);
        $this->assertSame($correction->id, Item::findOne($item->id)->getValidVersion()->id);
    }

    public function testDiscardingTheCorrectionClearsTheReason(): void
    {
        $faulty = $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']);
        $correction = Version::createCorrection($faulty, 'Wrong amount');
        $this->assertSame('Wrong amount', Version::findOne($faulty->id)->withdraw_reason);

        $this->assertSame(1, Version::findOne($correction->id)->delete());

        $faulty = Version::findOne($faulty->id);
        $this->assertNull($faulty->withdraw_reason);
        $this->assertSame(Version::STATUS_PUBLISHED, $faulty->status);
    }

    public function testDeletingAnOrdinaryDraftKeepsWithdrawReasons(): void
    {
        $item = $this->createItem();
        $withdrawn = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue($withdrawn->withdraw('Reason', Version::WITHDRAW_NONE));
        $draft = $this->createVersion($item, ['valid_from' => '2026-06-01']);

        $draft->delete();

        $this->assertSame('Reason', Version::findOne($withdrawn->id)->withdraw_reason);
    }

    public function testPublishFailsIfTheCorrectedVersionIsNoLongerPublished(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $correction = Version::createCorrection($faulty);
        $faulty->updateAttributes(['status' => Version::STATUS_WITHDRAWN]);

        $this->assertFalse($correction->publish());

        $this->assertSame(['The corrected version is no longer published.'], $correction->getErrors('status'));
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($correction->id)->status);
        $this->assertNull(History::findOne(['action' => History::ACTION_CORRECTED]));
    }

    private function assertRejected(Version $version, string $error): void
    {
        $this->assertFalse($version->canCorrect());

        $correction = Version::createCorrection($version, 'Reason');

        $this->assertTrue($correction->getIsNewRecord());
        $this->assertSame([$error], $correction->getErrors('status'));
        $this->assertSame($version->id, $correction->corrects_version_id);
    }

    /**
     * Submits the version to `user-2` and publishes it as `user-2`.
     */
    private function publishAsReviewer(Version $version): void
    {
        $this->assertTrue($version->submitForReview('user-2'), json_encode($version->getErrors()));

        DummyUserProvider::$currentReference = 'user-2';
        try {
            $this->assertTrue($version->approve(), json_encode($version->getErrors()));
        } finally {
            DummyUserProvider::$currentReference = DummyUserProvider::DEFAULT_REFERENCE;
        }
    }
}
