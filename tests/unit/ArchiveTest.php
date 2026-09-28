<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

/**
 * Tests of archiving and restoring items and the locks of archived items.
 */
class ArchiveTest extends TestCase
{
    public function testArchiveSetsFlagAndWritesHistory(): void
    {
        $item = $this->createItem();
        DummyUserProvider::$currentReference = 'user-2';

        $this->assertTrue($item->archive('Outdated'));

        $item = Item::findOne($item->id);
        $this->assertTrue((bool)$item->is_archived);
        $this->assertSame('user-2', $item->updated_by);
        $history = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_ARCHIVED]);
        $this->assertNotNull($history);
        $this->assertNull($history->version_id);
        $this->assertSame('Outdated', $history->reason);
        $this->assertSame('user-2', $history->actor_id);
    }

    public function testArchiveTwiceIsRejected(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->archive());

        $this->assertFalse($item->archive());

        $this->assertTrue($item->hasErrors('is_archived'));
        $this->assertSame(1, $this->countHistory($item, History::ACTION_ARCHIVED));
    }

    public function testRestoreResetsFlagAndWritesHistory(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->archive());

        $this->assertTrue($item->restore());

        $this->assertFalse((bool)Item::findOne($item->id)->is_archived);
        $this->assertSame(1, $this->countHistory($item, History::ACTION_RESTORED));
    }

    public function testRestoreOfActiveItemIsRejected(): void
    {
        $item = $this->createItem();

        $this->assertFalse($item->restore());

        $this->assertTrue($item->hasErrors('is_archived'));
        $this->assertSame(0, $this->countHistory($item, History::ACTION_RESTORED));
    }

    public function testArchiveOnlyChangesTheFlag(): void
    {
        $item = $this->createItem(['title' => 'Title']);
        $item->title = 'Unsaved change';

        $this->assertTrue($item->archive());

        $this->assertSame('Title', Item::findOne($item->id)->title);
    }

    public function testArchiveKeepsOpenDraftAndReview(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => true])->id]);
        $inReview = $this->createVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue($inReview->submitForReview('user-2'));

        $this->assertTrue($item->archive());

        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($inReview->id)->status);
    }

    public function testNoDraftForArchivedItem(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->archive());

        $draft = Version::createDraft($item);

        $this->assertTrue($draft->getIsNewRecord());
        $this->assertSame('The knowledge object is archived.', $draft->getFirstError('status'));
        $this->assertFalse($item->getVersions()->exists());
    }

    public function testArchivedItemBlocksPublishAndSubmit(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => false])->id]);
        $draft = $this->createVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue(Item::findOne($item->id)->archive());

        $draft = Version::findOne($draft->id);
        $this->assertFalse($draft->publish());
        $this->assertSame('The knowledge object is archived.', $draft->getFirstError('status'));
        $draft->clearErrors();
        $this->assertFalse($draft->submitForReview('user-2'));
        $this->assertTrue($draft->hasErrors('status'));

        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testArchivedItemBlocksApproval(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => true])->id]);
        $version = $this->createVersion($item, ['valid_from' => '2026-01-01']);
        $this->assertTrue($version->submitForReview('user-2'));
        $this->assertTrue(Item::findOne($item->id)->archive());
        DummyUserProvider::$currentReference = 'user-2';

        $version = Version::findOne($version->id);
        $this->assertFalse($version->approve());

        $this->assertSame('The knowledge object is archived.', $version->getFirstError('status'));
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testRestoredItemAcceptsNewDrafts(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->archive());
        $this->assertTrue($item->restore());

        $draft = Version::createDraft($item);

        $this->assertFalse($draft->getIsNewRecord(), json_encode($draft->getErrors()));
    }

    public function testArchivedMessageIsTranslated(): void
    {
        Yii::$app->language = 'de';

        $this->assertSame('Das Wissensobjekt ist archiviert.', Item::archivedMessage());
    }

    private function countHistory(Item $item, string $action): int
    {
        return (int)History::find()->where(['item_id' => $item->id, 'action' => $action])->count();
    }
}
