<?php

namespace dmstr\knowledgeLibrary\users;

use Yii;
use yii\db\ActiveRecordInterface;
use yii\db\BaseActiveRecord;
use yii\web\IdentityInterface;
use yii\web\User;

/**
 * Default user provider working on the application's `user` component and its
 * identity class.
 *
 * The reference of a user is its `uuid` attribute if the identity has one,
 * otherwise its ID. The display name is the `username` attribute if present,
 * otherwise the reference. No specific user module is required.
 */
class DefaultUserProvider implements UserProviderInterface
{
    public const UUID_ATTRIBUTE = 'uuid';
    public const DISPLAY_ATTRIBUTE = 'username';

    /**
     * Returns the user provider registered in the DI container, falling back to
     * this default implementation.
     *
     * This is the single place where the provider is resolved.
     */
    public static function resolve(): UserProviderInterface
    {
        if (Yii::$container->has(UserProviderInterface::class)) {
            return Yii::$container->get(UserProviderInterface::class);
        }

        return new static();
    }

    public function getCurrentUserReference(): ?string
    {
        if (Yii::$app === null || !Yii::$app->has('user')) {
            return null;
        }

        $user = Yii::$app->get('user');
        if (!$user instanceof User) {
            return null;
        }

        $identity = $user->getIdentity(false);

        return $identity === null ? null : $this->getReference($identity);
    }

    public function getDisplayName(string $reference): ?string
    {
        $identity = $this->findIdentity($reference);

        return $identity === null ? null : $this->getIdentityDisplayName($identity, $reference);
    }

    public function getReviewerOptions(): array
    {
        $class = $this->getIdentityClass();
        if ($class === null || !is_subclass_of($class, BaseActiveRecord::class)) {
            return [];
        }

        $query = $class::find();
        if ($this->hasColumn($class, static::DISPLAY_ATTRIBUTE)) {
            $query->orderBy([static::DISPLAY_ATTRIBUTE => SORT_ASC]);
        }

        $options = [];
        foreach ($query->all() as $identity) {
            $reference = $this->getReference($identity);
            $options[$reference] = $this->getIdentityDisplayName($identity, $reference);
        }

        return $options;
    }

    /**
     * Reference of the given identity: its UUID if available, else its ID.
     */
    protected function getReference(IdentityInterface $identity): string
    {
        $uuid = null;
        if ($identity instanceof BaseActiveRecord) {
            if ($identity->hasAttribute(static::UUID_ATTRIBUTE)) {
                $uuid = $identity->getAttribute(static::UUID_ATTRIBUTE);
            }
        } elseif (isset($identity->{static::UUID_ATTRIBUTE})) {
            $uuid = $identity->{static::UUID_ATTRIBUTE};
        }

        if ($uuid !== null && $uuid !== '') {
            return (string)$uuid;
        }

        return (string)$identity->getId();
    }

    protected function getIdentityDisplayName(IdentityInterface $identity, string $reference): string
    {
        $name = null;
        if ($identity instanceof BaseActiveRecord) {
            if ($identity->hasAttribute(static::DISPLAY_ATTRIBUTE)) {
                $name = $identity->getAttribute(static::DISPLAY_ATTRIBUTE);
            }
        } elseif (isset($identity->{static::DISPLAY_ATTRIBUTE})) {
            $name = $identity->{static::DISPLAY_ATTRIBUTE};
        }

        return $name !== null && $name !== '' ? (string)$name : $reference;
    }

    protected function findIdentity(string $reference): ?IdentityInterface
    {
        $class = $this->getIdentityClass();
        if ($class === null) {
            return null;
        }

        if (is_subclass_of($class, BaseActiveRecord::class)) {
            /** @var ActiveRecordInterface|null $identity */
            $identity = $this->hasColumn($class, static::UUID_ATTRIBUTE)
                ? $class::findOne([static::UUID_ATTRIBUTE => $reference])
                : $class::findOne($reference);
        } else {
            $identity = $class::findIdentity($reference);
        }

        return $identity instanceof IdentityInterface ? $identity : null;
    }

    /**
     * Identity class of the application's user component, null if unavailable.
     *
     * @return class-string<IdentityInterface>|null
     */
    protected function getIdentityClass(): ?string
    {
        if (Yii::$app === null || !Yii::$app->has('user')) {
            return null;
        }

        $user = Yii::$app->get('user');
        if (!$user instanceof User || empty($user->identityClass) || !class_exists($user->identityClass)) {
            return null;
        }

        return $user->identityClass;
    }

    protected function hasColumn(string $class, string $column): bool
    {
        if (!method_exists($class, 'getTableSchema')) {
            return false;
        }

        $schema = $class::getTableSchema();

        return $schema !== null && $schema->getColumn($column) !== null;
    }
}
