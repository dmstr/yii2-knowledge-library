<?php

namespace dmstr\knowledgeLibrary\tests\support;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use Yii;
use yii\web\Response;

/**
 * Fixtures of the frontend page tests: items in every version state, stored
 * files and reading the stream of a download response.
 *
 * Used by tests extending FrontendWebTestCase.
 */
trait FrontendFixtures
{
    /**
     * Content of the stored files.
     */
    protected static string $fileContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /**
     * Date relative to today in the format `Y-m-d`.
     */
    protected function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * Creates an item with a published version valid today, unless the item
     * attributes say otherwise.
     *
     * @return array{0: Item, 1: Version}
     */
    protected function createValidItem(array $itemAttributes = [], array $versionAttributes = []): array
    {
        $item = $this->createItem($itemAttributes);
        $version = $this->createPublishedVersion($item, array_merge(['valid_from' => $this->day(-30)], $versionAttributes));

        return [$item, $version];
    }

    /**
     * Items that exist but are not valid today, keyed by the reason, each with
     * the version whose file must not be delivered.
     *
     * @return array<string, array{0: Item, 1: Version}>
     */
    protected function createInvalidItems(): array
    {
        $items = [];

        $item = $this->createItem(['title' => 'Only draft']);
        $items['draft'] = [$item, $this->createVersion($item, ['valid_from' => $this->day(-30)])];

        $item = $this->createItem(['title' => 'Only in review']);
        $version = $this->createVersion($item, ['valid_from' => $this->day(-30)]);
        $this->assertTrue($version->submitForReview('user-2'), json_encode($version->getErrors()));
        $items['in review'] = [$item, $version];

        $item = $this->createItem(['title' => 'Only historical']);
        $items['historical'] = [$item, $this->createPublishedVersion($item, [
            'valid_from' => $this->day(-60),
            'valid_until' => $this->day(-1),
        ])];

        $item = $this->createItem(['title' => 'Only upcoming']);
        $items['upcoming'] = [$item, $this->createPublishedVersion($item, ['valid_from' => $this->day(1)])];

        $item = $this->createItem(['title' => 'Withdrawn, nothing applies']);
        $version = $this->createPublishedVersion($item, ['valid_from' => $this->day(-30)]);
        $this->assertTrue($version->withdraw('Faulty', Version::WITHDRAW_NONE), json_encode($version->getErrors()));
        $items['withdrawn'] = [$item, $version];

        $item = $this->createItem(['title' => 'Archived']);
        $version = $this->createPublishedVersion($item, ['valid_from' => $this->day(-30)]);
        $this->assertTrue($item->archive(), json_encode($item->getErrors()));
        $items['archived'] = [$item, $version];

        return $items;
    }

    /**
     * Creates a file row of the version and writes its content to the
     * storage `fs`.
     */
    protected function createStoredFile(Version $version, string $name, array $attributes = []): File
    {
        $file = $this->createFile($version, array_merge([
            'kind' => File::KIND_MAIN,
            'path' => 'knowledge-library/' . $version->item_id . '/' . uniqid() . '.pdf',
            'name' => $name,
            'mime_type' => 'application/pdf',
            'size' => strlen(static::$fileContent),
            'content_hash' => hash('sha256', static::$fileContent),
        ], $attributes));
        Yii::$app->get('fs')->write($file->path, static::$fileContent);

        return $file;
    }

    /**
     * Reads the range of the stream prepared by `sendStreamAsFile()` and
     * closes the stream.
     */
    protected function readResponseStream(Response $response): string
    {
        $this->assertIsArray($response->stream, 'The response has no stream.');
        [$handle, $begin, $end] = $response->stream;
        try {
            return (string)stream_get_contents($handle, $end - $begin + 1, $begin);
        } finally {
            fclose($handle);
            $response->stream = null;
        }
    }
}
