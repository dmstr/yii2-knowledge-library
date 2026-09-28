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
 * Tests of archiving and restoring items (`item/archive`, `item/restore`)
 * and of the locks of archived items.
 */
class ArchiveControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLogin(): void
    {
        $item = $this->createItem();
        $this->loginAsGuest();

        foreach (['item/archive', 'item/restore'] as $route) {
            $this->assertNull($this->post($route, [], ['id' => $item->id]), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertFalse((bool)Item::findOne($item->id)->is_archived);
    }

    public function testOnlyAdminsMayArchiveAndRestore(): void
    {
        $item = $this->createItem();

        foreach ([null, Module::ROLE_EDITOR, Module::ROLE_REVIEWER] as $role) {
            $this->loginAs($role);
            $this->assertForbidden('POST', 'item/archive', ['id' => $item->id]);
            $this->assertForbidden('POST', 'item/restore', ['id' => $item->id]);
        }
        $this->assertFalse((bool)Item::findOne($item->id)->is_archived);
    }

    public function testArchiveAndRestoreRequirePost(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertMethodNotAllowed('GET', 'item/archive', ['id' => $item->id]);
        $this->assertMethodNotAllowed('GET', 'item/restore', ['id' => $item->id]);
        $this->assertFalse((bool)Item::findOne($item->id)->is_archived);
    }

    public function testUnknownItemIsNotFound(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'item/archive', ['id' => self::UNKNOWN_ID]);
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'item/restore', ['id' => self::UNKNOWN_ID]);
    }

    public function testArchiveLocksNewVersionsAndRestoreUnlocks(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $admin = $this->loginAs(Module::ROLE_ADMIN);

        $this->post('item/archive', [], ['id' => $item->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Knowledge object archived.', $this->getFlash('success'));
        $this->assertTrue((bool)Item::findOne($item->id)->is_archived);
        $entry = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_ARCHIVED]);
        $this->assertNotNull($entry);
        $this->assertSame($admin->uuid, $entry->actor_id);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('knowledge-library-badge-archived', $html);
        $this->assertStringNotContainsString('knowledge-library-item-new-version', $html);
        $this->assertStringNotContainsString('knowledge-library-item-archive', $html);
        $this->assertStringNotContainsString('knowledge-library-version-correct', $html);
        $restoreUrl = Html::encode(Url::to(['/knowledge-library/item/restore', 'id' => $item->id]));
        $this->assertMatchesRegularExpression(
            '#<a class="btn btn-default knowledge-library-item-restore" href="' . preg_quote($restoreUrl, '#')
            . '" data-method="post" data-confirm="Restore &quot;Guideline&quot;\?">#',
            $html
        );

        $list = $this->assertPage($this->get('item/index', ['ItemSearch' => ['archived' => 'all', 'title' => 'Guideline']]));
        $this->assertMatchesRegularExpression('#knowledge-library-state-archived"[^>]*>Archived</span>#', $list);

        // No new version for archived items.
        $this->post('version/create', [], ['itemId' => $item->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));
        $this->assertSame(1, (int)Version::find()->forItem($item->id)->count());

        $this->post('item/restore', [], ['id' => $item->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Knowledge object restored.', $this->getFlash('success'));
        $this->assertFalse((bool)Item::findOne($item->id)->is_archived);
        $this->assertNotNull(History::findOne(['item_id' => $item->id, 'action' => History::ACTION_RESTORED]));

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id, 'tab' => 'history']));
        $this->assertStringNotContainsString('knowledge-library-badge-archived', $html);
        $this->assertStringContainsString('knowledge-library-item-new-version', $html);
        $this->assertStringContainsString('knowledge-library-item-archive', $html);
        $this->assertStringContainsString('knowledge-library-version-correct', $html);
        $this->assertStringContainsString('>Archived</td>', $html);
        $this->assertStringContainsString('>Restored</td>', $html);
    }

    public function testArchiveKeepsTheReason(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('item/archive', ['reason' => ' Replaced by the new guideline '], ['id' => $item->id]);

        $entry = History::findOne(['item_id' => $item->id, 'action' => History::ACTION_ARCHIVED]);
        $this->assertSame('Replaced by the new guideline', $entry->reason);
    }

    public function testArchiveTwiceAndRestoreOfActiveItemShowErrors(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('item/restore', [], ['id' => $item->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object is not archived.', $this->getFlash('error'));

        $this->post('item/archive', [], ['id' => $item->id]);
        $this->post('item/archive', [], ['id' => $item->id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));
        $this->assertSame(1, (int)History::find()->where(['item_id' => $item->id, 'action' => History::ACTION_ARCHIVED])->count());
    }

    public function testArchivedItemKeepsDraftDiscardAndWithdrawal(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => false]);
        $item = $this->createItem(['type_id' => $type->id]);
        $version = $this->createPublishedVersion($item, ['valid_from' => '2020-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2099-01-01']);
        $this->assertTrue($item->archive());
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringNotContainsString('knowledge-library-item-continue-draft', $html);
        $this->assertStringContainsString('knowledge-library-item-discard-draft', $html);
        $this->assertStringContainsString('knowledge-library-version-withdraw', $html);
        $this->assertStringNotContainsString('knowledge-library-item-archive', $html);
        $this->assertStringNotContainsString('knowledge-library-item-restore', $html);

        $this->get('version/update', ['id' => $draft->id, 'step' => 1]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object is archived.', $this->getFlash('error'));

        $this->post('version/discard', [], ['id' => $draft->id]);
        $this->assertNull(Version::findOne($draft->id));

        $this->post('version/withdraw', ['reason' => 'Obsolete', 'successor' => Version::WITHDRAW_NONE], ['id' => $version->id]);
        $this->assertSame(Version::STATUS_WITHDRAWN, Version::findOne($version->id)->status);
    }

    public function testArchiveButtonsAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->createItem(['title' => 'Leitlinie']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('Archivieren</a>', $html);
        $this->assertStringContainsString(Html::encode('„Leitlinie“ archivieren? Solange es archiviert ist, können keine neuen Versionen angelegt werden.'), $html);

        $this->post('item/archive', [], ['id' => $item->id]);
        $this->assertSame('Wissensobjekt archiviert.', $this->getFlash('success'));
        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('Wiederherstellen</a>', $html);
    }
}
