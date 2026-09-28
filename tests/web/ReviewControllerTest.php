<?php

namespace dmstr\knowledgeLibrary\tests\web;

use DateTimeImmutable;
use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\support\TestIdentity;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Tests of the review workflow: submitting in the wizard, the review page
 * (`version/review`), approving (`version/approve`), returning
 * (`version/return`), changing the reviewer (`version/reviewer`), the
 * callouts of the detail page and the lock of archived items.
 */
class ReviewControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLogin(): void
    {
        $version = $this->submitted($this->reviewItem(), 'user-2');
        $this->loginAsGuest();

        foreach ($this->reviewRoutes($version) as [$method, $route, $params]) {
            $this->assertNull($this->request($method, $route, $params), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testEditorAndUserWithoutRoleAreForbidden(): void
    {
        $version = $this->submitted($this->reviewItem(), 'user-2');

        foreach ([null, Module::ROLE_EDITOR] as $role) {
            $this->loginAs($role);
            foreach ($this->reviewRoutes($version) as [$method, $route, $params, $body]) {
                $this->assertForbidden($method, $route, $params, $body);
            }
        }
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testReviewerMayNotChangeTheReviewer(): void
    {
        $version = $this->submitted($this->reviewItem(), 'user-2');
        $this->loginAs(Module::ROLE_REVIEWER);

        $this->assertForbidden('GET', 'version/reviewer', ['id' => $version->id]);
        $this->assertForbidden('POST', 'version/reviewer', ['id' => $version->id], ['reviewer' => 'user-3']);
        $this->assertSame('user-2', Version::findOne($version->id)->reviewer_id);
    }

    public function testChangingRoutesRequirePost(): void
    {
        $version = $this->submitted($this->reviewItem(), 'user-2');
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertMethodNotAllowed('GET', 'version/approve', ['id' => $version->id]);
        $this->assertMethodNotAllowed('GET', 'version/return', ['id' => $version->id]);
        $this->assertMethodNotAllowed('POST', 'version/review', ['id' => $version->id]);
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
    }

    public function testOnlyVersionsInReviewAreFound(): void
    {
        $item = $this->reviewItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $draft = Version::createDraft($item);
        $this->loginAs(Module::ROLE_ADMIN);

        foreach ([$published->id, $draft->id, self::UNKNOWN_ID] as $id) {
            foreach ($this->reviewRoutes(new Version(['id' => $id])) as [$method, $route, $params, $body]) {
                $this->assertHttpException(NotFoundHttpException::class, $method, $route, $params, $body);
            }
        }
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testStepFourOffersSubmitWithReviewersWithoutTheCurrentUser(): void
    {
        $item = $this->reviewItem();
        $draft = $this->readyDraft($item);
        $editor = $this->loginAs(Module::ROLE_EDITOR);
        $this->allowReviewers($editor);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));

        $this->assertStringContainsString('Approval by a second person required.', $html);
        $this->assertStringContainsString('Reviewing person', $html);
        $this->assertMatchesRegularExpression('#<select id="knowledge-library-wizard-reviewer" class="form-control" name="reviewer">#', $html);
        foreach (['user-1', 'user-2', 'user-3'] as $reference) {
            $this->assertStringContainsString('<option value="' . $reference . '">User ' . $reference . '</option>', $html);
        }
        $this->assertStringNotContainsString('value="' . $editor->uuid . '"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertMatchesRegularExpression('#<button type="submit" class="btn knowledge-library-wizard-submit" name="submit" value="1"[^>]*>Submit for approval</button>#', $html);
        $this->assertStringNotContainsString('knowledge-library-wizard-publish', $html);
    }

    public function testSubmitSendsTheDraftForApproval(): void
    {
        $item = $this->reviewItem();
        $draft = $this->readyDraft($item);
        $editor = $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/update', ['submit' => 1, 'reviewer' => 'user-2', 'message' => ' Please check § 4. '], ['id' => $draft->id, 'step' => 4]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame('Version 1 submitted for approval.', $this->getFlash('success'));
        $version = Version::findOne($draft->id);
        $this->assertSame(Version::STATUS_IN_REVIEW, $version->status);
        $this->assertSame('user-2', $version->reviewer_id);
        $this->assertSame('Please check § 4.', $version->review_message);
        $this->assertSame($editor->uuid, $version->review_requested_by);

        $entry = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_REVIEW_REQUESTED]);
        $this->assertNotNull($entry);
        $this->assertSame('Please check § 4.', $entry->reason);
        $this->assertSame($editor->uuid, $entry->actor_id);

        // The draft is no longer reachable in the wizard.
        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'version/update', ['id' => $draft->id, 'step' => 4]);
    }

    public function testSubmitRejectsOwnReferenceUnknownPersonAndEmptySelection(): void
    {
        $item = $this->reviewItem();
        $draft = $this->readyDraft($item);
        $editor = $this->loginAs(Module::ROLE_EDITOR);
        // Even as reviewer option the own reference is rejected.
        $this->allowReviewers($editor);

        $cases = [
            $editor->uuid => 'You cannot review your own version.',
            'user-9' => 'The selected person cannot review this version.',
            '' => 'Select a reviewer.',
        ];
        foreach ($cases as $reviewer => $message) {
            $html = $this->assertPage($this->post(
                'version/update',
                ['submit' => 1, 'reviewer' => (string)$reviewer, 'message' => 'Kept <text>'],
                ['id' => $draft->id, 'step' => 4]
            ));

            $this->assertMatchesRegularExpression(
                '#knowledge-library-wizard-submit-error"[^>]*>\s*<i class="fa fa-times-circle"></i> ' . preg_quote(Html::encode($message), '#') . '#',
                $html,
                (string)$reviewer
            );
            $this->assertStringContainsString('Kept &lt;text&gt;</textarea>', $html);
            $this->assertNull($this->getFlash('success'));
            $version = Version::findOne($draft->id);
            $this->assertSame(Version::STATUS_DRAFT, $version->status, (string)$reviewer);
            $this->assertNull($version->reviewer_id);
        }
        $this->assertSame(0, (int)History::find()->where(['action' => History::ACTION_REVIEW_REQUESTED])->count());
    }

    public function testSubmitForTypeWithoutReviewIsForbidden(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);
        $draft = $this->readyDraft($this->createItem(['type_id' => $type->id]));
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('version/update', ['id' => $draft->id, 'step' => 4]));
        $this->assertStringNotContainsString('knowledge-library-wizard-submit', $html);
        $this->assertStringNotContainsString('name="reviewer"', $html);

        $this->assertHttpException(ForbiddenHttpException::class, 'POST', 'version/update', ['id' => $draft->id, 'step' => 4], ['submit' => 1, 'reviewer' => 'user-2']);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testSubmitWithIncompleteStepsRedirectsToTheStep(): void
    {
        $item = $this->reviewItem();
        $draft = Version::createDraft($item);
        $draft->updateAttributes(['content' => null]);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/update', ['submit' => 1, 'reviewer' => 'user-2'], ['id' => $draft->id, 'step' => 4]);

        $this->assertRedirectsTo(['version/update', 'id' => $draft->id, 'step' => 1]);
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
    }

    public function testReviewerFieldsInEarlierStepsAreIgnored(): void
    {
        $item = $this->reviewItem();
        $draft = $this->readyDraft($item);
        $this->loginAs(Module::ROLE_EDITOR);

        foreach ([1, 2, 3] as $step) {
            $this->post('version/update', [
                'save' => 1,
                'submit' => 1,
                'reviewer' => 'user-2',
                'Version' => ['content' => 'Text', 'reviewer_id' => 'user-2', 'status' => Version::STATUS_IN_REVIEW, 'review_message' => 'x'],
            ], ['id' => $draft->id, 'step' => $step]);
        }

        $version = Version::findOne($draft->id);
        $this->assertSame(Version::STATUS_DRAFT, $version->status);
        $this->assertNull($version->reviewer_id);
        $this->assertNull($version->review_message);
    }

    public function testSecondCreateWhileInReviewShowsFlashAndCreatesNoRow(): void
    {
        $item = $this->reviewItem();
        $this->submitted($item, 'user-2');
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('version/create', [], ['itemId' => $item->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('This item already has a version in review.', $this->getFlash('error'));
        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());
    }

    public function testReviewPageShowsMessageValidityConsequencesTopicsTextAndFiles(): void
    {
        $formatter = Yii::$app->formatter;
        $topicB = $this->createTopic(['name' => 'Water']);
        $topicA = $this->createTopic(['name' => 'Forest']);
        $item = $this->reviewItem(['title' => 'Forest <law>']);
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid, [
            'content' => "**Bold** <script>alert(1)</script>",
            'draft_title' => 'Forest <law>',
            'draft_topic_ids' => json_encode([$topicB->id, $topicA->id]),
        ], "Please check\n<b>§ 4</b>");
        $main = $this->createFile($version, ['kind' => File::KIND_MAIN, 'name' => 'law.pdf']);
        $attachment = $this->createFile($version, ['name' => 'annex.pdf', 'title' => 'Annex <1>']);

        $html = $this->assertPage($this->get('version/review', ['id' => $version->id]));

        $this->assertSame('Review version 2', Yii::$app->getView()->title);
        $this->assertSame(
            ['label' => 'Forest <law>', 'url' => ['item/view', 'id' => $item->id]],
            Yii::$app->getView()->params['breadcrumbs'][1]
        );
        $this->assertStringContainsString('Message from User user-1, ', $html);
        $this->assertStringContainsString('Please check<br />' . "\n" . '&lt;b&gt;§ 4&lt;/b&gt;', $html);
        $this->assertStringContainsString(
            Html::encode($formatter->asDate('2099-01-01') . ' – ' . 'open-ended'),
            $html
        );
        $this->assertStringContainsString(
            '<div class="knowledge-library-review-consequence">' . Html::encode('Version 1 ends on ' . $formatter->asDate('2098-12-31') . '.'),
            $html
        );
        $this->assertStringContainsString('knowledge-library-review-topics" style="font-weight: 400">Forest, Water</span>', $html);
        $this->assertStringContainsString('<strong>Bold</strong> &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        foreach ([$main, $attachment] as $file) {
            $this->assertStringContainsString(
                'href="' . Html::encode(Url::to(['/knowledge-library/file/download', 'id' => $file->id])) . '"',
                $html
            );
        }
        $this->assertStringContainsString('Annex &lt;1&gt; (annex.pdf)', $html);
        $this->assertStringContainsString('name="note"', $html);
        $this->assertStringContainsString(
            'action="' . Html::encode(Url::to(['/knowledge-library/version/approve', 'id' => $version->id])) . '"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<button type="submit" class="btn knowledge-library-review-return"[^>]*formaction="'
            . preg_quote(Html::encode(Url::to(['/knowledge-library/version/return', 'id' => $version->id])), '#') . '">Return</button>#',
            $html
        );
        $this->assertMatchesRegularExpression('#knowledge-library-review-approve"[^>]*data-confirm="Publish version 2\?[^"]*">Approve and publish</button>#', $html);
    }

    public function testReviewPageWithoutMessageTextAndFiles(): void
    {
        $item = $this->reviewItem();
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid);
        $version->updateAttributes(['content' => null]);

        $html = $this->assertPage($this->get('version/review', ['id' => $version->id]));

        $this->assertMatchesRegularExpression('#Sent for review by User user-1, [^<]*, without message\.#', $html);
        $this->assertStringContainsString('No text available.', $html);
        $this->assertStringContainsString('No files.', $html);
        $this->assertStringContainsString('knowledge-library-review-topics" style="font-weight: 400">none</span>', $html);
    }

    public function testReviewPageIsOnlyForTheChosenReviewer(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');

        foreach ([Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);
            $this->assertForbidden('GET', 'version/review', ['id' => $version->id]);
        }

        DummyUserProvider::$currentReference = 'user-2';
        $this->assertPage($this->get('version/review', ['id' => $version->id]));
    }

    public function testApproveAsReviewerPublishesAndLogs(): void
    {
        $today = date('Y-m-d');
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $item = $this->reviewItem(['title' => 'Old title']);
        $predecessor = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid, ['valid_from' => $today, 'draft_title' => 'New title']);

        $this->post('version/approve', ['note' => 'Looks good.'], ['id' => $version->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id, 'tab' => 'versions']);
        $this->assertSame('Version 2 approved and published.', $this->getFlash('success'));
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
        $this->assertSame($yesterday, Version::findOne($predecessor->id)->valid_until);
        $this->assertSame('New title', Item::findOne($item->id)->title);

        $approved = History::findOne(['version_id' => $version->id, 'action' => History::ACTION_APPROVED]);
        $this->assertSame('Looks good.', $approved->reason);
        $this->assertSame($reviewer->uuid, $approved->actor_id);
        $this->assertNotNull(History::findOne(['version_id' => $version->id, 'action' => History::ACTION_PUBLISHED]));

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id, 'tab' => 'history']));
        $this->assertStringContainsString('Version 2 approved by User ' . $reviewer->uuid, $html);
        $this->assertStringContainsString(Html::encode('Version 2 published, valid from ' . Yii::$app->formatter->asDate($today)), $html);
        $this->assertStringContainsString('<td class="knowledge-library-history-reason" style="color: #555">Looks good.</td>', $html);
        $this->assertStringNotContainsString('knowledge-library-pending-callout', $html);
    }

    public function testApproveAndReturnByOthersChangeNothing(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');

        foreach ([Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            $this->loginAs($role);

            $this->post('version/approve', [], ['id' => $version->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
            $this->assertSame('You are not the reviewer of this version.', $this->getFlash('error'), $role);

            $this->post('version/return', ['note' => 'No.'], ['id' => $version->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
            $this->assertSame('You are not the reviewer of this version.', $this->getFlash('error'), $role);
        }

        // The submitter, even with the reviewer role, cannot approve.
        $this->loginAs(Module::ROLE_REVIEWER);
        DummyUserProvider::$currentReference = 'user-1';
        $this->post('version/approve', [], ['id' => $version->id]);
        $this->assertSame('You are not the reviewer of this version.', $this->getFlash('error'));

        $stored = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_IN_REVIEW, $stored->status);
        $this->assertNull($stored->return_note);
        $this->assertSame(0, (int)History::find()->where(['action' => [History::ACTION_APPROVED, History::ACTION_RETURNED]])->count());
    }

    public function testReturnRequiresANote(): void
    {
        $item = $this->reviewItem();
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid);

        foreach ([[], ['note' => ''], ['note' => "  \n "], ['note' => ['array']]] as $body) {
            $this->post('version/return', $body, ['id' => $version->id]);

            $this->assertRedirectsTo(['version/review', 'id' => $version->id]);
            $this->assertSame('Enter a note for the author.', $this->getFlash('error'));
            $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);
        }
    }

    public function testReturnShowsCalloutsAndPrefillsTheResubmission(): void
    {
        $item = $this->reviewItem();
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid, [], 'Please <check>');

        $this->post('version/return', ['note' => "Section 2 is outdated.\n<b>Fix</b>"], ['id' => $version->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Version 1 returned to User user-1.', $this->getFlash('success'));
        $returned = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_DRAFT, $returned->status);
        $this->assertSame("Section 2 is outdated.\n<b>Fix</b>", $returned->return_note);
        $this->assertSame($reviewer->uuid, $returned->returned_by);
        $this->assertSame($reviewer->uuid, $returned->reviewer_id);
        $entry = History::findOne(['version_id' => $version->id, 'action' => History::ACTION_RETURNED]);
        $this->assertSame("Section 2 is outdated.\n<b>Fix</b>", $entry->reason);

        $callout = 'Returned by User ' . $reviewer->uuid . ' on ' . Html::encode(Yii::$app->formatter->asDate($returned->returned_at));
        $note = 'Section 2 is outdated.<br />' . "\n" . '&lt;b&gt;Fix&lt;/b&gt;';
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString($callout, $html);
        $this->assertStringContainsString($note, $html);
        $this->assertStringContainsString('knowledge-library-item-continue-draft', $html);
        $this->assertStringContainsString('Version 1 returned by User ' . $reviewer->uuid, $html);

        // The submitter continues the draft: callout, reviewer and message prefilled.
        $this->loginAs(Module::ROLE_EDITOR);
        $html = $this->assertPage($this->get('version/update', ['id' => $version->id, 'step' => 4]));
        $this->assertStringContainsString($callout, $html);
        $this->assertStringContainsString($note, $html);
        $this->assertStringContainsString('<option value="' . $reviewer->uuid . '" selected>', $html);
        $this->assertStringContainsString('Please &lt;check&gt;</textarea>', $html);

        // Submitting again clears the return.
        $this->post('version/update', ['submit' => 1, 'reviewer' => $reviewer->uuid, 'message' => 'Fixed.'], ['id' => $version->id, 'step' => 4]);
        $this->assertSame('Version 1 submitted for approval.', $this->getFlash('success'));
        $resubmitted = Version::findOne($version->id);
        $this->assertSame(Version::STATUS_IN_REVIEW, $resubmitted->status);
        $this->assertNull($resubmitted->return_note);
        $this->assertNull($resubmitted->returned_by);
        $this->assertNull($resubmitted->returned_at);
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringNotContainsString('knowledge-library-return-callout', $html);
    }

    public function testDetailPageShowsPendingCalloutWithButtonsByRole(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');
        $reviewerUrl = Html::encode(Url::to(['/knowledge-library/version/reviewer', 'id' => $version->id]));
        $reviewUrl = Html::encode(Url::to(['/knowledge-library/version/review', 'id' => $version->id]));

        $this->loginAs(Module::ROLE_EDITOR);
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertMatchesRegularExpression('#knowledge-library-pending-callout"[^>]*>\s*<i class="fa fa-hourglass-half"></i>\s*<span style="flex: 1">Version 1 is awaiting approval by User user-2.</span>#', $html);
        $this->assertStringNotContainsString($reviewerUrl, $html);
        $this->assertStringNotContainsString($reviewUrl, $html);

        $this->loginAs(Module::ROLE_ADMIN);
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertMatchesRegularExpression('#<a class="btn btn-sm knowledge-library-pending-change-reviewer" href="' . preg_quote($reviewerUrl, '#') . '"[^>]*>Change reviewer</a>#', $html);
        $this->assertStringNotContainsString($reviewUrl, $html);

        $this->loginAs(Module::ROLE_REVIEWER);
        DummyUserProvider::$currentReference = 'user-2';
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertMatchesRegularExpression('#<a class="btn btn-sm knowledge-library-pending-review" href="' . preg_quote($reviewUrl, '#') . '"[^>]*>Review</a>#', $html);
        $this->assertStringNotContainsString($reviewerUrl, $html);
    }

    public function testReviewerPageListsOtherOptions(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('version/reviewer', ['id' => $version->id]));

        $this->assertSame('Change reviewing person', Yii::$app->getView()->title);
        $this->assertStringContainsString('Current: User user-2', $html);
        $this->assertStringContainsString('<option value="user-3">User user-3</option>', $html);
        // Neither the current reviewer nor the submitter.
        $this->assertStringNotContainsString('<option value="user-2">', $html);
        $this->assertStringNotContainsString('<option value="user-1">', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('>Hand over</button>', $html);
    }

    public function testReviewerChangeRejectsInvalidPersons(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');
        $this->loginAs(Module::ROLE_ADMIN);

        $cases = [
            'user-2' => 'The selected person already reviews this version.',
            'user-1' => 'The person who submitted the version cannot review it.',
            'user-9' => 'The selected person cannot review this version.',
            '' => 'Select a reviewer.',
        ];
        foreach ($cases as $reviewer => $message) {
            $html = $this->assertPage($this->post('version/reviewer', ['reviewer' => (string)$reviewer, 'reason' => 'Why <not>'], ['id' => $version->id]));
            $this->assertStringContainsString('<i class="fa fa-times-circle"></i> ' . Html::encode($message), $html, (string)$reviewer);
            $this->assertStringContainsString('Why &lt;not&gt;</textarea>', $html);
        }

        $this->assertSame('user-2', Version::findOne($version->id)->reviewer_id);
        $this->assertSame(0, (int)History::find()->where(['action' => History::ACTION_REVIEWER_CHANGED])->count());
    }

    public function testReviewerChangeHandsOverTheReview(): void
    {
        $item = $this->reviewItem();
        $version = $this->submitted($item, 'user-2');
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('version/reviewer', ['reviewer' => 'user-3', 'reason' => 'On vacation.'], ['id' => $version->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Review handed over to User user-3.', $this->getFlash('success'));
        $this->assertSame('user-3', Version::findOne($version->id)->reviewer_id);
        $entry = History::findOne(['version_id' => $version->id, 'action' => History::ACTION_REVIEWER_CHANGED]);
        $this->assertSame('On vacation.', $entry->reason);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id, 'tab' => 'history']));
        $this->assertStringContainsString('Review of version 1 handed over to User user-3 (previously User user-2)', $html);
        $this->assertStringContainsString('Version 1 is awaiting approval by User user-3.', $html);

        // Only the new reviewer can approve now.
        $this->loginAs(Module::ROLE_REVIEWER);
        DummyUserProvider::$currentReference = 'user-2';
        $this->post('version/approve', [], ['id' => $version->id]);
        $this->assertSame('You are not the reviewer of this version.', $this->getFlash('error'));
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);

        DummyUserProvider::$currentReference = 'user-3';
        $this->post('version/approve', [], ['id' => $version->id]);
        $this->assertSame('Version 1 approved and published.', $this->getFlash('success'));
        $this->assertSame(Version::STATUS_PUBLISHED, Version::findOne($version->id)->status);
    }

    public function testArchivedItemLocksApprovalButAllowsReturn(): void
    {
        $item = $this->reviewItem();
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid);
        $item->updateAttributes(['is_archived' => true]);

        $html = $this->assertPage($this->get('version/review', ['id' => $version->id]));
        $this->assertStringContainsString('The knowledge object is archived.', $html);
        $this->assertStringNotContainsString('knowledge-library-review-approve', $html);

        $this->post('version/approve', [], ['id' => $version->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));
        $this->assertSame(Version::STATUS_IN_REVIEW, Version::findOne($version->id)->status);

        $this->post('version/return', ['note' => 'Archived meanwhile.'], ['id' => $version->id]);
        $this->assertSame('Version 1 returned to User user-1.', $this->getFlash('success'));
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($version->id)->status);
    }

    public function testArchivedItemLocksTheWizard(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $draft = $this->readyDraft($item);
        $other = $this->createItem(['type_id' => $type->id, 'is_archived' => true]);
        $item->updateAttributes(['is_archived' => true]);
        $this->loginAs(Module::ROLE_EDITOR);

        $requests = [
            ['POST', 'version/create', ['itemId' => $other->id], []],
            ['POST', 'version/create', ['itemId' => $item->id], []],
            ['GET', 'version/update', ['id' => $draft->id, 'step' => 1], []],
            ['POST', 'version/update', ['id' => $draft->id, 'step' => 4], ['publish' => 1]],
            ['POST', 'version/publish', ['id' => $draft->id], []],
        ];
        foreach ($requests as [$method, $route, $params, $body]) {
            $this->request($method, $route, $params, $body);
            $itemId = $params['itemId'] ?? $item->id;
            $this->assertRedirectsTo(['item/view', 'id' => $itemId]);
            $this->assertSame('The knowledge object is archived.', $this->getFlash('error'), $route);
        }
        $this->assertSame(Version::STATUS_DRAFT, Version::findOne($draft->id)->status);
        $this->assertSame(0, (int)Version::find()->forItem($other->id)->count());

        // Discarding stays possible.
        $this->post('version/discard', [], ['id' => $draft->id]);
        $this->assertSame('Draft discarded.', $this->getFlash('success'));
        $this->assertNull(Version::findOne($draft->id));
    }

    public function testReviewPagesAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->reviewItem();
        $reviewer = $this->loginAs(Module::ROLE_REVIEWER);
        $this->allowReviewers($reviewer);
        $version = $this->submitted($item, $reviewer->uuid);

        $html = $this->assertPage($this->get('version/review', ['id' => $version->id]));
        foreach (['Version 1 prüfen', 'Zur Prüfung gesendet von User user-1', 'Gültigkeit', 'Folgen', 'Themen', 'Dateien', 'Keine Dateien.', 'Anmerkung', '(Pflicht bei Rückgabe)', 'Zurückgeben', 'Freigeben und veröffentlichen'] as $text) {
            $this->assertStringContainsString(Html::encode($text), $html);
        }

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('Version 1 wartet auf Freigabe durch User ' . $reviewer->uuid . '.', $html);
        $this->assertStringContainsString('>Prüfen</a>', $html);

        $this->loginAs(Module::ROLE_ADMIN);
        $html = $this->assertPage($this->get('version/reviewer', ['id' => $version->id]));
        foreach (['Prüfende Person ändern', 'Aktuell: User ' . $reviewer->uuid, 'Neue prüfende Person', 'Begründung', 'Übergeben', 'Person wählen'] as $text) {
            $this->assertStringContainsString(Html::encode($text), $html);
        }
    }

    /**
     * Routes of the review workflow for the version.
     *
     * @return array<int, array{string, string, array, array}>
     */
    private function reviewRoutes(Version $version): array
    {
        return [
            ['GET', 'version/review', ['id' => $version->id], []],
            ['POST', 'version/approve', ['id' => $version->id], []],
            ['POST', 'version/return', ['id' => $version->id], ['note' => 'Note']],
            ['GET', 'version/reviewer', ['id' => $version->id], []],
            ['POST', 'version/reviewer', ['id' => $version->id], ['reviewer' => 'user-3']],
        ];
    }

    /**
     * Item of a type with validity period and review.
     */
    private function reviewItem(array $attributes = []): Item
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);

        return $this->createItem(array_merge(['type_id' => $type->id], $attributes));
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

    /**
     * Creates a version and submits it as `user-1` to the reviewer; the
     * current user reference is restored afterwards.
     */
    private function submitted(Item $item, string $reviewer, array $attributes = [], ?string $message = null): Version
    {
        $current = DummyUserProvider::$currentReference;
        DummyUserProvider::$currentReference = 'user-1';
        try {
            $version = $this->createVersion($item, array_merge(['valid_from' => '2099-01-01'], $attributes));
            $this->assertTrue(
                $version->submitForReview($reviewer, $message),
                'Version not submitted: ' . json_encode($version->getErrors())
            );
        } finally {
            DummyUserProvider::$currentReference = $current;
        }

        return $version;
    }

    /**
     * Adds the identities to the default reviewer options `user-1` to
     * `user-3`.
     */
    private function allowReviewers(TestIdentity ...$identities): void
    {
        $options = (new DummyUserProvider())->getReviewerOptions();
        foreach ($identities as $identity) {
            $options[$identity->uuid] = 'User ' . $identity->uuid;
        }
        DummyUserProvider::$reviewerOptions = $options;
    }
}
