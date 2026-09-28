<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use yii\base\Exception;

class FileHistoryTest extends TestCase
{
    public function testFileRequiredFields(): void
    {
        $file = new File();

        $this->assertFalse($file->validate());
        foreach (['version_id', 'storage_id', 'path', 'name'] as $attribute) {
            $this->assertTrue($file->hasErrors($attribute), $attribute);
        }
    }

    public function testFileValidation(): void
    {
        $version = $this->createVersion($this->createItem());
        $file = new File([
            'version_id' => '00000000-0000-4000-8000-000000000000',
            'kind' => 'other',
            'storage_id' => 'fs',
            'storage_item_id' => str_repeat('x', 37),
            'path' => str_repeat('p', 1025),
            'name' => 'a.pdf',
            'size' => -1,
            'position' => 'first',
        ]);

        $this->assertFalse($file->validate());
        foreach (['version_id', 'kind', 'storage_item_id', 'path', 'size', 'position'] as $attribute) {
            $this->assertTrue($file->hasErrors($attribute), $attribute);
        }

        $file->setAttributes([
            'version_id' => $version->id,
            'kind' => File::KIND_MAIN,
            'storage_item_id' => str_repeat('x', 36),
            'path' => 'knowledge-library/a.pdf',
            'size' => 0,
            'position' => null,
        ]);
        $this->assertTrue($file->validate(), json_encode($file->getErrors()));
        $this->assertSame(0, $file->position);
    }

    public function testFileDefaultsAndVersionRelation(): void
    {
        $version = $this->createVersion($this->createItem());
        $file = new File([
            'version_id' => $version->id,
            'storage_id' => 'fs',
            'path' => 'knowledge-library/a.pdf',
            'name' => 'a.pdf',
        ]);

        $this->assertTrue($file->save());
        $this->assertSame(File::KIND_ATTACHMENT, $file->kind);
        $this->assertSame($version->id, File::findOne($file->id)->version->id);
    }

    public function testMainFilesAndAttachmentsAreOrderedByPosition(): void
    {
        $version = $this->createVersion($this->createItem());
        $attachment2 = $this->createFile($version, ['position' => 2]);
        $main2 = $this->createFile($version, ['kind' => File::KIND_MAIN, 'position' => 2]);
        $attachment1 = $this->createFile($version, ['position' => 1]);
        $main1 = $this->createFile($version, ['kind' => File::KIND_MAIN, 'position' => 1]);

        $version = Version::findOne($version->id);
        $this->assertSame([$main1->id, $main2->id], array_column($version->mainFiles, 'id'));
        $this->assertSame([$attachment1->id, $attachment2->id], array_column($version->attachments, 'id'));
        $this->assertCount(4, $version->files);
    }

    public function testLogFillsActorAndCreatedAt(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);
        DummyUserProvider::$currentReference = 'editor';

        $history = History::log($item, History::ACTION_CREATED, $version, 'Initial version');

        $history = History::findOne($history->id);
        $this->assertSame($item->id, $history->item_id);
        $this->assertSame($version->id, $history->version_id);
        $this->assertSame('created', $history->action);
        $this->assertSame('Initial version', $history->reason);
        $this->assertSame('editor', $history->actor_id);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history->created_at);
        $this->assertFalse($history->hasAttribute('updated_at'));
        $this->assertSame([$history->id], array_column($item->history, 'id'));
        $this->assertSame([$history->id], array_column($version->history, 'id'));
    }

    public function testExplicitActorIsKept(): void
    {
        $history = new History([
            'item_id' => $this->createItem()->id,
            'action' => History::ACTION_ARCHIVED,
            'actor_id' => 'importer',
        ]);

        $this->assertTrue($history->save());
        $this->assertSame('importer', History::findOne($history->id)->actor_id);
    }

    public function testLogWithoutVersion(): void
    {
        $history = History::log($this->createItem(), History::ACTION_ARCHIVED);

        $this->assertNull($history->version_id);
        $this->assertSame('user-1', $history->actor_id);
    }

    public function testLogThrowsOnInvalidEntry(): void
    {
        $this->expectException(Exception::class);

        History::log($this->createItem(), str_repeat('a', 65));
    }

    public function testHistoryValidation(): void
    {
        $history = new History([
            'item_id' => '00000000-0000-4000-8000-000000000000',
            'version_id' => '00000000-0000-4000-8000-000000000000',
        ]);

        $this->assertFalse($history->validate());
        $this->assertTrue($history->hasErrors('item_id'));
        $this->assertTrue($history->hasErrors('version_id'));
        $this->assertTrue($history->hasErrors('action'));
    }

    public function testHistoryVersionIsSetNullWhenVersionIsDeleted(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);
        $history = History::log($item, History::ACTION_CREATED, $version);

        $this->assertSame(1, $version->delete());

        $history = History::findOne($history->id);
        $this->assertNotNull($history);
        $this->assertNull($history->version_id);
    }

    public function testDeletingItemCascades(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01']);
        $file = $this->createFile($draft);
        $history = History::log($item, History::ACTION_PUBLISHED, $published);

        $this->assertSame(1, Item::findOne($item->id)->delete());

        $this->assertNull(Version::findOne($published->id));
        $this->assertNull(Version::findOne($draft->id));
        $this->assertNull(File::findOne($file->id));
        $this->assertNull(History::findOne($history->id));
    }
}
