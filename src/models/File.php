<?php

namespace dmstr\knowledgeLibrary\models;

use Yii;
use yii\db\ActiveQuery;

/**
 * File of a version, stored in the configured file storage.
 *
 * A main file carries the content of the version (e.g. a PDF), attachments
 * are supplementary material.
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
            [['title', 'name', 'mime_type'], 'string', 'max' => 255],
            [['storage_id', 'path', 'name'], 'required'],
            ['storage_id', 'string', 'max' => 255],
            ['storage_item_id', 'string', 'max' => 36],
            ['path', 'string', 'max' => 1024],
            ['size', 'integer', 'min' => 0],
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
            'position' => Yii::t('knowledge-library', 'Position'),
            'created_at' => Yii::t('knowledge-library', 'Created At'),
            'updated_at' => Yii::t('knowledge-library', 'Updated At'),
            'created_by' => Yii::t('knowledge-library', 'Created By'),
            'updated_by' => Yii::t('knowledge-library', 'Updated By'),
        ];
    }

    public function getVersion(): ActiveQuery
    {
        return $this->hasOne(Version::class, ['id' => 'version_id']);
    }
}
