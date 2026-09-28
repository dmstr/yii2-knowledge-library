<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\TestUser;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use dmstr\knowledgeLibrary\users\DefaultUserProvider;
use Yii;
use yii\helpers\ArrayHelper;

/**
 * Tests of the reviewer options of the DefaultUserProvider: users with a
 * direct assignment of the reviewer or the admin role.
 */
class ReviewerOptionsTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestUser::createTable();
    }

    protected function applicationConfig(): array
    {
        return ArrayHelper::merge(parent::applicationConfig(), [
            'components' => [
                'user' => [
                    'identityClass' => TestUser::class,
                ],
            ],
        ]);
    }

    public function testOptionsContainReviewersAndAdminsOnly(): void
    {
        $editor = $this->createUser('editor', Module::ROLE_EDITOR);
        $reviewer = $this->createUser('reviewer', Module::ROLE_REVIEWER);
        $admin = $this->createUser('admin', Module::ROLE_ADMIN);
        $this->createUser('nobody');
        $both = $this->createUser('both', Module::ROLE_REVIEWER);
        Yii::$app->getAuthManager()->assign(Yii::$app->getAuthManager()->getRole(Module::ROLE_ADMIN), $both->id);

        $options = (new DefaultUserProvider())->getReviewerOptions();

        $this->assertSame([
            $admin->uuid => 'admin',
            $both->uuid => 'both',
            $reviewer->uuid => 'reviewer',
        ], $options);
        $this->assertArrayNotHasKey($editor->uuid, $options);
    }

    public function testOptionsAreEmptyWithoutReviewers(): void
    {
        $this->createUser('editor', Module::ROLE_EDITOR);

        $this->assertSame([], (new DefaultUserProvider())->getReviewerOptions());
    }

    public function testOptionsContainAllUsersWithoutAuthManager(): void
    {
        $first = $this->createUser('first');
        $second = $this->createUser('second', Module::ROLE_EDITOR);
        Yii::$app->set('authManager', null);

        $this->assertSame(
            [$first->uuid => 'first', $second->uuid => 'second'],
            (new DefaultUserProvider())->getReviewerOptions()
        );
    }

    private function createUser(string $username, ?string $role = null): TestUser
    {
        $user = TestUser::create($username);
        if ($role !== null) {
            $authManager = Yii::$app->getAuthManager();
            $authManager->assign($authManager->getRole($role), $user->id);
        }

        return $user;
    }
}
