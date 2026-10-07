<?php
// file generated with AI assistance: Claude Code - 2026-10-07 19:33:22 UTC

namespace dmstr\knowledgeLibrary\models;

use Yii;
use yii\db\ActiveQuery;

/**
 * File of a version, stored in the configured file storage.
 *
 * A main file carries the content of the version (e.g. a PDF), attachments
 * are supplementary material. Both may have a title, shown instead of the
 * file name.
 *
 * @property string $id
 * @property string $version_id
 * @property string $kind
 * @property string|null $title
 * @property string $storage_id
 * @property string|null $storage_item_id
 * @property string $path
 * @property string $name
 * @property string|null $mime_type
 * @property int|null $size
 * @property string|null $content_hash SHA-256 hex digest of the content
 * @property int $position
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 *
 * @property-read Version|null $version
 */
class File extends ActiveRecord
{
    public const KIND_MAIN = 'main';
    public const KIND_ATTACHMENT = 'attachment';

    public static function tableName()
    {
        return '{{%knowledge_library_file}}';
    }

    /**
     * @return array<string, string> map `kind => label`
     */
    public static function kinds(): array
    {
        return [
            self::KIND_MAIN => Yii::t('knowledge-library', 'Main File'),
            self::KIND_ATTACHMENT => Yii::t('knowledge-library', 'Attachment'),
        ];
    }

    public function rules()
    {
        return [
            ['version_id', 'required'],
            ['version_id', 'exist', 'targetClass' => Version::class, 'targetAttribute' => 'id'],
            ['kind', 'default', 'value' => self::KIND_ATTACHMENT],
            ['kind', 'in', 'range' => array_keys(static::kinds())],
            ['title', 'trim'],
            ['title', 'default', 'value' => null],
            [['title', 'name', 'mime_type'], 'string', 'max' => 255],
            [['storage_id', 'path', 'name'], 'required'],
            ['storage_id', 'string', 'max' => 255],
            ['storage_item_id', 'string', 'max' => 36],
            ['path', 'string', 'max' => 1024],
            ['size', 'integer', 'min' => 0],
            ['content_hash', 'match', 'pattern' => '/^[0-9a-f]{64}$/'],
            ['position', 'default', 'value' => 0],
            ['position', 'integer'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => Yii::t('knowledge-library', 'ID'),
            'version_id' => Yii::t('knowledge-library', 'Version'),
            'kind' => Yii::t('knowledge-library', 'Kind'),
            'title' => Yii::t('knowledge-library', 'Title'),
            'storage_id' => Yii::t('knowledge-library', 'Storage'),
            'storage_item_id' => Yii::t('knowledge-library', 'Storage Item'),
            'path' => Yii::t('knowledge-library', 'Path'),
            'name' => Yii::t('knowledge-library', 'File Name'),
            'mime_type' => Yii::t('knowledge-library', 'MIME Type'),
            'size' => Yii::t('knowledge-library', 'Size'),
            'content_hash' => Yii::t('knowledge-library', 'Content Hash'),
            'position' => Yii::t('knowledge-library', 'Position'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    /**
     * Title of the file, the file name if it has none.
     */
    public function getDisplayName(): string
    {
        return trim((string)$this->title) !== '' ? (string)$this->title : (string)$this->name;
    }

    /**
     * Copies this file row to another version. The copy points to the same
     * stored file (same storage and path), the storage is not touched.
     *
     * @return File the saved copy, or the unsaved copy with errors
     */
    public function copyToVersion(Version $target): File
    {
        $copy = new static();
        $copy->setAttributes([
            'version_id' => $target->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'storage_id' => $this->storage_id,
            'storage_item_id' => $this->storage_item_id,
            'path' => $this->path,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'content_hash' => $this->content_hash,
            'position' => $this->position,
        ], false);
        $copy->save();

        return $copy;
    }

    /**
     * Whether the file belongs to the published version valid at the date of
     * an active item, i.e. is delivered to readers.
     *
     * @param string|null $date date in the format `Y-m-d`, today if null
     */
    public function belongsToValidVersion(?string $date = null): bool
    {
        $version = $this->version;
        $item = $version?->item;
        if ($version === null || $item === null) {
            return false;
        }
        if ($version->status !== Version::STATUS_PUBLISHED || $item->is_archived) {
            return false;
        }

        return $item->getValidVersion($date)?->id === $this->version_id;
    }

    public function getVersion(): ActiveQuery
    {
        return $this->hasOne(Version::class, ['id' => 'version_id']);
    }
}
