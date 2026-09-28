<?php

namespace dmstr\knowledgeLibrary\tests\web;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\controllers\VersionController;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ValidityCheck;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Tests of the version wizard (`version/*`).
 */
class VersionControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLoginOnAllRoutes(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAsGuest();

        foreach ($this->routes($item, $draft) as [$method, $route, $params]) {
            $this->assertNull($this->request($method, $route, $params), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertNotNull(Version::findOne($draft->id));
    }

    public function testUserWithoutRoleIsForbiddenOnAllRoutes(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs();

        foreach ($this->routes($item, $draft) as [$method, $route, $params]) {
            $this->assertForbidden($method, $route, $params);
        }
        $this->assertNotNull(Version::findOne($draft->id));
    }

    public function testEditorReviewerAndAdminMayUseAllRoutes(): void
    {
        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);

            $item = $this->createPeriodItem();
            $this->post('version/create', [], ['itemId' => $item->id]);
            $draft = $this->findDraft($item);
            $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
            $this->post('version/update', ['next' => 1, 'Version' => ['content' => 'Text']], ['id' => $draft->id, 'step' => 1]);
            $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 2]);

            foreach ([1, 2, 3, 4] as $step) {
                $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => $step]));
            }

            $this->post('version/publish', [], ['id' => $draft->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
            $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($draft->id)->status, $role);

            $this->post('version/create', [], ['itemId' => $item->id]);
            $second = $this->findDraft($item);
            $this->post('version/discard', [], ['id' => $second->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
            $this->assertNull(Version::findOne($second->id), $role);
        }
    }

    public function testChangingRoutesRequirePost(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertMethodNotAllowed('GET', 'version/create', ['itemId' => $item->id]);
        $this->assertMethodNotAllowed('GET', 'version/publish', ['id' => $draft->id]);
        $this->assertMethodNotAllowed('GET', 'version/discard', ['id' => $draft->id]);

        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testCreateCopiesTheLatestPublishedVersion(): void
    {
        $topic = $this->createTopic();
        $item = $this->createPeriodItem(['summary' => 'Summary', 'topicIds' => [$topic->id]]);
        $published = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01', 'content' => 'Old text']);
        $this->createFile($published, ['kind' => File::KIND_MAIN, 'name' => 'law.pdf']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/create', [], ['itemId' => $item->id]);

        $draft = $this->findDraft($item);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame(2, (int)$draft->number);
        $this->assertSame('Old text', $draft->content);
        $this->assertSame($item->title, $draft->draft_title);
        $this->assertSame('Summary', $draft->draft_summary);
        $this->assertSame([$topic->id], $draft->getDraftTopicIds());
        $this->assertSame(['law.pdf'], array_map(static fn (File $file) => $file->name, $draft->mainFiles));

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id]));
        $this->assertStringContainsString('Old text</textarea>', $html);
        $this->assertStringContainsString('law.pdf', $html);
    }

    public function testSecondCreateRedirectsToTheExistingDraft(): void
    {
        $item = $this->createPeriodItem();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/create', [], ['itemId' => $item->id]);
        $draft = $this->findDraft($item);
        $this->post('version/create', [], ['itemId' => $item->id]);

        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame('This item already has a draft.', $this->getFlash('info'));
        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());
    }

    public function testCreateForUnknownItemIsNotFound(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'version/create', ['itemId' => self::UNKNOWN_ID]);
    }

    public function testOnlyDraftsAreReachable(): void
    {
        $item = $this->createPeriodItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $inReview = $this->createVersion($item, ['valid_from' => '2099-01-01']);
        $this->assertTrue($inReview->submitForReview('user-2'));
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([$published->id, $inReview->id, self::UNKNOWN_ID] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'version/update', ['id' => $id]);
            $this->assertHttpException(NotFoundHttpException::class, 'POST', 'version/update', ['id' => $id], ['save' => 1]);
            $this->assertHttpException(NotFoundHttpException::class, 'POST', 'version/publish', ['id' => $id]);
            $this->assertHttpException(NotFoundHttpException::class, 'POST', 'version/discard', ['id' => $id]);
        }
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($published->id)->status);
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($inReview->id)->status);
    }

    public function testWizardTitleAndBreadcrumbs(): void
    {
        $item = $this->createPeriodItem(['title' => 'Forest <law>']);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id]));

        $this->assertSame('Create first version', Yii::$app->getView()->title);
        $breadcrumbs = Yii::$app->getView()->params['breadcrumbs'];
        $this->assertSame(['label' => 'Forest <law>', 'url' => ['item/view', 'id' => $item->id]], $breadcrumbs[1]);
        $this->assertSame('Create first version', $breadcrumbs[2]);
        $this->assertStringContainsString('Forest &lt;law&gt;</span>', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        foreach (['Content', 'Validity', 'Details', 'Check'] as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html);
        }

        $other = $this->createPeriodItem();
        $this->createPublishedVersion($other, ['valid_from' => '2020-01-01']);
        $second = Version::createDraft($other);
        $this->assertPage($this->get('version/update', ['id' => $second->id]));
        $this->assertSame('Create version 2', Yii::$app->getView()->title);
    }

    public function testStepOneNextWithoutContentStaysWithMessage(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 1]));
        $this->assertStringContainsString('knowledge-library-wizard-blocked', $html);
        $this->assertStringNotContainsString('knowledge-library-wizard-error', $html);
        // Later steps are not reachable yet.
        $this->assertStringNotContainsString($this->stepUrl($draft, 2), $html);

        $html = $this->assertPage($this->post(
            'version/update',
            ['next' => 1, 'Version' => ['content' => "  \n "]],
            ['id' => $draft->id, 'step' => 1]
        ));
        $this->assertStringContainsString(Html::encode(Version::noContentMessage()), $html);
        $this->assertStringContainsString('knowledge-library-wizard-blocked', $html);
    }

    public function testStepOneNextWithTextSavesAndContinues(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/update', ['next' => 1, 'Version' => ['content' => 'New **text**']], ['id' => $draft->id, 'step' => 1]);

        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 2]);
        $this->assertSame('New **text**', Version::findOne($draft->id)->content);
    }

    public function testManipulatedLaterStepRedirectsToTheFirstIncompleteStep(): void
    {
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([2, 3, 4] as $step) {
            $this->get('version/update', ['id' => $draft->id, 'step' => $step]);
            $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
            $this->assertSame(Version::noContentMessage(), $this->getFlash('error'));
        }

        $this->post(
            'version/update',
            ['next' => 1, 'Version' => ['valid_from' => '2099-01-01']],
            ['id' => $draft->id, 'step' => 2]
        );
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame($draft->valid_from, Version::findOne($draft->id)->valid_from);

        $this->post('version/update', ['publish' => 1], ['id' => $draft->id, 'step' => 4]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->post('version/publish', [], ['id' => $draft->id]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);

        // Invalid validity blocks the details step.
        $draft->updateAttributes(['content' => 'Text', 'valid_from' => '2099-02-01', 'valid_until' => '2099-01-01']);
        $this->get('version/update', ['id' => $draft->id, 'step' => 3]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 2]);
        $this->assertSame(ValidityCheck::untilBeforeFromMessage(), $this->getFlash('error'));
    }

    public function testInvalidStepNumberIsClamped(): void
    {
        $item = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        foreach (['0' => 1, 'abc' => 1, '-1' => 1, '9' => 4] as $step => $expected) {
            $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => (string)$step]));
            $this->assertStringContainsString('knowledge-library-wizard-step-' . $expected . '"', $html, "step $step");
        }
    }

    public function testStepTwoErrors(): void
    {
        $item = $this->createPeriodItem();
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $draft = Version::createDraft($item);
        $validFrom = Version::findOne($draft->id)->valid_from;
        $this->loginAs(Module::ROLE_EDITOR);

        $cases = [
            'until before from' => [['valid_from' => '2099-02-01', 'valid_until' => '2099-01-01'], ValidityCheck::untilBeforeFromMessage()],
            'retroactive' => [['valid_from' => '2019-06-01', 'valid_until' => ''], Yii::t(
                'knowledge-library',
                'The date is before the start of version {number} ({date}). Retroactive changes are only possible with "Correct".',
                ['number' => 1, 'date' => Yii::$app->formatter->asDate('2020-01-01')]
            )],
            'same day as predecessor' => [['valid_from' => '2020-01-01', 'valid_until' => ''], null],
            'invalid format' => [['valid_from' => '01.02.2099', 'valid_until' => ''], ValidityCheck::invalidDateMessage('valid_from')],
            'invalid until' => [['valid_from' => '2099-01-01', 'valid_until' => '2099-13-45'], ValidityCheck::invalidDateMessage('valid_until')],
            'empty from' => [['valid_from' => '', 'valid_until' => ''], ValidityCheck::invalidDateMessage('valid_from')],
        ];

        foreach ($cases as $case => [$dates, $message]) {
            $html = $this->assertPage($this->post(
                'version/update',
                ['next' => 1, 'Version' => $dates],
                ['id' => $draft->id, 'step' => 2]
            ));
            $this->assertStringContainsString('knowledge-library-wizard-error', $html, $case);
            if ($message !== null) {
                $this->assertStringContainsString(Html::encode($message), $html, $case);
            }
            $this->assertSame(1, substr_count($html, 'knowledge-library-wizard-error'), $case);
            $this->assertStringNotContainsString('knowledge-library-wizard-preview', $html, $case);
            $this->assertSame($validFrom, Version::findOne($draft->id)->valid_from, $case);
        }
    }

    public function testStepTwoRejectsDatesForTypeWithoutValidityPeriod(): void
    {
        $item = $this->createNoPeriodItem();
        $draft = Version::createDraft($item);
        $draft->updateAttributes(['content' => 'Text']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 2]));
        $this->assertStringContainsString('Valid from publication, open-ended.', $html);
        $this->assertStringNotContainsString('name="Version[valid_from]"', $html);
        $this->assertStringContainsString('Version 1 is valid from publication, open-ended.', $html);

        $html = $this->assertPage($this->post(
            'version/update',
            ['next' => 1, 'Version' => ['valid_from' => '2099-01-01']],
            ['id' => $draft->id, 'step' => 2]
        ));
        $this->assertStringContainsString(Html::encode(ValidityCheck::noValidityPeriodMessage()), $html);
        $this->assertNull(Version::findOne($draft->id)->valid_from);

        $this->post('version/update', ['next' => 1], ['id' => $draft->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 3]);
    }

    public function testStepTwoConsequencesAndPreview(): void
    {
        $formatter = Yii::$app->formatter;
        $item = $this->createPeriodItem();
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/update', ['next' => 1, 'Version' => ['valid_from' => '2099-01-01', 'valid_until' => '']], ['id' => $draft->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 3]);
        $this->assertSame('2099-01-01', Version::findOne($draft->id)->valid_from);
        $this->assertNull(Version::findOne($draft->id)->valid_until);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 2]));
        $this->assertStringContainsString('value="2099-01-01"', $html);
        $this->assertStringContainsString('placeholder="open-ended"', $html);
        $this->assertStringContainsString('fa-long-arrow-right', $html);
        $this->assertStringContainsString(Html::encode('Version 1 ends on ' . $formatter->asDate('2098-12-31') . '.'), $html);
        $this->assertStringContainsString(Html::encode('Version 2 is valid from ' . $formatter->asDate('2099-01-01') . ', open-ended.'), $html);
        $this->assertStringContainsString('knowledge-library-wizard-preview', $html);
        $this->assertMatchesRegularExpression('#kl-validity-timeline-neu"[^>]*data-number="2"#', $html);
        $this->assertStringContainsString('kl-validity-timeline-changed', $html);
        $this->assertStringNotContainsString('knowledge-library-wizard-error', $html);

        // Gap between an ended predecessor and the new version, with an end.
        $other = $this->createPeriodItem();
        $this->createPublishedVersion($other, ['valid_from' => '2020-01-01', 'valid_until' => '2021-12-31']);
        $gapDraft = Version::createDraft($other);
        $this->post(
            'version/update',
            ['save' => 1, 'Version' => ['valid_from' => '2099-01-01', 'valid_until' => '2099-12-31']],
            ['id' => $gapDraft->id, 'step' => 2]
        );
        $this->assertRedirectsTo(['item/view', 'id' => $other->id]);
        $html = $this->assertPage($this->get('version/update', ['id' => $gapDraft->id, 'step' => 2]));
        $this->assertStringContainsString(Html::encode(
            'From ' . $formatter->asDate('2022-01-01') . ' to ' . $formatter->asDate('2098-12-31') . ' no version is valid.'
        ), $html);
        $this->assertStringContainsString(Html::encode(
            'Version 2 is valid from ' . $formatter->asDate('2099-01-01') . ' until ' . $formatter->asDate('2099-12-31') . '.'
        ), $html);
    }

    public function testStepThreeErrors(): void
    {
        $item = $this->createPeriodItem(['title' => 'Original']);
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post(
            'version/update',
            ['next' => 1, 'Version' => ['draft_title' => '  ', 'draft_summary' => '', 'draftTopicIds' => '']],
            ['id' => $draft->id, 'step' => 3]
        ));
        $this->assertMatchesRegularExpression('#field-version-draft_title[^"]*has-error#', $html);
        $this->assertSame('Original', Version::findOne($draft->id)->draft_title);

        $html = $this->assertPage($this->post(
            'version/update',
            ['next' => 1, 'Version' => ['draft_title' => 'New', 'draftTopicIds' => [self::UNKNOWN_ID]]],
            ['id' => $draft->id, 'step' => 3]
        ));
        $this->assertMatchesRegularExpression('#field-knowledge-library-version-topics[^"]*has-error#', $html);
        $this->assertSame('Original', Version::findOne($draft->id)->draft_title);
    }

    public function testStepThreeSavesDetails(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $item = $this->createPeriodItem(['title' => 'Original']);
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 3]));
        $this->assertStringContainsString('value="Original"', $html);
        $this->assertStringContainsString('>Forestry</option>', $html);

        $this->post(
            'version/update',
            ['next' => 1, 'Version' => ['draft_title' => ' New title ', 'draft_summary' => 'Short', 'draftTopicIds' => [$topic->id]]],
            ['id' => $draft->id, 'step' => 3]
        );
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 4]);
        $saved = Version::findOne($draft->id);
        $this->assertSame('New title', $saved->draft_title);
        $this->assertSame('Short', $saved->draft_summary);
        $this->assertSame([$topic->id], $saved->getDraftTopicIds());
        // The item keeps its details until publication.
        $this->assertSame('Original', Item::findOne($item->id)->title);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));
        $this->assertMatchesRegularExpression('#knowledge-library-review-topics.*?Forestry</span>#s', $html);

        // An empty multiple select submits no topics.
        $this->post('version/update', ['next' => 1, 'Version' => ['draft_title' => 'New title']], ['id' => $draft->id, 'step' => 3]);
        $this->assertSame([], Version::findOne($draft->id)->getDraftTopicIds());
    }

    public function testStepFourShowsTextChange(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);
        $cases = [
            'unchanged' => ['Same text', 'unchanged'],
            'changed' => ['Other text', 'changed'],
            'none' => ['', 'no text'],
        ];

        foreach ($cases as $case => [$content, $expected]) {
            $item = $this->createPeriodItem();
            $this->createPublishedVersion($item, ['valid_from' => '2020-01-01', 'content' => 'Same text']);
            $draft = Version::createDraft($item);
            $draft->updateAttributes(['content' => $content, 'valid_from' => '2099-01-01']);
            if ($content === '') {
                $this->createFile($draft, ['kind' => File::KIND_MAIN, 'name' => 'main.pdf']);
            }

            $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));
            $this->assertMatchesRegularExpression(
                '#knowledge-library-review-text".*?<span class="knowledge-library-review-value">' . $expected . '</span>#s',
                $html,
                $case
            );
        }

        $this->assertMatchesRegularExpression('#knowledge-library-review-main-files".*?value">main.pdf</span>#s', $html);
        $this->assertMatchesRegularExpression('#knowledge-library-review-attachments".*?value">none</span>#s', $html);
        $this->assertMatchesRegularExpression(
            '#knowledge-library-review-validity".*?value">' . preg_quote(Html::encode(Yii::$app->formatter->asDate('2099-01-01')), '#') . ' – open-ended</span>#s',
            $html
        );
    }

    public function testStepOneMarksChangedText(): void
    {
        $item = $this->createPeriodItem();
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01', 'content' => 'Same text']);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id]));
        $this->assertStringNotContainsString('knowledge-library-text-changed', $html);
        $this->assertStringContainsString('data-kl-base-text="Same text"', $html);

        $draft->updateAttributes(['content' => 'Other text']);
        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id]));
        $this->assertStringContainsString('knowledge-library-text-changed', $html);
        $this->assertStringContainsString('border-color: #f39c12', $html);
        $this->assertStringContainsString('Changed compared to version 1.', $html);
    }

    public function testSaveAsDraftInEachStepAndResume(): void
    {
        $topic = $this->createTopic();
        $item = $this->createPeriodItem();
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $steps = [
            1 => [['content' => 'Saved <text>'], 'Saved &lt;text&gt;</textarea>'],
            2 => [['valid_from' => '2099-03-01', 'valid_until' => '2099-12-31'], 'value="2099-12-31"'],
            3 => [['draft_title' => 'Saved title', 'draft_summary' => 'Saved summary', 'draftTopicIds' => [$topic->id]], 'value="Saved title"'],
            4 => [[], null],
        ];
        foreach ($steps as $step => [$fields, $expected]) {
            $this->post('version/update', ['save' => 1, 'Version' => $fields], ['id' => $draft->id, 'step' => $step]);
            $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
            $this->assertSame('Draft saved.', $this->getFlash('success'), "step $step");

            if ($expected !== null) {
                $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => $step]));
                $this->assertStringContainsString($expected, $html, "step $step");
            }
        }

        $saved = Version::findOne($draft->id);
        $this->assertSame('Saved <text>', $saved->content);
        $this->assertSame('2099-03-01', $saved->valid_from);
        $this->assertSame('Saved summary', $saved->draft_summary);
        $this->assertSame(Version::STATUS_DRAFT, $saved->status);

        // The detail page continues the draft.
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('href="' . Html::encode($this->stepUrl($draft, 1)) . '"', $html);
    }

    public function testSaveWithInvalidInputShowsErrors(): void
    {
        $item = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post(
            'version/update',
            ['save' => 1, 'Version' => ['valid_from' => 'tomorrow']],
            ['id' => $draft->id, 'step' => 2]
        ));
        $this->assertStringContainsString(Html::encode(ValidityCheck::invalidDateMessage('valid_from')), $html);
        $this->assertNull($this->getFlash('success'));
    }

    public function testBackReturnsWithoutValidation(): void
    {
        $item = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $validFrom = $draft->valid_from;
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/update', ['back' => 1, 'Version' => ['valid_from' => 'invalid']], ['id' => $draft->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame($validFrom, Version::findOne($draft->id)->valid_from);

        $this->post('version/update', ['back' => 1, 'Version' => ['draft_title' => 'Kept']], ['id' => $draft->id, 'step' => 3]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 2]);
        $this->assertSame('Kept', Version::findOne($draft->id)->draft_title);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 2]));
        $this->assertStringContainsString('knowledge-library-wizard-back', $html);
        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 1]));
        $this->assertStringNotContainsString('knowledge-library-wizard-back', $html);
    }

    public function testManipulatedAttributesAreIgnored(): void
    {
        $item = $this->createPeriodItem();
        $other = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $number = (int)$draft->number;
        $this->loginAs(Module::ROLE_EDITOR);

        $manipulated = [
            'item_id' => $other->id,
            'status' => Version::STATUS_PUBLISHED,
            'number' => 99,
            'published_by' => 'intruder',
            'corrects_version_id' => $draft->id,
        ];
        foreach ([1, 2, 3] as $step) {
            $this->post(
                'version/update',
                ['save' => 1, 'Version' => array_merge($manipulated, ['content' => 'Text', 'draft_title' => 'Title'])],
                ['id' => $draft->id, 'step' => $step]
            );
        }

        $saved = Version::findOne($draft->id);
        $this->assertSame($item->id, $saved->item_id);
        $this->assertSame(Version::STATUS_DRAFT, $saved->status);
        $this->assertSame($number, (int)$saved->number);
        $this->assertNull($saved->published_by);
        $this->assertNull($saved->corrects_version_id);
    }

    public function testStepIndicatorLinksReachableSteps(): void
    {
        $item = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 2]));

        foreach ([1, 3, 4] as $step) {
            $this->assertStringContainsString('href="' . Html::encode($this->stepUrl($draft, $step)) . '"', $html, "step $step");
        }
        $this->assertMatchesRegularExpression('#<div class="knowledge-library-wizard-step active" data-step="2"#', $html);
        $this->assertMatchesRegularExpression('#class="knowledge-library-wizard-step done" href="[^"]*" data-step="1"[^>]*>.*?fa-check#s', $html);
    }

    public function testPublishForTypeWithReviewIsForbidden(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);
        $item = $this->createItem(['type_id' => $type->id]);
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));
        $this->assertStringNotContainsString('knowledge-library-wizard-publish', $html);
        $this->assertStringContainsString('Approval by a second person required.', $html);
        $this->assertStringContainsString('knowledge-library-wizard-save', $html);

        $this->assertHttpException(ForbiddenHttpException::class, 'POST', 'version/publish', ['id' => $draft->id]);
        $this->assertHttpException(ForbiddenHttpException::class, 'POST', 'version/update', ['id' => $draft->id, 'step' => 4], ['publish' => 1]);

        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testPublishEndsPredecessorAndAppliesDetails(): void
    {
        $formatter = Yii::$app->formatter;
        $today = date('Y-m-d');
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $topic = $this->createTopic(['name' => 'Forestry']);
        $item = $this->createPeriodItem(['title' => 'Old title', 'summary' => 'Old summary']);
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01', 'content' => 'Old']);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/create', [], ['itemId' => $item->id]);
        $draft = $this->findDraft($item);
        $this->post('version/update', ['next' => 1, 'Version' => ['content' => 'New']], ['id' => $draft->id, 'step' => 1]);
        $this->post('version/update', ['next' => 1, 'Version' => ['valid_from' => $today, 'valid_until' => '']], ['id' => $draft->id, 'step' => 2]);
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 3]);
        $this->post(
            'version/update',
            ['next' => 1, 'Version' => ['draft_title' => 'New title', 'draft_summary' => 'New summary', 'draftTopicIds' => [$topic->id]]],
            ['id' => $draft->id, 'step' => 3]
        );
        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 4]);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));
        $confirm = "Publish version 2?\nValid From: " . $formatter->asDate($today)
            . "\nConsequences: ends version 1 on " . $formatter->asDate($yesterday) . ', is in force immediately'
            . "\nContent: Text";
        $this->assertStringContainsString('data-confirm="' . Html::encode($confirm) . '"', $html);

        $this->post('version/update', ['publish' => 1], ['id' => $draft->id, 'step' => 4]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame('Version 2 published.', $this->getFlash('success'));
        $published = Version::findOne($draft->id);
        $this->assertSame(Version::STATUS_PUBLISHED, $published->status);
        $this->assertNull($published->draft_title);
        $this->assertNull($published->draft_summary);
        $this->assertNull($published->draft_topic_ids);
        $this->assertSame($yesterday, Version::findOne($predecessor->id)->valid_until);
        $updated = Item::findOne($item->id);
        $this->assertSame('New title', $updated->title);
        $this->assertSame('New summary', $updated->summary);
        $this->assertSame([$topic->id], $updated->getTopicIds());

        $html = $this->assertPage($this->get('item/index'));
        $this->assertMatchesRegularExpression(
            '#>New title</a>.*?<span>Version 2</span> <span class="knowledge-library-since" style="color: \#777">since '
            . preg_quote(Html::encode($formatter->asDate($today)), '#') . '</span>#s',
            $html
        );
    }

    public function testPublishKeepsEarlierEndingPredecessor(): void
    {
        $item = $this->createPeriodItem();
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01', 'valid_until' => '2020-12-31']);
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/publish', [], ['id' => $draft->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame('2020-12-31', Version::findOne($predecessor->id)->valid_until);
    }

    public function testPublishConfirmationForUpcomingVersion(): void
    {
        $item = $this->createPeriodItem();
        $draft = $this->readyDraft($item);
        $this->createFile($draft, ['kind' => File::KIND_MAIN]);
        $this->createFile($draft);
        $this->createFile($draft);
        Yii::$app->language = 'de';
        $formatter = Yii::$app->formatter;

        $this->assertSame(
            "Version 1 veröffentlichen?\nGilt ab: " . $formatter->asDate('2099-01-01')
            . "\nFolgen: wird ab " . $formatter->asDate('2099-01-01') . ' die Version „in Kraft“'
            . "\nInhalt: Text, 1 Hauptdokument, 2 Anhänge",
            VersionController::publishConfirmation(Version::findOne($draft->id), null, '2026-09-28')
        );
    }

    public function testPublishForTypeWithoutValidityPeriod(): void
    {
        $item = $this->createNoPeriodItem();
        $predecessor = $this->createPublishedVersion($item);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 2]));
        $this->assertStringContainsString('Version 2 replaces version 1 upon publication.', $html);

        $this->post('version/publish', [], ['id' => $draft->id]);

        $this->assertSame('Version 2 published.', $this->getFlash('success'));
        $this->assertSame(Version::STATE_IN_FORCE, Version::findOne($draft->id)->getEffectiveState());
        $this->assertSame(Version::STATE_HISTORICAL, Version::findOne($predecessor->id)->getEffectiveState());
    }

    public function testDiscardRemovesDraftAndUnreferencedFiles(): void
    {
        $item = $this->createPeriodItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $shared = $this->createFile($published, ['path' => 'knowledge-library/' . $item->id . '/shared.pdf']);
        $draft = Version::createDraft($item);
        $own = $this->createFile($draft, ['path' => 'knowledge-library/' . $item->id . '/own.pdf']);
        $filesystem = Yii::$app->get('fs');
        $filesystem->write($shared->path, 'shared');
        $filesystem->write($own->path, 'own');
        $this->assertSame(2, (int)File::find()->where(['version_id' => $draft->id])->count());
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertMatchesRegularExpression(
            '#href="' . preg_quote(Html::encode(Url::to(['/knowledge-library/version/discard', 'id' => $draft->id])), '#')
            . '" data-method="post" data-confirm="Discard the draft of version 2\?"#',
            $html
        );

        $this->post('version/discard', [], ['id' => $draft->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Draft discarded.', $this->getFlash('success'));
        $this->assertNull(Version::findOne($draft->id));
        $this->assertSame(0, (int)File::find()->where(['version_id' => $draft->id])->count());
        $this->assertNotNull(File::findOne($shared->id));
        $this->assertFileExists($this->getStoragePath($shared->path));
        $this->assertFileDoesNotExist($this->getStoragePath($own->path));
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($published->id)->status);
    }

    public function testWizardIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->createPeriodItem();
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post('version/update', ['next' => 1, 'Version' => ['content' => '']], ['id' => $draft->id, 'step' => 1]));

        foreach (['Erste Version anlegen', 'Inhalt', 'Gültigkeit', 'Details', 'Prüfen', 'Abbrechen', 'Als Entwurf speichern', 'Weiter', 'Hauptdokumente', '(optional, mehrere möglich)', 'Anhänge', 'Bitte Text erfassen oder mindestens ein Hauptdokument hinzufügen.'] as $text) {
            $this->assertStringContainsString(Html::encode($text), $html);
        }

        $this->post('version/update', ['save' => 1, 'Version' => ['content' => 'Text']], ['id' => $draft->id, 'step' => 1]);
        $this->assertSame('Entwurf gespeichert.', $this->getFlash('success'));
    }

    /**
     * @return array<int, array{string, string, array}>
     */
    private function routes(Item $item, Version $draft): array
    {
        return [
            ['POST', 'version/create', ['itemId' => $item->id]],
            ['GET', 'version/update', ['id' => $draft->id]],
            ['POST', 'version/update', ['id' => $draft->id]],
            ['POST', 'version/publish', ['id' => $draft->id]],
            ['POST', 'version/discard', ['id' => $draft->id]],
        ];
    }

    private function createPeriodItem(array $attributes = []): Item
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);

        return $this->createItem(array_merge(['type_id' => $type->id], $attributes));
    }

    private function createNoPeriodItem(): Item
    {
        $type = $this->createType(['has_validity_period' => false, 'requires_review' => false]);

        return $this->createItem(['type_id' => $type->id]);
    }

    /**
     * Draft with text and a valid start in the future: steps 1 to 3 are
     * complete.
     */
    private function readyDraft(Item $item): Version
    {
        $draft = Version::createDraft($item);
        $this->assertFalse($draft->getIsNewRecord(), json_encode($draft->getErrors()));
        $draft->updateAttributes(['content' => 'Text', 'valid_from' => '2099-01-01']);

        return $draft;
    }

    private function findDraft(Item $item): Version
    {
        $draft = Version::find()->forItem($item->id)->andWhere(['status' => Version::STATUS_DRAFT])->one();
        $this->assertNotNull($draft, 'No draft found.');

        return $draft;
    }

    private function stepUrl(Version $draft, int $step): string
    {
        return Url::to(['/knowledge-library/version/update', 'id' => $draft->id, 'step' => $step]);
    }
}
