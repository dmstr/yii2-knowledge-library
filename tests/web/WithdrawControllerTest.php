<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the withdrawal page (`version/withdraw`): access, the three ways
 * of what applies instead, validation and the end state in the list, the
 * timeline and the history.
 */
class WithdrawControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLogin(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAsGuest();

        foreach (['GET', 'POST'] as $method) {
            $this->assertNull($this->request($method, 'version/withdraw', ['id' => $current->id], [
                'reason' => 'Wrong',
                'successor' => Version::WITHDRAW_NONE,
            ]));
            $this->assertRedirectsToLogin();
        }
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($current->id)->status);
    }

    public function testUserWithoutRoleIsForbidden(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAs();

        $this->assertForbidden('GET', 'version/withdraw', ['id' => $current->id]);
        $this->assertForbidden('POST', 'version/withdraw', ['id' => $current->id], [
            'reason' => 'Wrong',
            'successor' => Version::WITHDRAW_NONE,
        ]);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($current->id)->status);
    }

    public function testEditorReviewerAndAdminMayWithdraw(): void
    {
        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            [, $current] = $this->twoVersions();
            $this->loginAs($role);

            $this->assertPage($this->get('version/withdraw', ['id' => $current->id]));
            $this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_NONE], ['id' => $current->id]);

            $this->assertRedirectsTo(['item/view', 'id' => $current->item_id]);
            $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($current->id)->status, $role);
        }
    }

    public function testPageShowsReasonOptionsAndPeriod(): void
    {
        [$previous, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/withdraw', ['id' => $current->id]));

        $this->assertSame('Withdraw version 2', Yii::$app->view->title);
        $this->assertStringContainsString('name="reason"', $html);
        $period = Yii::$app->formatter->asDate('2025-01-01') . ' – open-ended';
        $this->assertStringContainsString(Html::encode('What applies instead in the period ' . $period . '?'), $html);
        $this->assertStringContainsString('Version 1 remains valid', $html);
        $this->assertStringContainsString('I will publish a corrected version', $html);
        $this->assertStringContainsString('Nothing applies', $html);
        $this->assertMatchesRegularExpression('#<input type="radio"[^>]*name="successor" value="previous" checked#', $html);
        $this->assertStringContainsString('<input type="hidden" name="previous" value="' . $previous->id . '">', $html);
        $this->assertMatchesRegularExpression('#<button type="submit" id="knowledge-library-withdraw-submit"[^>]*>Withdraw version 2</button>#', $html);
        $this->assertStringContainsString('data-label-correction="Continue to correction"', $html);
    }

    public function testWithoutPreviousVersionThePreviousOptionIsDisabled(): void
    {
        $version = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2020-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/withdraw', ['id' => $version->id]));

        $this->assertStringContainsString('No previous version available', $html);
        $this->assertMatchesRegularExpression('#<input type="radio"[^>]*name="successor" value="previous" disabled#', $html);
        $this->assertMatchesRegularExpression('#<input type="radio"[^>]*name="successor" value="none" checked#', $html);
        $this->assertStringNotContainsString('name="previous"', $html);

        $html = $this->assertPage($this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_PREVIOUS], ['id' => $version->id]));
        $this->assertStringContainsString('There is no previous version.', $html);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
    }

    public function testPreviousVersionRemainsValid(): void
    {
        [$previous, $current] = $this->twoVersions();
        $this->assertSame('2024-12-31', Version::findOne($previous->id)->valid_until);
        $editor = $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/withdraw', [
            'reason' => ' Wrong amount ',
            'successor' => Version::WITHDRAW_PREVIOUS,
            'previous' => $previous->id,
        ], ['id' => $current->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $current->item_id]);
        $this->assertSame('Version 2 withdrawn.', $this->getFlash('success'));
        $withdrawn = Version::findOne($current->id);
        $this->assertSame(Version::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertSame('Wrong amount', $withdrawn->withdraw_reason);
        $this->assertSame($editor->uuid, $withdrawn->withdrawn_by);
        // The previous version takes over the open end of the withdrawn one.
        $this->assertNull(Version::findOne($previous->id)->valid_until);
        $item = Item::findOne($current->item_id);
        $this->assertSame($previous->id, $item->getValidVersion()->id);

        $entry = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_WITHDRAWN]);
        $this->assertSame('Wrong amount', $entry->reason);
        $this->assertSame(1, $entry->getDetails()['successor']);

        $list = $this->assertPage($this->get('item/index', ['ItemSearch' => ['title' => $item->title]]));
        $this->assertStringContainsString(
            '<span>Version 1</span> <span class="knowledge-library-since" style="color: #777">since '
            . Html::encode(Yii::$app->formatter->asDate('2020-01-01')) . '</span>',
            $list
        );

        $view = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('Version 2 withdrawn, version 1 remains valid', $view);
        $this->assertMatchesRegularExpression(
            '#data-number="2">.*?knowledge-library-version-state-withdrawn#s',
            $view
        );
    }

    public function testNothingApplies(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/withdraw', ['reason' => 'Void', 'successor' => Version::WITHDRAW_NONE], ['id' => $current->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $current->item_id]);
        $item = Item::findOne($current->item_id);
        $this->assertNull($item->getValidVersion());
        $this->assertArrayNotHasKey('successor', History::findOne(['item_id' => $item->id, 'action' => History::ACTION_WITHDRAWN])->getDetails());

        $list = $this->assertPage($this->get('item/index', ['ItemSearch' => ['title' => $item->title]]));
        $this->assertMatchesRegularExpression('#<span class="knowledge-library-state knowledge-library-state-none"[^>]*>No valid version</span>#', $list);

        $view = $this->assertPage($this->get('item/view', ['id' => $item->id, 'tab' => 'history']));
        $this->assertStringContainsString('Version 2 withdrawn, no valid version', $view);
        $this->assertStringContainsString('Void', $view);
    }

    public function testCorrectionOptionStartsTheCorrection(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/withdraw', ['reason' => 'Wrong amount', 'successor' => Version::WITHDRAW_CORRECTION], ['id' => $current->id]);

        $correction = Version::findOne(['corrects_version_id' => $current->id]);
        $this->assertNotNull($correction);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 1]);
        $this->assertSame(Version::STATUS_DRAFT, $correction->status);
        // The faulty version stays published until the correction is published.
        $faulty = Version::findOne($current->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $faulty->status);
        $this->assertSame('Wrong amount', $faulty->withdraw_reason);
    }

    public function testReasonIsRequired(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([Version::WITHDRAW_NONE, Version::WITHDRAW_CORRECTION] as $successor) {
            $html = $this->assertPage($this->post('version/withdraw', ['reason' => '  ', 'successor' => $successor], ['id' => $current->id]));

            $this->assertStringContainsString('Enter a reason for the withdrawal.', $html, $successor);
        }
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($current->id)->status);
        $this->assertNull(Version::findOne(['corrects_version_id' => $current->id]));
    }

    public function testInvalidSuccessorAndChangedPreviousAreRejected(): void
    {
        [$previous, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => 'later'], ['id' => $current->id]));
        $this->assertStringContainsString('Select what applies instead.', $html);

        $html = $this->assertPage($this->post('version/withdraw', [
            'reason' => 'Wrong',
            'successor' => Version::WITHDRAW_PREVIOUS,
            'previous' => self::UNKNOWN_ID,
        ], ['id' => $current->id]));
        $this->assertStringContainsString('The previous version has changed meanwhile.', $html);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($current->id)->status);
        $this->assertSame('2024-12-31', Version::findOne($previous->id)->valid_until);
    }

    public function testHistoricalAndWithdrawnVersionsRedirectWithMessage(): void
    {
        [$previous, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertNull(Version::findOne($previous->id)->withdrawn_at);
        foreach (['GET', 'POST'] as $method) {
            $this->request($method, 'version/withdraw', ['id' => $previous->id], ['reason' => 'Old', 'successor' => Version::WITHDRAW_NONE]);
            $this->assertRedirectsTo(['item/view', 'id' => $previous->item_id, 'tab' => 'versions']);
            $this->assertSame('Only a version in force or an upcoming version can be withdrawn.', $this->getFlash('error'));
        }
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($previous->id)->status);

        $this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_NONE], ['id' => $current->id]);
        $this->get('version/withdraw', ['id' => $current->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $current->item_id, 'tab' => 'versions']);
    }

    public function testDraftsAndUnknownVersionsAreNotFound(): void
    {
        $item = $this->periodItem();
        $draft = $this->createVersion($item);
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([$draft->id, self::UNKNOWN_ID] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'version/withdraw', ['id' => $id]);
        }
    }

    public function testUpcomingVersionCanBeWithdrawn(): void
    {
        [$previous] = $this->twoVersions();
        $upcoming = $this->createPublishedVersion(Item::findOne($previous->item_id), ['valid_from' => '2099-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/withdraw', ['reason' => 'Too early', 'successor' => Version::WITHDRAW_PREVIOUS], ['id' => $upcoming->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $upcoming->item_id]);
        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($upcoming->id)->status);
    }

    public function testTypeWithoutValidityPeriodOffersTwoWays(): void
    {
        $type = $this->createType(['has_validity_period' => false, 'requires_review' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $first = $this->createPublishedVersion($item);
        $second = $this->createPublishedVersion($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/withdraw', ['id' => $second->id]));
        $this->assertStringContainsString('Version 1 remains valid', $html);
        $this->assertStringContainsString('I will publish a corrected version', $html);
        $this->assertStringNotContainsString('Nothing applies', $html);

        $html = $this->assertPage($this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_NONE], ['id' => $second->id]));
        $this->assertStringContainsString('Version 1 remains valid, as this type has no validity period.', $html);

        $this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_PREVIOUS, 'previous' => $first->id], ['id' => $second->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame($first->id, Item::findOne($item->id)->getValidVersion()->id);

        // The last version has no previous version: nothing applies.
        $html = $this->assertPage($this->get('version/withdraw', ['id' => $first->id]));
        $this->assertStringContainsString('No previous version available', $html);
        $this->assertStringContainsString('Nothing applies', $html);
    }

    public function testArchivedItemAllowsWithdrawalButNoCorrection(): void
    {
        [$previous, $current] = $this->twoVersions();
        $this->assertTrue(Item::findOne($current->item_id)->archive());
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/withdraw', ['id' => $current->id]));
        $this->assertMatchesRegularExpression('#<input type="radio"[^>]*name="successor" value="correction" disabled#', $html);

        $this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_CORRECTION], ['id' => $current->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $current->item_id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));
        $this->assertNull(Version::findOne(['corrects_version_id' => $current->id]));

        $this->post('version/withdraw', ['reason' => 'Wrong', 'successor' => Version::WITHDRAW_PREVIOUS, 'previous' => $previous->id], ['id' => $current->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $current->item_id]);
        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($current->id)->status);
    }

    public function testVersionsTabLinksWithdrawForVersionsInForceAndUpcoming(): void
    {
        [$previous, $current] = $this->twoVersions();
        $upcoming = $this->createPublishedVersion(Item::findOne($previous->item_id), ['valid_from' => '2099-01-01']);

        $this->loginAs(Module::ROLE_EDITOR);
        $html = $this->assertPage($this->get('item/view', ['id' => $current->item_id]));
        foreach ([$current, $upcoming] as $version) {
            $this->assertStringContainsString(
                'href="' . Html::encode(Url::to(['/knowledge-library/version/withdraw', 'id' => $version->id])) . '"',
                $html
            );
        }
        $this->assertStringNotContainsString(
            Html::encode(Url::to(['/knowledge-library/version/withdraw', 'id' => $previous->id])),
            $html
        );
    }

    public function testWithdrawPageIsTranslated(): void
    {
        [, $current] = $this->twoVersions();
        $this->loginAs(Module::ROLE_EDITOR);
        Yii::$app->language = 'de';

        $html = $this->assertPage($this->get('version/withdraw', ['id' => $current->id]));

        $this->assertSame('Version 2 zurückziehen', Yii::$app->view->title);
        foreach (['Begründung', 'Was gilt stattdessen im Zeitraum', 'Version 1 gilt weiter', 'Ich veröffentliche eine korrigierte Version', 'Nichts gilt', 'Weiter zur Korrektur'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    /**
     * Item of a type with validity period and without review, with version 1
     * from 2020-01-01 (historical) and version 2 from 2025-01-01, open-ended
     * (in force).
     *
     * @return Version[]
     */
    private function twoVersions(): array
    {
        $item = $this->periodItem();
        $previous = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $current = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);

        return [$previous, $current];
    }

    private function periodItem(): Item
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);

        return $this->createItem(['type_id' => $type->id]);
    }
}
