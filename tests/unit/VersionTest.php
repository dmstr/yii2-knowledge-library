<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class VersionTest extends TestCase
{
    public function testNumberIsIncrementedPerItem(): void
    {
        $first = $this->createItem();
        $second = $this->createItem();

        $this->assertSame(1, $this->createPublishedVersion($first, ['valid_from' => '2026-01-01'])->number);
        $this->assertSame(2, $this->createVersion($first, ['valid_from' => '2026-02-01'])->number);
        $this->assertSame(1, $this->createVersion($second)->number);
    }

    public function testNumberIsNotSafe(): void
    {
        $version = new Version();
        $version->load(['number' => 5, 'status' => Version::STATUS_PUBLISHED, 'content' => 'Text'], '');

        $this->assertNull($version->number);
        $this->assertNull($version->status);
        $this->assertSame('Text', $version->content);
    }

    public function testItemIsRequiredAndMustExist(): void
    {
        $version = new Version();
        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('item_id'));

        $version->item_id = '00000000-0000-4000-8000-000000000000';
        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('item_id'));
    }

    public function testStatusDefaultsToDraftAndMustBeInRange(): void
    {
        $version = new Version(['item_id' => $this->createItem()->id]);
        $this->assertTrue($version->validate());
        $this->assertSame(Version::STATUS_DRAFT, $version->status);

        $version->status = 'unknown';
        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('status'));
    }

    public function testDraftMayBeEmpty(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => '  ']);

        $this->assertSame(Version::STATUS_DRAFT, $version->status);
    }

    public function testContentIsRequiredForReviewAndPublication(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item, ['content' => "  \n ", 'valid_from' => '2026-01-01']);

        $this->assertFalse($version->submitForReview('user-2'));
        $this->assertTrue($version->hasErrors('content'));
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($version->id)->status);

        $version = new Version([
            'item_id' => $item->id,
            'content' => '',
            'valid_from' => '2026-01-01',
        ]);
        $version->status = Version::STATUS_PUBLISHED;
        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('content'));

        $version->content = 'Text';
        $this->assertTrue($version->validate());
    }

    public function testMainFileCountsAsContent(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => null, 'valid_from' => '2026-01-01']);
        $this->createFile($version, ['kind' => File::KIND_MAIN]);

        $this->assertTrue($version->submitForReview('user-2'), json_encode($version->getErrors()));
    }

    public function testAttachmentDoesNotCountAsContent(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => null, 'valid_from' => '2026-01-01']);
        $this->createFile($version, ['kind' => File::KIND_ATTACHMENT]);

        $this->assertFalse($version->submitForReview('user-2'));
        $this->assertTrue($version->hasErrors('content'));
    }

    public function testDatesMustBeValid(): void
    {
        $version = new Version([
            'item_id' => $this->createItem()->id,
            'valid_from' => '2026-1-5',
            'valid_until' => '2026-02-30',
        ]);

        $this->assertFalse($version->validate());
        $this->assertSame('Enter "Valid From" in the format DD.MM.YYYY.', $version->getFirstError('valid_from'));
        $this->assertSame('Enter "Valid Until" in the format DD.MM.YYYY.', $version->getFirstError('valid_until'));
    }

    public function testValidUntilBeforeValidFromIsRejected(): void
    {
        $version = new Version([
            'item_id' => $this->createItem()->id,
            'valid_from' => '2026-03-01',
            'valid_until' => '2026-02-28',
        ]);
        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('valid_until'));

        $version->valid_until = '2026-03-01';
        $this->assertTrue($version->validate());
    }

    public function testValidFromIsRequiredForReviewButNotForDraft(): void
    {
        $version = $this->createVersion($this->createItem(), ['valid_from' => null, 'valid_until' => '2026-12-31']);

        $this->assertFalse($version->submitForReview('user-2'));
        $this->assertTrue($version->hasErrors('valid_from'));

        $version->valid_from = '2026-01-01';
        $this->assertTrue($version->submitForReview('user-2'));
    }

    public function testTypeWithoutValidityPeriodRejectsDates(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $version = new Version([
            'item_id' => $item->id,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
        ]);

        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('valid_from'));
        $this->assertTrue($version->hasErrors('valid_until'));

        $version->valid_from = null;
        $version->valid_until = null;
        $this->assertTrue($version->validate());
    }

    public function testSecondDraftIsRejected(): void
    {
        $item = $this->createItem();
        $draft = $this->createVersion($item);

        $second = new Version(['item_id' => $item->id]);
        $this->assertFalse($second->validate());
        $this->assertTrue($second->hasErrors('status'));

        // The existing draft itself stays valid.
        $this->assertTrue($draft->validate());
    }

    public function testSecondVersionInReviewIsRejected(): void
    {
        $item = $this->createItem();
        $this->assertTrue($this->createVersion($item, ['valid_from' => '2026-01-01'])->submitForReview('user-2'));

        // A draft next to the version in review is allowed.
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);

        $this->assertFalse($draft->submitForReview('user-2'));
        $this->assertTrue($draft->hasErrors('status'));
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testRetroactiveNewVersionIsRejected(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        foreach (['2026-02-01', '2026-03-01'] as $validFrom) {
            $version = new Version(['item_id' => $item->id, 'valid_from' => $validFrom]);
            $this->assertFalse($version->validate(), $validFrom);
            $this->assertSame(
                'The date is before the start of version 1 (' . Yii::$app->formatter->asDate('2026-03-01')
                    . '). Retroactive changes are only possible with "Correct".',
                $version->getFirstError('valid_from'),
                $validFrom
            );
        }

        $version = new Version(['item_id' => $item->id, 'valid_from' => '2026-03-02']);
        $this->assertTrue($version->validate());
    }

    public function testRetroactiveVersionIsAllowedAsCorrection(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $correction = new Version([
            'item_id' => $item->id,
            'valid_from' => '2026-03-01',
            'corrects_version_id' => $published->id,
        ]);

        $this->assertTrue($correction->validate(), json_encode($correction->getErrors()));
    }

    public function testCorrectedVersionMustBelongToSameItem(): void
    {
        $other = $this->createPublishedVersion($this->createItem(), ['valid_from' => '2026-01-01']);

        $version = new Version([
            'item_id' => $this->createItem()->id,
            'valid_from' => '2026-01-01',
            'corrects_version_id' => $other->id,
        ]);

        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('corrects_version_id'));
    }

    public function testVersionCannotCorrectItself(): void
    {
        $version = $this->createVersion($this->createItem());
        $version->corrects_version_id = $version->id;

        $this->assertFalse($version->validate());
        $this->assertTrue($version->hasErrors('corrects_version_id'));
    }

    public function testSubmitForReviewOnlyFromDraft(): void
    {
        $version = $this->createVersion($this->createItem(), ['valid_from' => '2026-01-01']);
        $this->assertTrue($version->submitForReview('user-2', 'Please check'));

        $this->assertSame(Version::STATUS_IN_REVIEW, $version->status);
        $this->assertSame('user-2', $version->reviewer_id);
        $this->assertSame('Please check', $version->review_message);
        $this->assertNotNull($version->review_requested_at);

        $this->assertFalse($version->submitForReview('user-2'));
        $this->assertTrue($version->hasErrors('status'));
    }

    public function testRelations(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);
        $attachment = $this->createFile($version, ['position' => 2]);
        $main = $this->createFile($version, ['kind' => File::KIND_MAIN, 'position' => 1]);

        $this->assertSame($item->id, $version->item->id);
        $this->assertSame([$main->id, $attachment->id], array_column($version->files, 'id'));
        $this->assertSame([$main->id], array_column($version->mainFiles, 'id'));
        $this->assertSame([$attachment->id], array_column($version->attachments, 'id'));
        $this->assertSame([$version->id], array_column(Item::findOne($item->id)->versions, 'id'));
    }

    public function testCorrectedVersionRelation(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $correction = $this->createVersion($item, [
            'valid_from' => '2026-01-01',
            'corrects_version_id' => $published->id,
        ]);

        $this->assertSame($published->id, $correction->correctedVersion->id);
    }
}
