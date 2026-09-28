<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class DraftTest extends TestCase
{
    public function testCreateFirstDraftFromItem(): void
    {
        $topic = $this->createTopic();
        $item = $this->createItem(['title' => 'Guideline', 'summary' => 'Summary', 'topicIds' => [$topic->id]]);

        $draft = Version::createDraft(Item::findOne($item->id), '2026-09-28');

        $this->assertFalse($draft->getIsNewRecord(), json_encode($draft->getErrors()));
        $draft = Version::findOne($draft->id);
        $this->assertSame(Version::STATUS_DRAFT, $draft->status);
        $this->assertSame(1, $draft->number);
        $this->assertNull($draft->content);
        $this->assertSame('2026-09-28', $draft->valid_from);
        $this->assertNull($draft->valid_until);
        $this->assertSame('Guideline', $draft->draft_title);
        $this->assertSame('Summary', $draft->draft_summary);
        $this->assertSame([$topic->id], $draft->draftTopicIds);
        $this->assertSame([], $draft->files);
    }

    public function testCreateDraftCopiesTheLatestPublishedVersion(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01', 'content' => 'Old']);
        $latest = $this->createPublishedVersion($item, ['valid_from' => '2026-03-01', 'content' => 'Latest']);
        $main = $this->createFile($latest, [
            'kind' => File::KIND_MAIN,
            'name' => 'main.pdf',
            'mime_type' => 'application/pdf',
            'size' => 123,
            'content_hash' => str_repeat('a', 64),
            'position' => 0,
        ]);
        $attachment = $this->createFile($latest, ['title' => 'Form', 'position' => 1]);

        $draft = Version::createDraft(Item::findOne($item->id), '2026-01-15');

        $this->assertSame(3, $draft->number);
        $this->assertSame('Latest', $draft->content);
        // Day after the start of the predecessor, as it is later than today.
        $this->assertSame('2026-03-02', $draft->valid_from);

        $files = Version::findOne($draft->id)->files;
        $this->assertCount(2, $files);
        foreach ([$main, $attachment] as $index => $original) {
            $copy = $files[$index];
            $this->assertNotSame($original->id, $copy->id);
            $this->assertSame($draft->id, $copy->version_id);
            foreach (['kind', 'title', 'storage_id', 'path', 'name', 'mime_type', 'size', 'content_hash', 'position'] as $attribute) {
                $this->assertEquals($original->$attribute, $copy->$attribute, $attribute);
            }
        }
        // The published version keeps its files.
        $this->assertCount(2, Version::findOne($latest->id)->files);
    }

    public function testSuggestedValidFromIsAtLeastToday(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame('2026-09-28', Version::createDraft($item, '2026-09-28')->valid_from);
    }

    public function testSuggestedValidFromIsEmptyWithoutValidityPeriod(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $this->createPublishedVersion($item, ['content' => 'Text']);

        $draft = Version::createDraft($item, '2026-09-28');

        $this->assertFalse($draft->getIsNewRecord(), json_encode($draft->getErrors()));
        $this->assertNull($draft->valid_from);
        $this->assertSame('Text', $draft->content);
    }

    public function testCreateDraftReturnsTheExistingDraft(): void
    {
        $item = $this->createItem();
        $existing = $this->createVersion($item, ['content' => 'Work in progress']);

        $draft = Version::createDraft($item);

        $this->assertSame($existing->id, $draft->id);
        $this->assertSame('Work in progress', $draft->content);
        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());
    }

    public function testScenariosOnlyAllowTheirAttributes(): void
    {
        $item = $this->createItem();
        $other = $this->createItem();
        $draft = $this->createVersion($item);
        $posted = [
            'item_id' => $other->id,
            'status' => Version::STATUS_PUBLISHED,
            'number' => 99,
            'content' => 'New text',
            'valid_from' => '2026-05-01',
            'valid_until' => '2026-12-31',
            'draft_title' => 'New title',
            'draft_summary' => 'New summary',
            'draftTopicIds' => [],
            'published_by' => 'someone',
            'corrects_version_id' => $draft->id,
        ];

        $expected = [
            Version::SCENARIO_CONTENT => ['content'],
            Version::SCENARIO_VALIDITY => ['valid_from', 'valid_until'],
            Version::SCENARIO_DETAILS => ['draft_title', 'draft_summary', 'draft_topic_ids'],
        ];
        foreach ($expected as $scenario => $changed) {
            $model = Version::findOne($draft->id);
            $before = $model->getAttributes();
            $model->scenario = $scenario;
            $model->load($posted, '');

            $this->assertSame(
                $changed,
                array_keys(array_diff_assoc(array_map('strval', $model->getAttributes()), array_map('strval', $before))),
                $scenario
            );
            $this->assertSame($item->id, $model->item_id);
        }
    }

    public function testValidityScenarioRequiresValidFrom(): void
    {
        $draft = $this->createVersion($this->createItem(), ['valid_from' => null]);
        $draft->scenario = Version::SCENARIO_VALIDITY;

        $this->assertFalse($draft->validate());
        $this->assertSame('Enter "Valid From" in the format DD.MM.YYYY.', $draft->getFirstError('valid_from'));

        $draft->valid_from = '2026-05-01';
        $this->assertTrue($draft->validate());

        // Types without validity period need no date.
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $draft = $this->createVersion($item);
        $draft->scenario = Version::SCENARIO_VALIDITY;
        $this->assertTrue($draft->validate(), json_encode($draft->getErrors()));
    }

    public function testDetailsScenarioValidatesTitleAndTopics(): void
    {
        $topic = $this->createTopic();
        $draft = $this->createVersion($this->createItem());
        $draft->scenario = Version::SCENARIO_DETAILS;

        $draft->load(['draft_title' => '   ', 'draftTopicIds' => [$topic->id]], '');
        $this->assertFalse($draft->validate());
        $this->assertTrue($draft->hasErrors('draft_title'));
        $this->assertFalse($draft->hasErrors('draftTopicIds'));

        $draft->load(['draft_title' => str_repeat('x', 256)], '');
        $this->assertFalse($draft->validate());
        $this->assertTrue($draft->hasErrors('draft_title'));

        $draft->load(['draft_title' => '  Title  ', 'draftTopicIds' => [$topic->id, 'unknown']], '');
        $this->assertFalse($draft->validate());
        $this->assertTrue($draft->hasErrors('draftTopicIds'));

        $draft->load(['draft_title' => '  Title  ', 'draft_summary' => 'Summary', 'draftTopicIds' => [$topic->id]], '');
        $this->assertTrue($draft->validate(), json_encode($draft->getErrors()));
        $this->assertSame('Title', $draft->draft_title);
        $this->assertTrue($draft->save());

        $draft = Version::findOne($draft->id);
        $this->assertSame([$topic->id], $draft->draftTopicIds);
        $this->assertSame(json_encode([$topic->id]), $draft->draft_topic_ids);
    }

    public function testDraftTopicIdsFromEmptySelect(): void
    {
        $version = new Version();
        $this->assertSame([], $version->draftTopicIds);

        $version->draftTopicIds = '';
        $this->assertSame('[]', $version->draft_topic_ids);
        $this->assertSame([], $version->draftTopicIds);

        $version->draftTopicIds = ['a', 'a', 'b'];
        $this->assertSame(['a', 'b'], $version->draftTopicIds);

        $version->draft_topic_ids = 'not json';
        $this->assertSame([], $version->draftTopicIds);
    }

    public function testHasContent(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => " \n "]);
        $this->assertFalse($version->hasContent());

        $this->createFile($version, ['kind' => File::KIND_ATTACHMENT]);
        $this->assertFalse($version->hasContent(), 'Attachments alone are no content.');

        $this->createFile($version, ['kind' => File::KIND_MAIN]);
        $this->assertTrue($version->hasContent());

        $this->assertTrue((new Version(['content' => 'Text']))->hasContent());
        $this->assertFalse((new Version(['content' => null]))->hasContent());
    }

    public function testNoContentMessage(): void
    {
        $this->assertSame('Enter a text or attach a main file.', Version::noContentMessage());

        Yii::$app->language = 'de';
        $this->assertSame(
            'Bitte Text erfassen oder mindestens ein Hauptdokument hinzufügen.',
            Version::noContentMessage()
        );
    }

    public function testContentChange(): void
    {
        $item = $this->createItem();
        $draft = $this->createVersion($item, ['content' => 'First']);
        $this->assertSame(Version::CONTENT_CHANGED, $draft->getContentChange(), 'Text without predecessor');

        $draft->content = '  ';
        $this->assertSame(Version::CONTENT_NONE, $draft->getContentChange());

        $draft->delete();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'content' => "Line 1\nLine 2"]);
        $draft = $this->createVersion($item, ['valid_from' => '2026-02-01', 'content' => "Line 1\r\nLine 2\r\n"]);
        $this->assertSame(Version::CONTENT_UNCHANGED, $draft->getContentChange());

        $draft->content = "Line 1\nLine 3";
        $this->assertSame(Version::CONTENT_CHANGED, $draft->getContentChange());
    }

    public function testContentSummary(): void
    {
        $version = $this->createVersion($this->createItem(), ['content' => '']);
        $this->assertSame('empty', $version->getContentSummary());

        $version->content = 'Text';
        $this->createFile($version, ['kind' => File::KIND_MAIN]);
        $this->createFile($version);
        $this->createFile($version);
        $this->assertSame('Text, 1 main document, 2 attachments', $version->getContentSummary());

        Yii::$app->language = 'de';
        $this->createFile($version, ['kind' => File::KIND_MAIN]);
        $this->assertSame('Text, 2 Hauptdokumente, 2 Anhänge', $version->getContentSummary());
    }

    public function testPredecessor(): void
    {
        $item = $this->createItem();
        $this->assertNull($this->createVersion($item)->getPredecessor());
        Version::deleteAll(['item_id' => $item->id]);

        $first = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $second = $this->createPublishedVersion($item, ['valid_from' => '2026-02-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame($second->id, $draft->getPredecessor()->id);
        $this->assertSame($first->id, Version::findOne($second->id)->getPredecessor()->id);
    }
}
