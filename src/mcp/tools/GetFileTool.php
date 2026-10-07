<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\mcp\tools;

use dmstr\knowledgeLibrary\files\FileService;
use dmstr\knowledgeLibrary\mcp\ToolException;
use dmstr\knowledgeLibrary\models\File;

/**
 * `knowledge_get_file`: the content of a file of a version valid today, as
 * embedded resource.
 */
class GetFileTool extends BaseTool
{
    public const NAME = 'knowledge_get_file';

    public function getDefinition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Fetch a file of a knowledge object',
            'description' => 'Returns the content of a file of a knowledge object valid today (ID from '
                . 'knowledge_get_item) as embedded resource: text files as text, other files such as PDF as '
                . 'base64 blob with their MIME type. Files larger than ' . $this->module->maxInlineFileSize
                . ' bytes are not returned inline; the result then names the download URL of the file.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ID of the file (from knowledge_get_item)',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            'annotations' => $this->readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): array
    {
        $id = $this->stringArgument($arguments, 'id', true);

        $file = File::find()
            ->andWhere([File::tableName() . '.[[id]]' => $id])
            ->with('version.item')
            ->one();
        if ($file === null || !$file->belongsToValidVersion($this->today())) {
            throw new ToolException("No file with the ID \"$id\" belongs to a valid knowledge object.");
        }

        $meta = $this->presenter()->file($file);
        $meta['item'] = [
            'id' => $file->version->item->id,
            'title' => $file->version->item->title,
        ];

        $maxSize = $this->module->maxInlineFileSize;
        if ((int)$file->size > $maxSize) {
            throw new ToolException($this->tooLargeMessage($meta, (int)$file->size));
        }

        // Through the service like the downloads, so the storage named by the
        // file row is used and read errors are logged in one place.
        $stream = (new FileService($this->module->getBackendModule()))->readStream($file);
        if ($stream === null) {
            throw new ToolException("The stored file of \"{$file->name}\" is missing or could not be read.");
        }
        try {
            $content = (string)stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (strlen($content) > $maxSize) {
            throw new ToolException($this->tooLargeMessage($meta, strlen($content)));
        }

        $mimeType = $file->mime_type ?: 'application/octet-stream';
        $resource = [
            'uri' => $meta['download_url'],
            'mimeType' => $mimeType,
        ];
        if (str_starts_with($mimeType, 'text/') && mb_check_encoding($content, 'UTF-8')) {
            $resource['text'] = $content;
        } else {
            $resource['blob'] = base64_encode($content);
        }

        return [
            'content' => [['type' => 'resource', 'resource' => $resource]],
            'structuredContent' => $meta,
        ];
    }

    private function tooLargeMessage(array $meta, int $size): string
    {
        return sprintf(
            'The file "%s" has %d bytes, more than the %d bytes returned inline. Download it from %s with the same credentials.',
            $meta['name'],
            $size,
            $this->module->maxInlineFileSize,
            $meta['download_url']
        );
    }
}
