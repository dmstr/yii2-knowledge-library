<?php

namespace dmstr\knowledgeLibrary\tests\support;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * Minimal user identity for web tests, kept in an in-memory registry.
 */
class TestIdentity extends BaseObject implements IdentityInterface
{
    public int $id;

    public string $uuid;

    public string $username;

    /**
     * @var array<int, self>
     */
    private static array $identities = [];

    /**
     * Creates and registers an identity with the next free ID.
     */
    public static function create(string $username): self
    {
        $id = count(self::$identities) + 1;
        $identity = new self([
            'id' => $id,
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'username' => $username,
        ]);
        self::$identities[$id] = $identity;

        return $identity;
    }

    /**
     * Removes all registered identities.
     */
    public static function reset(): void
    {
        self::$identities = [];
    }

    public static function findIdentity($id)
    {
        return self::$identities[(int)$id] ?? null;
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
