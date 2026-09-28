<?php

namespace dmstr\knowledgeLibrary\tests\support;

use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * Active record user identity for tests of the DefaultUserProvider, stored in
 * the table created by createTable().
 *
 * @property int $id
 * @property string $uuid
 * @property string $username
 */
class TestUser extends ActiveRecord implements IdentityInterface
{
    public static function tableName()
    {
        return '{{%test_user}}';
    }

    /**
     * Creates the user table in the database of the application.
     */
    public static function createTable(): void
    {
        static::getDb()->createCommand()->createTable(static::tableName(), [
            'id' => 'pk',
            'uuid' => 'string(36) NOT NULL',
            'username' => 'string(255) NOT NULL',
        ])->execute();
    }

    /**
     * Inserts a user with a UUID derived from the ID.
     */
    public static function create(string $username): self
    {
        $user = new static(['username' => $username, 'uuid' => 'pending']);
        $user->save(false);
        $user->updateAttributes(['uuid' => sprintf('00000000-0000-4000-9000-%012d', $user->id)]);

        return $user;
    }

    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return false;
    }
}
