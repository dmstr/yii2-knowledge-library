<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Version;
use yii\helpers\Url;

/**
 * Builds the JSON representation of items, versions and files returned by the
 * tools.
 *
 * Dates are `Y-m-d`, timestamps `Y-m-d H:i:s` as stored, empty strings are
 * null. The text of a version is returned as Markdown source, not rendered.
 */
class ItemPresenter
{
    public function __construct(private Module $module)
    {
    }

    /**
     * Summary of an item with its valid version, as listed by the search.
     */
    public function summary(Item $item, Version $version): array
    {
        $topics = array_map(static fn (Topic $topic): string => (string)$topic->name, $item->topics);
        sort($topics);

        $hasValidityPeriod = $item->type === null || $item->type->has_validity_period;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'type' => $item->type?->name,
            'topics' => $topics,
            'summary' => self::text($item->summary),
            'valid_from' => $hasValidityPeriod ? $version->valid_from : null,
            'valid_until' => $hasValidityPeriod ? $version->valid_until : null,
            'published_at' => self::date($version->published_at),
            'version' => (int)$version->number,
            'updated_at' => max((string)$item->updated_at, (string)$version->updated_at) ?: null,
        ];
    }

    /**
     * Full representation of an item with its valid version: the summary,
     * the source, the Markdown text, the files and the relations to other
     * items the server shows.
     *
     * @param string $date the date the version is valid at, `Y-m-d`
     */
    public function details(Item $item, Version $version, string $date): array
    {
        $data = $this->summary($item, $version);
        $data['source'] = [
            'name' => self::text($item->source_name),
            'reference' => self::text($item->source_reference),
            'url' => self::text($item->source_url),
        ];
        $data['content'] = self::text($version->content);
        $data['files'] = array_map([$this, 'file'], $version->files);

        $data['relations'] = [];
        foreach ($item->findVisibleRelations($date) as $label => $relatedItems) {
            foreach ($relatedItems as $related) {
                $data['relations'][] = [
                    'relation' => $label,
                    'id' => $related->id,
                    'title' => $related->title,
                ];
            }
        }

        return $data;
    }

    /**
     * A file with the URL of its download through the module.
     */
    public function file(File $file): array
    {
        return [
            'id' => $file->id,
            'kind' => $file->kind,
            'title' => self::text($file->title),
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size === null ? null : (int)$file->size,
            'download_url' => $this->downloadUrl($file),
        ];
    }

    /**
     * Absolute URL of the file download of the module, usable with the same
     * credentials as the MCP endpoint.
     */
    public function downloadUrl(File $file): string
    {
        return Url::to(['/' . $this->module->getUniqueId() . '/file/download', 'id' => $file->id], true);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private static function date(?string $dateTime): ?string
    {
        return $dateTime === null || $dateTime === '' ? null : substr($dateTime, 0, 10);
    }
}
