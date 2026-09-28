<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;

/**
 * Tests of Version::withdraw() and Version::getPreviousForWithdrawal().
 *
 * Dates are relative to today, as the effective state of a version depends
 * on the current date.
 */
class WithdrawTest extends TestCase
{
    public function testWithdrawWithPreviousExtendsThePreviousVersion(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $this->assertSame($this->day(-11), Version::findOne($first->id)->valid_until);

        $this->assertTrue(
            $second->withdraw('  Wrong figures  ', Version::WITHDRAW_PREVIOUS),
            json_encode($second->getErrors())
        );

        $second = Version::findOne($second->id);
        $this->assertSame(Version::STATUS_WITHDRAWN, $second->status);
        $this->assertSame('Wrong figures', $second->withdraw_reason);
        $this->assertSame('user-1', $second->withdrawn_by);
        $this->assertNotNull($second->withdrawn_at);
        $this->assertNull(Version::findOne($first->id)->valid_until);
        $this->assertSame($first->id, Item::findOne($item->id)->getValidVersion()->id);

        $history = History::findOne(['version_id' => $second->id, 'action' => History::ACTION_WITHDRAWN]);
        $this->assertSame('Wrong figures', $history->reason);
        $this->assertSame(['number' => 2, 'successor' => 1], $history->getDetails());
        $this->assertSame(
            'Version 2 withdrawn, version 1 remains valid',
            $history->describe(new DummyUserProvider())
        );
    }

    public function testWithdrawWithPreviousTakesOverValidUntil(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, [
            'valid_from' => $this->day(-10),
            'valid_until' => $this->day(100),
        ]);

        $this->assertTrue($second->withdraw('Reason', Version::WITHDRAW_PREVIOUS));

        $this->assertSame($this->day(100), Version::findOne($first->id)->valid_until);
    }

    public function testWithdrawUpcomingVersion(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $upcoming = $this->createPublishedVersion($item, ['valid_from' => $this->day(30)]);
        $this->assertSame(Version::STATE_UPCOMING, $upcoming->getEffectiveState());
        $this->assertTrue($upcoming->canWithdraw());

        $this->assertTrue($upcoming->withdraw('Reason', Version::WITHDRAW_PREVIOUS, Version::findOne($first->id)));

        $this->assertNull(Version::findOne($first->id)->valid_until);
        $this->assertSame($first->id, Item::findOne($item->id)->getValidVersion($this->day(60))->id);
    }

    public function testWithdrawWithNothingValid(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);

        $this->assertTrue($second->withdraw('Reason', Version::WITHDRAW_NONE));

        $this->assertSame($this->day(-11), Version::findOne($first->id)->valid_until);
        $this->assertNull(Item::findOne($item->id)->getValidVersion());

        $history = History::findOne(['version_id' => $second->id, 'action' => History::ACTION_WITHDRAWN]);
        $this->assertSame(['number' => 2], $history->getDetails());
        $this->assertSame('Version 2 withdrawn, no valid version', $history->describe(new DummyUserProvider()));
    }

    public function testOnlyVersionsInForceOrUpcomingCanBeWithdrawn(): void
    {
        $item = $this->createItem();
        $historical = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $historical = Version::findOne($historical->id);
        $draft = $this->createVersion($item, ['valid_from' => $this->day(10)]);

        foreach ([$historical, $draft] as $version) {
            $this->assertFalse($version->canWithdraw());
            $this->assertFalse($version->withdraw('Reason', Version::WITHDRAW_NONE));
            $this->assertSame(
                ['Only a version in force or an upcoming version can be withdrawn.'],
                $version->getErrors('status')
            );
        }

        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($historical->id)->status);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
        $this->assertSame(0, (int)History::find()->where(['action' => History::ACTION_WITHDRAWN])->count());
    }

    public function testWithdrawnVersionCannotBeWithdrawnAgain(): void
    {
        $version = $this->createPublishedVersion($this->createItem(), ['valid_from' => $this->day(-10)]);
        $this->assertTrue($version->withdraw('Reason', Version::WITHDRAW_NONE));

        $this->assertFalse($version->withdraw('Reason', Version::WITHDRAW_NONE));
        $this->assertTrue($version->hasErrors('status'));
    }

    public function testReasonIsRequired(): void
    {
        $version = $this->createPublishedVersion($this->createItem(), ['valid_from' => $this->day(-10)]);

        foreach (['', "  \n "] as $reason) {
            $this->assertFalse($version->withdraw($reason, Version::WITHDRAW_NONE));
            $this->assertSame(['Enter a reason for the withdrawal.'], $version->getErrors('withdraw_reason'));
            $version->clearErrors();
        }

        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
    }

    public function testInvalidSuccessorIsRejected(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $version = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);

        $this->assertFalse($version->withdraw('Reason', Version::WITHDRAW_CORRECTION));
        $this->assertSame(
            ['A correction withdraws this version when it is published.'],
            $version->getErrors('successor')
        );
        $version->clearErrors();

        $this->assertFalse($version->withdraw('Reason', 'everything'));
        $this->assertSame(['Select what applies instead.'], $version->getErrors('successor'));

        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
    }

    public function testPreviousIsRequiredForPrevious(): void
    {
        $version = $this->createPublishedVersion($this->createItem(), ['valid_from' => $this->day(-10)]);
        $this->assertNull($version->getPreviousForWithdrawal());

        $this->assertFalse($version->withdraw('Reason', Version::WITHDRAW_PREVIOUS));
        $this->assertSame(['There is no previous version.'], $version->getErrors('successor'));

        $this->assertTrue($version->withdraw('Reason', Version::WITHDRAW_NONE));
    }

    public function testGivenPreviousMustBeThePreviousVersion(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $third = $this->createPublishedVersion($item, ['valid_from' => $this->day(30)]);

        $this->assertFalse($third->withdraw('Reason', Version::WITHDRAW_PREVIOUS, $first));
        $this->assertSame(['The previous version has changed meanwhile.'], $third->getErrors('successor'));

        $this->assertTrue($third->withdraw('Reason', Version::WITHDRAW_PREVIOUS, $second));
        $this->assertNull(Version::findOne($second->id)->valid_until);
        $this->assertSame($this->day(-11), Version::findOne($first->id)->valid_until);
    }

    public function testPreviousForWithdrawalOfTypeWithValidityPeriod(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-200)]);
        $third = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $fourth = $this->createPublishedVersion($item, ['valid_from' => $this->day(30)]);

        $this->assertSame($third->id, $fourth->getPreviousForWithdrawal()->id);
        $this->assertSame($second->id, $third->getPreviousForWithdrawal()->id);
        $this->assertNull($first->getPreviousForWithdrawal());

        // Withdrawn versions do not apply again.
        $this->assertTrue($third->withdraw('Reason', Version::WITHDRAW_NONE));
        $this->assertSame($second->id, Version::findOne($fourth->id)->getPreviousForWithdrawal()->id);
    }

    public function testTypeWithoutValidityPeriod(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $first = $this->createPublishedVersion($item);
        $second = $this->createPublishedVersion($item);

        $this->assertFalse(Version::findOne($first->id)->canWithdraw());
        $this->assertSame($first->id, $second->getPreviousForWithdrawal()->id);

        // The previous version becomes valid anyway, "nothing applies" is not
        // possible.
        $this->assertFalse($second->withdraw('Reason', Version::WITHDRAW_NONE));
        $this->assertSame(
            ['Version 1 remains valid, as this type has no validity period.'],
            $second->getErrors('successor')
        );
        $second->clearErrors();

        $this->assertTrue($second->withdraw('Reason', Version::WITHDRAW_PREVIOUS));

        $first = Version::findOne($first->id);
        $this->assertNull($first->valid_from);
        $this->assertNull($first->valid_until);
        $this->assertSame(Version::STATE_IN_FORCE, $first->getEffectiveState());
        $this->assertSame($first->id, Item::findOne($item->id)->getValidVersion()->id);
        $history = History::findOne(['version_id' => $second->id, 'action' => History::ACTION_WITHDRAWN]);
        $this->assertSame(['number' => 2, 'successor' => 1], $history->getDetails());

        // Without previous version nothing applies.
        $this->assertFalse($first->withdraw('Reason', Version::WITHDRAW_PREVIOUS));
        $this->assertSame(['There is no previous version.'], $first->getErrors('successor'));
        $first->clearErrors();
        $this->assertTrue($first->withdraw('Reason', Version::WITHDRAW_NONE));
        $this->assertNull(Item::findOne($item->id)->getValidVersion());
    }

    public function testArchivedItemAllowsWithdrawal(): void
    {
        $item = $this->createItem();
        $version = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        $this->assertTrue($item->archive());

        $version = Version::findOne($version->id);
        $this->assertTrue($version->withdraw('Reason', Version::WITHDRAW_NONE), json_encode($version->getErrors()));
    }

    public function testCorrectionInProgressBlocksWithdrawal(): void
    {
        $version = $this->createPublishedVersion($this->createItem(), ['valid_from' => $this->day(-10)]);
        $correction = Version::createCorrection($version);
        $this->assertFalse($correction->getIsNewRecord());

        $this->assertFalse($version->canWithdraw());
        $this->assertFalse($version->withdraw('Reason', Version::WITHDRAW_NONE));
        $this->assertSame(['A correction of this version is in progress.'], $version->getErrors('status'));

        $correction->delete();
        $this->assertTrue($version->withdraw('Reason', Version::WITHDRAW_NONE));
    }

    public function testFailedWithdrawalWritesNothing(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => $this->day(-400)]);
        $second = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10)]);
        // Makes the validation of the withdrawn version fail.
        $second->valid_until = 'invalid';

        $this->assertFalse($second->withdraw('Reason', Version::WITHDRAW_PREVIOUS));

        $this->assertSame(Version::STATUS_PUBLISHED, $second->status);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($second->id)->status);
        $this->assertSame($this->day(-11), Version::findOne($first->id)->valid_until);
        $this->assertSame(0, (int)History::find()->where(['action' => History::ACTION_WITHDRAWN])->count());
    }

    /**
     * Date relative to today in the format `Y-m-d`.
     */
    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }
}
