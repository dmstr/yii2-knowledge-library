<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the relations of an item (`relation/*`) and the tab relations of
 * the detail page.
 */
class RelationControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    public function testGuestIsRedirectedToLogin(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $relation = $this->createRelation($source, $target);
        $this->loginAsGuest();

        $this->assertNull($this->post('relation/create', $this->body($target), ['itemId' => $source->id]));
        $this->assertRedirectsToLogin();
        $this->assertNull($this->post('relation/delete', [], ['id' => $relation->id]));
        $this->assertRedirectsToLogin();
        $this->assertSame(1, (int)Relation::find()->count());
    }

    public function testUserWithoutRoleIsForbidden(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $relation = $this->createRelation($source, $target);
        $this->loginAs();

        $this->assertForbidden('POST', 'relation/create', ['itemId' => $source->id], $this->body($target, Relation::TYPE_REPLACES));
        $this->assertForbidden('POST', 'relation/delete', ['id' => $relation->id]);
        $this->assertSame(1, (int)Relation::find()->count());
    }

    public function testEditorReviewerAndAdminMayAddAndRemove(): void
    {
        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER, Module::ROLE_ADMIN] as $role) {
            [$source, $target] = [$this->createItem(), $this->createItem()];
            $this->loginAs($role);

            $this->post('relation/create', $this->body($target), ['itemId' => $source->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $source->id, 'tab' => 'relations']);
            $relation = Relation::findOne(['source_item_id' => $source->id]);
            $this->assertNotNull($relation, $role);

            $this->post('relation/delete', [], ['id' => $relation->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $source->id, 'tab' => 'relations']);
            $this->assertNull(Relation::findOne($relation->id), $role);
        }
    }

    public function testRoutesRequirePost(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $relation = $this->createRelation($source, $target);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertMethodNotAllowed('GET', 'relation/create', ['itemId' => $source->id]);
        $this->assertMethodNotAllowed('GET', 'relation/delete', ['id' => $relation->id]);
        $this->assertNotNull(Relation::findOne($relation->id));
    }

    public function testCreateAddsOutgoingRelation(): void
    {
        [$source, $target, $other] = [$this->createItem(), $this->createItem(), $this->createItem()];
        $this->loginAs(Module::ROLE_EDITOR);

        // The source is always the item of the page.
        $this->post(
            'relation/create',
            ['Relation' => ['type' => Relation::TYPE_BASED_ON, 'target_item_id' => $target->id, 'source_item_id' => $other->id]],
            ['itemId' => $source->id]
        );

        $this->assertSame('Relation added.', $this->getFlash('success'));
        $relation = Relation::findOne(['target_item_id' => $target->id]);
        $this->assertNotNull($relation);
        $this->assertSame($source->id, $relation->source_item_id);
        $this->assertSame(Relation::TYPE_BASED_ON, $relation->type);
    }

    public function testInvalidRelationsAreRejectedWithFlash(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $this->createRelation($source, $target);
        $this->loginAs(Module::ROLE_EDITOR);

        $cases = [
            'duplicate' => [$this->body($target), 'This relation already exists.'],
            'self' => [$this->body($source), 'An item cannot be related to itself.'],
            'unknown target' => [['Relation' => ['type' => Relation::TYPE_SUPPLEMENTS, 'target_item_id' => self::UNKNOWN_ID]], null],
            'no target' => [['Relation' => ['type' => Relation::TYPE_SUPPLEMENTS, 'target_item_id' => '']], null],
            'unknown type' => [$this->body($target, 'contradicts'), null],
            'no body' => [[], null],
        ];
        foreach ($cases as $case => [$body, $message]) {
            $this->post('relation/create', $body, ['itemId' => $source->id]);
            $this->assertRedirectsTo(['item/view', 'id' => $source->id, 'tab' => 'relations']);
            $error = $this->getFlash('error');
            $this->assertIsString($error, $case);
            $this->assertNotSame('', $error, $case);
            if ($message !== null) {
                $this->assertSame($message, $error, $case);
            }
            $this->assertNull($this->getFlash('success'), $case);
        }

        $this->assertSame(1, (int)Relation::find()->count());
    }

    public function testUnknownItemOrRelationIsNotFound(): void
    {
        $target = $this->createItem();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'relation/create', ['itemId' => self::UNKNOWN_ID], $this->body($target));
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'relation/delete', ['id' => self::UNKNOWN_ID]);
        $this->assertSame(0, (int)Relation::find()->count());
    }

    public function testTabShowsOutgoingAndIncomingRelations(): void
    {
        $source = $this->createItem(['title' => 'Source <item>']);
        $target = $this->createItem(['title' => 'Target <item>']);
        $relation = $this->createRelation($source, $target, Relation::TYPE_SUPPLEMENTS);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $source->id, 'tab' => 'relations']));
        $this->assertStringContainsString('class="tab-pane active" id="knowledge-library-tab-relations"', $html);
        $this->assertMatchesRegularExpression(
            '#knowledge-library-relations-outgoing.*?>Supplements</span>.*?>Target &lt;item&gt;</a>.*?'
            . preg_quote(Html::encode(Url::to(['/knowledge-library/relation/delete', 'id' => $relation->id])), '#')
            . '"[^>]*data-method="post"#s',
            $html
        );
        $this->assertMatchesRegularExpression('#knowledge-library-relations-incoming.*?None\.</div>#s', $html);

        $html = $this->assertPage($this->get('item/view', ['id' => $target->id, 'tab' => 'relations']));
        $this->assertMatchesRegularExpression(
            '#knowledge-library-relations-incoming.*?>Is supplemented by</span>.*?'
            . preg_quote(Html::encode(Url::to(['/knowledge-library/item/view', 'id' => $source->id])), '#')
            . '">Source &lt;item&gt;</a>#s',
            $html
        );
        $this->assertMatchesRegularExpression('#knowledge-library-relations-outgoing.*?No relations\.</div>#s', $html);
        // Incoming relations cannot be removed on the target.
        $this->assertStringNotContainsString('relation%2Fdelete', $html);
    }

    public function testInverseLabelsAreCapitalized(): void
    {
        $target = $this->createItem();
        $expected = [
            Relation::TYPE_BASED_ON => 'Is the basis for',
            Relation::TYPE_SUPPLEMENTS => 'Is supplemented by',
            Relation::TYPE_REPLACES => 'Is replaced by',
        ];
        foreach (array_keys($expected) as $type) {
            $this->createRelation($this->createItem(), $target, $type);
        }
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $target->id, 'tab' => 'relations']));

        foreach ($expected as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html);
        }

        Yii::$app->language = 'de';
        $html = $this->assertPage($this->get('item/view', ['id' => $target->id, 'tab' => 'relations']));
        foreach (['Ist Grundlage für', 'Wird ergänzt durch', 'Wird ersetzt durch', 'Ausgehend', 'Eingehend', 'Keine Beziehungen.', 'Hinzufügen'] as $text) {
            $this->assertStringContainsString(Html::encode($text), $html);
        }
        foreach (['Basiert auf', 'Ergänzt', 'Ersetzt'] as $type) {
            $this->assertStringContainsString('>' . $type . '</option>', $html);
        }
    }

    public function testTargetSelectExcludesItselfAndLinkedItems(): void
    {
        $source = $this->createItem(['title' => 'A source']);
        $linked = $this->createItem(['title' => 'B linked']);
        $incoming = $this->createItem(['title' => 'C incoming']);
        $free = $this->createItem(['title' => 'D free']);
        $this->createRelation($source, $linked);
        $this->createRelation($incoming, $source);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $source->id, 'tab' => 'relations']));

        $this->assertSame(1, preg_match('#<select id="knowledge-library-relation-target"[^>]*>(.*?)</select>#s', $html, $matches));
        $options = $matches[1];
        $this->assertStringContainsString('value="' . $free->id . '"', $options);
        $this->assertStringContainsString('value="' . $incoming->id . '"', $options);
        $this->assertStringNotContainsString('value="' . $source->id . '"', $options);
        $this->assertStringNotContainsString('value="' . $linked->id . '"', $options);
        $this->assertStringContainsString('<option value="">Select knowledge object</option>', $options);

        $this->assertStringContainsString(
            'action="' . Html::encode(Url::to(['/knowledge-library/relation/create', 'itemId' => $source->id])) . '" method="post"',
            $html
        );
    }

    public function testDeleteOnlyRemovesTheRelation(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $relation = $this->createRelation($source, $target);
        $other = $this->createRelation($target, $source);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('relation/delete', [], ['id' => $relation->id]);

        $this->assertSame('Relation removed.', $this->getFlash('success'));
        $this->assertNull(Relation::findOne($relation->id));
        $this->assertNotNull(Relation::findOne($other->id));
        $this->assertNotNull(Item::findOne($source->id));
        $this->assertNotNull(Item::findOne($target->id));
    }

    public function testAddAndRemoveWriteHistoryForBothItems(): void
    {
        $source = $this->createItem(['title' => 'Forest <law>']);
        $target = $this->createItem(['title' => 'Water act']);
        $editor = $this->loginAs(Module::ROLE_EDITOR);

        $this->post('relation/create', $this->body($target), ['itemId' => $source->id]);
        $relation = Relation::findOne(['source_item_id' => $source->id]);

        $this->assertHistory($source, History::ACTION_RELATION_ADDED, 'forward', 'Water act');
        $this->assertHistory($target, History::ACTION_RELATION_ADDED, 'inverse', 'Forest <law>');
        $this->assertSame($editor->uuid, History::findOne(['item_id' => $source->id])->actor_id);

        $html = $this->assertPage($this->get('item/view', ['id' => $source->id, 'tab' => 'history']));
        $this->assertStringContainsString('<td class="knowledge-library-history-what">Relation added: supplements Water act</td>', $html);
        $html = $this->assertPage($this->get('item/view', ['id' => $target->id, 'tab' => 'history']));
        $this->assertStringContainsString('<td class="knowledge-library-history-what">Relation added: is supplemented by Forest &lt;law&gt;</td>', $html);

        $this->post('relation/delete', [], ['id' => $relation->id]);

        $this->assertHistory($source, History::ACTION_RELATION_REMOVED, 'forward', 'Water act');
        $this->assertHistory($target, History::ACTION_RELATION_REMOVED, 'inverse', 'Forest <law>');
        $html = $this->assertPage($this->get('item/view', ['id' => $source->id, 'tab' => 'history']));
        $this->assertStringContainsString('Relation removed: supplements Water act', $html);

        Yii::$app->language = 'de';
        $html = $this->assertPage($this->get('item/view', ['id' => $target->id, 'tab' => 'history']));
        $this->assertStringContainsString('Beziehung entfernt: wird ergänzt durch Forest &lt;law&gt;', $html);
    }

    public function testRejectedRelationWritesNoHistory(): void
    {
        [$source, $target] = [$this->createItem(), $this->createItem()];
        $this->createRelation($source, $target);
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('relation/create', $this->body($target), ['itemId' => $source->id]);
        $this->post('relation/create', $this->body($source), ['itemId' => $source->id]);

        $this->assertSame(0, (int)History::find()->count());
    }

    /**
     * Asserts exactly one history entry of the action for the item with the
     * relation details.
     */
    private function assertHistory(Item $item, string $action, string $direction, string $title): void
    {
        $entries = History::findAll(['item_id' => $item->id, 'action' => $action]);
        $this->assertCount(1, $entries, $action);
        $this->assertSame(
            ['relation' => Relation::TYPE_SUPPLEMENTS, 'direction' => $direction, 'target' => $title],
            $entries[0]->getDetails()
        );
        $this->assertNull($entries[0]->version_id);
    }

    private function body(Item $target, string $type = Relation::TYPE_SUPPLEMENTS): array
    {
        return ['Relation' => ['type' => $type, 'target_item_id' => $target->id]];
    }

    private function createRelation(Item $source, Item $target, string $type = Relation::TYPE_SUPPLEMENTS): Relation
    {
        $relation = new Relation([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'type' => $type,
        ]);
        $this->assertTrue($relation->save(), 'Relation not saved: ' . json_encode($relation->getErrors()));

        return $relation;
    }
}
