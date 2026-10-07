<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\tests\web\mcp;

use dmstr\knowledgeLibrary\mcp\Server;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\tests\McpWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendFixtures;
use Yii;

/**
 * The endpoint and the tools of the MCP module against the data model.
 */
class ToolsTest extends McpWebTestCase
{
    use FrontendFixtures;

    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs(static::PERMISSION);
    }

    public function testInitializeNegotiatesTheVersionAndListsTheTools(): void
    {
        $result = $this->rpcResult('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ]);

        $this->assertSame('2025-03-26', $result['protocolVersion']);
        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertSame('Knowledge Library', $result['serverInfo']['name']);
        $this->assertStringContainsString('knowledge_search', $result['instructions']);

        $tools = $this->rpcResult('tools/list')['tools'];
        $this->assertSame(
            ['knowledge_search', 'knowledge_get_item', 'knowledge_get_file', 'knowledge_list_types', 'knowledge_list_topics'],
            array_column($tools, 'name')
        );
        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
        }
    }

    public function testNotificationIsAccepted(): void
    {
        $this->assertNull($this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->assertSame(202, $this->getResponse()->getStatusCode());
    }

    public function testInvalidBodyIsABadRequest(): void
    {
        $response = $this->rpc('{"jsonrpc":"2.0",');

        $this->assertSame(400, $this->getResponse()->getStatusCode());
        $this->assertNull($response['id']);
        $this->assertSame(Server::ERROR_PARSE, $response['error']['code']);
    }

    public function testSearchListsExactlyTheValidActiveItemsSorted(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->createInvalidItems();
        $this->createValidItem(['title' => 'Charlie', 'type_id' => $type->id]);
        [$alphaSecond] = $this->createValidItem(['title' => 'Alpha', 'type_id' => $type->id]);
        [$alphaFirst] = $this->createValidItem(['title' => 'Alpha', 'type_id' => $type->id]);
        $this->createValidItem(['title' => 'Bravo', 'type_id' => $type->id]);

        $data = $this->structured('knowledge_search');

        $this->assertSame(4, $data['total']);
        $this->assertSame(['Alpha', 'Alpha', 'Bravo', 'Charlie'], array_column($data['items'], 'title'));
        $ids = [$alphaFirst->id, $alphaSecond->id];
        sort($ids);
        $this->assertSame($ids, array_slice(array_column($data['items'], 'id'), 0, 2));
    }

    public function testSearchReturnsTheSummaryOfTheValidVersion(): void
    {
        $type = $this->createType(['name' => 'Guideline']);
        $zulu = $this->createTopic(['name' => 'Zulu']);
        $alpha = $this->createTopic(['name' => 'Alpha']);
        [$item, $version] = $this->createValidItem([
            'title' => 'Forest law',
            'summary' => '  Rules of the forest  ',
            'type_id' => $type->id,
            'topicIds' => [$zulu->id, $alpha->id],
        ], ['valid_from' => $this->day(-10), 'valid_until' => $this->day(10)]);

        $data = $this->structured('knowledge_search', ['query' => 'forest']);

        $this->assertSame(1, $data['total']);
        $this->assertSame([
            'id' => $item->id,
            'title' => 'Forest law',
            'type' => 'Guideline',
            'topics' => ['Alpha', 'Zulu'],
            'summary' => 'Rules of the forest',
            'valid_from' => $this->day(-10),
            'valid_until' => $this->day(10),
            'published_at' => substr($version->published_at, 0, 10),
            'version' => 1,
            'updated_at' => max($item->updated_at, $version->updated_at),
        ], $data['items'][0]);
    }

    public function testSearchMatchesTitleSummaryAndValidContentOnly(): void
    {
        $this->createValidItem(['title' => 'Alpha', 'summary' => 'Bravo'], ['content' => 'Charlie delta']);
        [$withDraft] = $this->createValidItem(['title' => 'Other'], ['content' => 'Valid text']);
        $this->createVersion($withDraft, ['content' => 'Charlie only in the draft']);
        [$historical] = $this->createValidItem(['title' => 'Replaced'], ['content' => 'Charlie in the old version', 'valid_from' => $this->day(-60)]);
        $this->createPublishedVersion($historical, ['content' => 'New text', 'valid_from' => $this->day(-1)]);

        $this->assertSame(['Alpha'], $this->titles(['query' => 'ALPHA']));
        $this->assertSame(['Alpha'], $this->titles(['query' => 'bravo']));
        $this->assertSame(['Alpha'], $this->titles(['query' => 'charlie']));
        $this->assertSame(['Other'], $this->titles(['query' => 'valid text']));
        $this->assertSame(['Replaced'], $this->titles(['query' => 'new text']));
        $this->assertSame([], $this->titles(['query' => 'nothing like this']));
        $this->assertSame([], $this->titles(['query' => '%']));
    }

    public function testSearchFiltersByTypeAndTopic(): void
    {
        $law = $this->createType(['name' => 'Law']);
        $guideline = $this->createType(['name' => 'Guideline']);
        $topic = $this->createTopic(['name' => 'Harvest']);
        $this->createValidItem(['title' => 'Law with topic', 'type_id' => $law->id, 'topicIds' => [$topic->id]]);
        $this->createValidItem(['title' => 'Law without topic', 'type_id' => $law->id]);
        $this->createValidItem(['title' => 'Guideline with topic', 'type_id' => $guideline->id, 'topicIds' => [$topic->id]]);

        $this->assertSame(['Law with topic', 'Law without topic'], $this->titles(['type_id' => $law->id]));
        $this->assertSame(['Guideline with topic', 'Law with topic'], $this->titles(['topic_id' => $topic->id]));
        $this->assertSame(['Law with topic'], $this->titles(['type_id' => $law->id, 'topic_id' => $topic->id]));
        $this->assertSame([], $this->titles(['type_id' => self::UNKNOWN_ID]));
    }

    public function testSearchPages(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $title) {
            $this->createValidItem(['title' => $title]);
        }

        $page = $this->structured('knowledge_search', ['limit' => 2, 'offset' => 2]);

        $this->assertSame(5, $page['total']);
        $this->assertSame(2, $page['offset']);
        $this->assertSame(2, $page['limit']);
        $this->assertSame(['C', 'D'], array_column($page['items'], 'title'));

        $this->assertSame(['E'], array_column($this->structured('knowledge_search', ['limit' => '2', 'offset' => 4.0])['items'], 'title'));
    }

    public function testSearchRejectsInvalidArguments(): void
    {
        foreach ([['limit' => 0], ['limit' => 101], ['offset' => -1], ['limit' => 'many'], ['query' => ['x']]] as $arguments) {
            $error = $this->rpcError('tools/call', ['name' => 'knowledge_search', 'arguments' => $arguments]);
            $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code'], json_encode($arguments));
        }
    }

    public function testGetItemReturnsTheDetails(): void
    {
        $type = $this->createType(['name' => 'Law']);
        [$item, $version] = $this->createValidItem([
            'title' => 'Forest law',
            'type_id' => $type->id,
            'source_name' => 'Ministry',
            'source_reference' => 'Gazette 12',
            'source_url' => 'https://example.org/law',
        ], ['content' => "# Heading\n\nText with <b>html</b>."]);
        $main = $this->createStoredFile($version, 'law.pdf', ['title' => 'The law']);
        $attachment = $this->createStoredFile($version, 'notes.txt', ['kind' => File::KIND_ATTACHMENT, 'mime_type' => 'text/plain', 'position' => 1]);
        [$related] = $this->createValidItem(['title' => 'Related guideline']);
        [$hidden] = $this->createValidItem(['title' => 'Archived guideline']);
        $this->assertTrue($hidden->archive());
        $this->createRelation($item, $related, Relation::TYPE_BASED_ON);
        $this->createRelation($hidden, $item, Relation::TYPE_SUPPLEMENTS);
        $this->createRelation($related, $item, Relation::TYPE_REPLACES);

        $data = $this->structured('knowledge_get_item', ['id' => $item->id]);

        $this->assertSame('Forest law', $data['title']);
        $this->assertSame(['name' => 'Ministry', 'reference' => 'Gazette 12', 'url' => 'https://example.org/law'], $data['source']);
        $this->assertSame("# Heading\n\nText with <b>html</b>.", $data['content']);
        $this->assertSame([$main->id, $attachment->id], array_column($data['files'], 'id'));
        $this->assertSame([
            'id' => $main->id,
            'kind' => 'main',
            'title' => 'The law',
            'name' => 'law.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen(static::$fileContent),
            'download_url' => $data['files'][0]['download_url'],
        ], $data['files'][0]);
        $this->assertStringStartsWith('http://localhost/', $data['files'][0]['download_url']);
        $this->assertStringContainsString('file%2Fdownload', $data['files'][0]['download_url']);
        $this->assertStringContainsString($main->id, $data['files'][0]['download_url']);
        $this->assertSame([
            ['relation' => 'is based on', 'id' => $related->id, 'title' => 'Related guideline'],
            ['relation' => 'is replaced by', 'id' => $related->id, 'title' => 'Related guideline'],
        ], $data['relations']);
    }

    public function testGetItemOfTypeWithoutValidityPeriodHasNoDates(): void
    {
        $type = $this->createType(['name' => 'Note', 'has_validity_period' => false]);
        [$item, $version] = $this->createValidItem(['type_id' => $type->id], ['valid_from' => null]);

        $data = $this->structured('knowledge_get_item', ['id' => $item->id]);

        $this->assertNull($data['valid_from']);
        $this->assertNull($data['valid_until']);
        $this->assertSame(substr($version->published_at, 0, 10), $data['published_at']);
        $this->assertSame(['name' => null, 'reference' => null, 'url' => null], $data['source']);
        $this->assertSame([], $data['files']);
        $this->assertSame([], $data['relations']);
    }

    public function testGetItemOfInvalidItemsIsAToolError(): void
    {
        $invalid = $this->createInvalidItems();

        foreach ($invalid as $reason => [$item]) {
            $message = $this->toolError('knowledge_get_item', ['id' => $item->id]);
            $this->assertStringContainsString($item->id, $message, $reason);
        }
        $this->assertStringContainsString(self::UNKNOWN_ID, $this->toolError('knowledge_get_item', ['id' => self::UNKNOWN_ID]));

        $error = $this->rpcError('tools/call', ['name' => 'knowledge_get_item', 'arguments' => ['id' => '']]);
        $this->assertSame(Server::ERROR_INVALID_PARAMS, $error['code']);
    }

    public function testGetFileReturnsTextAndBlobs(): void
    {
        [$item, $version] = $this->createValidItem(['title' => 'Forest law']);
        $pdf = $this->createStoredFile($version, 'law.pdf');
        $text = $this->createStoredFile($version, 'notes.txt', ['kind' => File::KIND_ATTACHMENT, 'mime_type' => 'text/plain']);

        $result = $this->callTool('knowledge_get_file', ['id' => $pdf->id]);
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertSame('resource', $result['content'][0]['type']);
        $this->assertSame('application/pdf', $result['content'][0]['resource']['mimeType']);
        $this->assertSame(static::$fileContent, base64_decode($result['content'][0]['resource']['blob']));
        $this->assertSame($result['structuredContent']['download_url'], $result['content'][0]['resource']['uri']);
        $this->assertSame(['id' => $item->id, 'title' => 'Forest law'], $result['structuredContent']['item']);
        $this->assertSame('law.pdf', $result['structuredContent']['name']);

        $result = $this->callTool('knowledge_get_file', ['id' => $text->id]);
        $this->assertSame(static::$fileContent, $result['content'][0]['resource']['text']);
        $this->assertArrayNotHasKey('blob', $result['content'][0]['resource']);
    }

    public function testGetFileLargerThanTheLimitNamesTheDownload(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'law.pdf');
        $this->maxInlineFileSize = strlen(static::$fileContent) - 1;

        $message = $this->toolError('knowledge_get_file', ['id' => $file->id]);

        $this->assertStringContainsString('law.pdf', $message);
        $this->assertStringContainsString('file%2Fdownload', $message);
        $this->assertStringContainsString($file->id, $message);
    }

    public function testGetFileOfInvalidVersionsAndMissingFilesIsAToolError(): void
    {
        foreach ($this->createInvalidItems() as $reason => [, $version]) {
            $file = $this->createStoredFile($version, "$reason.pdf");
            $this->assertStringContainsString($file->id, $this->toolError('knowledge_get_file', ['id' => $file->id]), $reason);
        }

        [, $version] = $this->createValidItem();
        $missing = $this->createFile($version, ['kind' => File::KIND_MAIN, 'name' => 'missing.pdf']);
        $this->assertStringContainsString('missing.pdf', $this->toolError('knowledge_get_file', ['id' => $missing->id]));
        $this->assertStringContainsString(self::UNKNOWN_ID, $this->toolError('knowledge_get_file', ['id' => self::UNKNOWN_ID]));
    }

    public function testListTypesAndTopicsCountTheValidItems(): void
    {
        $law = $this->createType(['name' => 'Law', 'has_validity_period' => true, 'requires_review' => true]);
        $note = $this->createType(['name' => 'Note', 'has_validity_period' => false, 'requires_review' => false]);
        $harvest = $this->createTopic(['name' => 'Harvest']);
        $empty = $this->createTopic(['name' => 'Empty']);
        $this->createValidItem(['type_id' => $law->id, 'topicIds' => [$harvest->id]]);
        $this->createValidItem(['type_id' => $law->id]);
        [$archived] = $this->createValidItem(['type_id' => $law->id, 'topicIds' => [$harvest->id]]);
        $this->assertTrue($archived->archive());
        $this->createItem(['type_id' => $note->id, 'topicIds' => [$harvest->id]]);

        $this->assertSame([
            ['id' => $law->id, 'name' => 'Law', 'has_validity_period' => true, 'requires_review' => true, 'item_count' => 2],
            ['id' => $note->id, 'name' => 'Note', 'has_validity_period' => false, 'requires_review' => false, 'item_count' => 0],
        ], $this->structured('knowledge_list_types')['types']);
        $this->assertSame([
            ['id' => $empty->id, 'name' => 'Empty', 'item_count' => 0],
            ['id' => $harvest->id, 'name' => 'Harvest', 'item_count' => 1],
        ], $this->structured('knowledge_list_topics')['topics']);
    }

    /**
     * Titles returned by knowledge_search with the arguments.
     *
     * @return string[]
     */
    private function titles(array $arguments): array
    {
        return array_column($this->structured('knowledge_search', $arguments)['items'], 'title');
    }

    private function createRelation(Item $source, Item $target, string $type): Relation
    {
        $relation = new Relation([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'type' => $type,
        ]);
        $this->assertTrue($relation->save(), json_encode($relation->getErrors()));

        return $relation;
    }
}
