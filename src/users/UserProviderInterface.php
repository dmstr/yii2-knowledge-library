<?php

namespace dmstr\knowledgeLibrary\users;

/**
 * Decouples the knowledge library from a concrete user implementation.
 *
 * Users are stored as opaque string references (max. 64 characters) in the
 * `*_by` and `*_id` user columns. The provider translates between the
 * application's user model and these references.
 *
 * Register a custom implementation in the DI container or via the module
 * property `userProvider`. It is resolved in one place, see
 * {@see DefaultUserProvider::resolve()}.
 */
interface UserProviderInterface
{
    /**
     * Reference of the currently logged-in user, null for guests and in
     * contexts without a web user (e.g. console).
     */
    public function getCurrentUserReference(): ?string;

    /**
     * Human-readable name of the user with the given reference, null if the
     * user cannot be found.
     */
    public function getDisplayName(string $reference): ?string;

    /**
     * Users that may be chosen as reviewer, as map `reference => display name`.
     *
     * Implementations return the users allowed to review, e.g. users with the
     * reviewer or admin role of the module. The current user is not excluded
     * here: the review form and Version::submitForReview() reject choosing
     * oneself (four-eyes principle).
     *
     * @return array<string, string>
     */
    public function getReviewerOptions(): array;
}
