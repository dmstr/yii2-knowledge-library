<?php

namespace dmstr\knowledgeLibrary\tests\support;

use dmstr\knowledgeLibrary\users\UserProviderInterface;

/**
 * User provider for tests with a settable current user.
 */
class DummyUserProvider implements UserProviderInterface
{
    public const DEFAULT_REFERENCE = 'user-1';

    public static ?string $currentReference = self::DEFAULT_REFERENCE;

    public function getCurrentUserReference(): ?string
    {
        return static::$currentReference;
    }

    public function getDisplayName(string $reference): ?string
    {
        return 'User ' . $reference;
    }

    public function getReviewerOptions(): array
    {
        return [
            'user-1' => 'User user-1',
            'user-2' => 'User user-2',
        ];
    }
}
