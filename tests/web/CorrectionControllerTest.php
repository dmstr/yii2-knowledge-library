<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\controllers\VersionController;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of corrections in the backend (`version/correct`, the wizard of a
 * correction and the action "Correct" of the tab versions).
 */
class CorrectionControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLogin(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->loginAsGuest();

        $this->assertNull($this->post('version/correct', ['reason' => 'Wrong'], ['id' => $faulty->id]));
        $this->assertRedirectsToLogin();
        $this->assertNull(Version::findOne(['corrects_version_id' => $faulty->id]));
    }

    public function testUserWithoutRoleIsForbidden(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->loginAs();

        $this->assertForbidden('POST', 'version/correct', ['id' => $faulty->id], ['reason' => 'Wrong']);
        $this->assertNull(Version::findOne(['corrects_version_id' => $faulty->id]));
    }

    public function testCorrectRequiresPost(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertMethodNotAllowed('GET', 'version/correct', ['id' => $faulty->id]);
        $this->assertNull(Version::findOne(['corrects_version_id' => $faulty->id]));
    }

    public function testDraftsAndUnknownVersionsAreNotFound(): void
    {
        $draft = $this->createVersion($this->periodItem());
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([$draft->id, self::UNKNOWN_ID] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'POST', 'version/correct', ['id' => $id]);
        }
    }

    public function testCorrectCreatesTheCorrectionAndOpensTheWizard(): void
    {
        $item = $this->periodItem();
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01', 'content' => 'Faulty text']);
        $this->createFile($faulty, ['kind' => File::KIND_MAIN, 'name' => 'main.pdf']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/correct', ['reason' => ' Wrong amount '], ['id' => $faulty->id]);

        $correction = Version::findOne(['corrects_version_id' => $faulty->id]);
        $this->assertNotNull($correction);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 1]);
        $this->assertSame(Version::STATUS_DRAFT, $correction->status);
        $this->assertSame(3, $correction->number);
        $this->assertSame('Faulty text', $correction->content);
        $this->assertSame('2025-01-01', $correction->valid_from);
        $this->assertNull($correction->valid_until);
        $this->assertCount(1, $correction->files);
        $this->assertSame('Wrong amount', Version::findOne($faulty->id)->withdraw_reason);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($faulty->id)->status);

        $this->assertPage($this->get('version/update', ['id' => $correction->id, 'step' => 1]));
        $this->assertSame('Correct version 2', Yii::$app->view->title);
    }

    public function testCorrectWithoutReason(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/correct', [], ['id' => $faulty->id]);

        $correction = Version::findOne(['corrects_version_id' => $faulty->id]);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 1]);
        $this->assertNull(Version::findOne($faulty->id)->withdraw_reason);
    }

    public function testValidityStepIsReadOnlyWithPastHint(): void
    {
        $item = $this->periodItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2024-01-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);
        $correction = $this->correct($faulty);

        $html = $this->assertPage($this->get('version/update', ['id' => $correction->id, 'step' => 2]));

        $this->assertStringNotContainsString('name="Version[valid_from]"', $html);
        $this->assertStringNotContainsString('name="Version[valid_until]"', $html);
        $this->assertStringContainsString(Html::encode(Yii::$app->formatter->asDate('2024-01-01')), $html);
        $this->assertStringContainsString(Html::encode(Yii::$app->formatter->asDate('2024-12-31')), $html);
        $this->assertStringContainsString('A correction keeps the validity period of the corrected version.', $html);
        $this->assertStringContainsString('Changes the answers to questions about 2024.', $html);
        $this->assertStringContainsString('Version 1 is withdrawn.', $html);

        // Next without dates continues, other dates are rejected.
        $this->post('version/update', ['next' => 1], ['id' => $correction->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 3]);

        $html = $this->assertPage($this->post('version/update', [
            'next' => 1,
            'Version' => ['valid_from' => '2024-06-01', 'valid_until' => ''],
        ], ['id' => $correction->id, 'step' => 2]));
        $this->assertStringContainsString('knowledge-library-wizard-error', $html);
        $correction = Version::findOne($correction->id);
        $this->assertSame('2024-01-01', $correction->valid_from);
        $this->assertSame('2024-12-31', $correction->valid_until);
    }

    public function testCorrectionOfAFutureVersionHasNoPastHint(): void
    {
        $item = $this->periodItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2099-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);
        $correction = $this->correct($faulty);

        $html = $this->assertPage($this->get('version/update', ['id' => $correction->id, 'step' => 2]));

        $this->assertStringNotContainsString('knowledge-library-wizard-past-hint', $html);
    }

    public function testPublishingTheCorrectionWithdrawsTheFaultyVersion(): void
    {
        $item = $this->periodItem();
        $previous = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);
        $this->post('version/correct', ['reason' => 'Wrong amount'], ['id' => $faulty->id]);
        $correction = Version::findOne(['corrects_version_id' => $faulty->id]);

        $this->post('version/update', ['next' => 1, 'Version' => ['content' => 'Corrected']], ['id' => $correction->id, 'step' => 1]);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 2]);
        $this->post('version/update', ['next' => 1], ['id' => $correction->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 3]);
        $this->post('version/update', ['next' => 1], ['id' => $correction->id, 'step' => 3]);
        $this->assertRedirectsTo(['version/update', 'id' => $correction->id, 'step' => 4]);

        $html = $this->assertPage($this->get('version/update', ['id' => $correction->id, 'step' => 4]));
        $this->assertStringContainsString(Html::encode('Version 2 is withdrawn.'), $html);
        $this->assertStringContainsString('Changes the answers to questions about 2025', $html);

        $this->post('version/update', ['publish' => 1], ['id' => $correction->id, 'step' => 4]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);

        $faulty = Version::findOne($faulty->id);
        $correction = Version::findOne($correction->id);
        $this->assertSame(Version::STATUS_WITHDRAWN, $faulty->status);
        $this->assertSame('Wrong amount', $faulty->withdraw_reason);
        $this->assertSame(Version::STATUS_PUBLISHED, $correction->status);
        $this->assertSame('Corrected', $correction->content);
        $this->assertSame('2025-01-01', $correction->valid_from);
        $this->assertNull($correction->valid_until);
        // The previous version is not changed.
        $this->assertSame('2024-12-31', Version::findOne($previous->id)->valid_until);
        $this->assertSame($correction->id, Item::findOne($item->id)->getValidVersion()->id);

        $entry = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_CORRECTED]);
        $this->assertNotNull($entry);
        $this->assertSame('Wrong amount', $entry->reason);
        $view = $this->assertPage($this->get('item/view', ['id' => $item->id, 'tab' => 'history']));
        $this->assertStringContainsString('Version 2 corrected by version 3', $view);
    }

    public function testCorrectionOfTypeWithReviewGoesThroughTheReview(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);
        $item = $this->createItem(['type_id' => $type->id]);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);
        $correction = $this->correct($faulty);

        $this->post('version/update', ['submit-for-review' => 1, 'reviewer' => 'user-2'], ['id' => $correction->id, 'step' => 4]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($correction->id)->status);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($faulty->id)->status);

        $this->loginAs(Module::ROLE_REVIEWER);
        DummyUserProvider::$currentReference = 'user-2';
        $this->post('version/approve', [], ['id' => $correction->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);

        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($faulty->id)->status);
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($correction->id)->status);
    }

    public function testCorrectIsLockedByDraftReviewAndArchive(): void
    {
        $item = $this->periodItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $draft = $this->createVersion($item, ['valid_from' => '2099-01-01']);
        $this->post('version/correct', ['reason' => 'Wrong'], ['id' => $faulty->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame('This item already has a draft.', $this->getFlash('error'));

        $current = DummyUserProvider::$currentReference;
        $this->assertTrue($draft->submitForReview('user-2'));
        DummyUserProvider::$currentReference = $current;
        $this->post('version/correct', ['reason' => 'Wrong'], ['id' => $faulty->id]);
        $this->assertSame('This item already has a version in review.', $this->getFlash('error'));
        $draft->delete();

        $this->assertTrue(Item::findOne($item->id)->archive());
        $this->post('version/correct', ['reason' => 'Wrong'], ['id' => $faulty->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));

        $this->assertNull(Version::findOne(['corrects_version_id' => $faulty->id]));
        $this->assertNull(Version::findOne($faulty->id)->withdraw_reason);
    }

    public function testWithdrawnVersionCannotBeCorrected(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->assertTrue($faulty->withdraw('Wrong', Version::WITHDRAW_NONE));
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/correct', [], ['id' => $faulty->id]);

        $this->assertSame('Only a published version can be corrected.', $this->getFlash('error'));
        $this->assertNull(Version::findOne(['corrects_version_id' => $faulty->id]));
    }

    public function testDiscardingTheCorrectionClearsTheReason(): void
    {
        $faulty = $this->createPublishedVersion($this->periodItem(), ['valid_from' => '2025-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);
        $correction = $this->correct($faulty, 'Wrong amount');
        $this->assertSame('Wrong amount', Version::findOne($faulty->id)->withdraw_reason);

        $this->post('version/discard', [], ['id' => $correction->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $faulty->item_id]);
        $this->assertNull(Version::findOne($correction->id));
        $faulty = Version::findOne($faulty->id);
        $this->assertNull($faulty->withdraw_reason);
        $this->assertSame(Version::STATUS_PUBLISHED, $faulty->status);
    }

    public function testVersionsTabOffersCorrectWithConfirmation(): void
    {
        $item = $this->periodItem();
        $inForce = $this->createPublishedVersion($item, ['valid_from' => '2024-01-01']);
        $current = $this->createPublishedVersion($item, ['valid_from' => '2099-01-01']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        foreach ([$inForce, $current] as $version) {
            $url = Html::encode(Url::to(['/knowledge-library/version/correct', 'id' => $version->id]));
            $this->assertMatchesRegularExpression(
                '#<a class="btn btn-default btn-xs knowledge-library-version-correct" href="' . preg_quote($url, '#')
                . '" data-method="post" data-confirm="[^"]+">Correct</a>#',
                $html
            );
        }
        $period = Yii::$app->formatter->asDate('2024-01-01') . ' – ' . Yii::$app->formatter->asDate('2098-12-31');
        $this->assertStringContainsString(
            Html::encode("Correct version 1?\nThe faulty version is withdrawn, the correction takes over the same validity period ($period).\nChanges the answers to questions about 2024 to " . date('Y') . '.'),
            $html
        );
        $period = Yii::$app->formatter->asDate('2099-01-01') . ' – open-ended';
        $this->assertStringContainsString(
            Html::encode("Correct version 2?\nThe faulty version is withdrawn, the correction takes over the same validity period ($period).") . '"',
            $html
        );

        // No correction while a draft exists.
        $this->createVersion($item);
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringNotContainsString('knowledge-library-version-correct', $html);
    }

    public function testCorrectionConfirmationWithoutValidityPeriod(): void
    {
        $type = $this->createType(['has_validity_period' => false, 'requires_review' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $version = $this->createPublishedVersion($item);
        $version->populateRelation('item', Item::findOne($item->id));

        $text = VersionController::correctionConfirmation($version, 'P');

        $this->assertSame(
            "Correct version 1?\nThe faulty version is withdrawn, the correction takes over the same validity period (P).",
            $text
        );
    }

    public function testCorrectionIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->periodItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2024-01-01', 'valid_until' => '2024-12-31']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('>Korrigieren</a>', $html);
        $this->assertStringContainsString('Die fehlerhafte Version wird zurückgezogen, die Korrektur übernimmt denselben Gültigkeitszeitraum', $html);
        $this->assertStringContainsString('Ändert die Antworten auf Fragen zu 2024.', $html);

        $correction = $this->correct($faulty);
        $this->assertPage($this->get('version/update', ['id' => $correction->id, 'step' => 2]));
        $this->assertSame('Version 1 korrigieren', Yii::$app->view->title);
    }

    /**
     * Posts version/correct for the version and returns the created
     * correction.
     */
    private function correct(Version $faulty, ?string $reason = null): Version
    {
        $this->post('version/correct', $reason === null ? [] : ['reason' => $reason], ['id' => $faulty->id]);
        $correction = Version::findOne(['corrects_version_id' => $faulty->id, 'status' => Version::STATUS_DRAFT]);
        $this->assertNotNull($correction, 'Correction not created: ' . json_encode($this->getFlash('error')));

        return $correction;
    }

    private function periodItem(): Item
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);

        return $this->createItem(['type_id' => $type->id]);
    }
}
