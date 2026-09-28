<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the topic management pages (`topic/*`).
 */
class TopicControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'index' => ['GET', 'topic/index'],
            'create' => ['GET', 'topic/create'],
            'update' => ['GET', 'topic/update'],
            'delete' => ['POST', 'topic/delete'],
        ];
    }

    public function testGuestIsRedirectedToLoginOnAllActions(): void
    {
        $topic = $this->createTopic();
        $this->loginAsGuest();

        foreach (self::routes() as [$method, $route]) {
            $this->assertNull($this->request($method, $route, ['id' => $topic->id]), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertNotNull(Topic::findOne($topic->id));
    }

    public function testUsersWithoutAdminRoleAreForbidden(): void
    {
        $topic = $this->createTopic();

        foreach ([null, Module::ROLE_EDITOR, Module::ROLE_REVIEWER] as $role) {
            $this->loginAs($role);
            foreach (self::routes() as [$method, $route]) {
                $this->assertForbidden($method, $route, ['id' => $topic->id]);
            }
        }
        $this->assertNotNull(Topic::findOne($topic->id));
    }

    public function testAdminSeesPages(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/index'));
        $this->assertStringContainsString('Forestry', $html);
        $this->assertSame('Topics', Yii::$app->getView()->title);
        $this->assertSame('Topics', Yii::$app->getView()->params['breadcrumbs'][1]);

        $html = $this->assertPage($this->get('topic/create'));
        $this->assertStringContainsString('Create topic', $html);
        $this->assertSame('Create topic', Yii::$app->getView()->title);
        $this->assertSame('Create topic', Yii::$app->getView()->params['breadcrumbs'][2]);

        $html = $this->assertPage($this->get('topic/update', ['id' => $topic->id]));
        $this->assertStringContainsString('value="Forestry"', $html);
        $this->assertSame('Edit topic', Yii::$app->getView()->title);
        $this->assertSame(
            ['label' => 'Topics', 'url' => ['index']],
            Yii::$app->getView()->params['breadcrumbs'][1]
        );
    }

    public function testDeleteRequiresPost(): void
    {
        $topic = $this->createTopic();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertMethodNotAllowed('GET', 'topic/delete', ['id' => $topic->id]);
        $this->assertNotNull(Topic::findOne($topic->id));
    }

    public function testUnknownIdIsNotFound(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'topic/update', ['id' => self::UNKNOWN_ID]);
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'topic/update', ['id' => self::UNKNOWN_ID]);
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'topic/delete', ['id' => self::UNKNOWN_ID]);
    }

    public function testEmptyListShowsHint(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/index'));

        $this->assertStringContainsString('callout callout-info', $html);
        $this->assertStringContainsString('No topics yet. Create the first one with &quot;Create topic&quot;.', $html);
        $this->assertStringNotContainsString('grid-view', $html);
        $this->assertStringContainsString('href="' . Url::to(['/knowledge-library/topic/create']) . '"', $html);
    }

    public function testIndexIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/index'));
        $this->assertStringContainsString('Noch keine Themen vorhanden.', $html);

        $this->createTopic();
        $html = $this->assertPage($this->get('topic/index'));
        $this->assertStringContainsString('Themen', $html);
        $this->assertStringContainsString('Wissensobjekte', $html);
        $this->assertStringContainsString('Thema anlegen', $html);
    }

    public function testCreateFormHasNameAndSave(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/create'));

        $this->assertStringContainsString('name="Topic[name]"', $html);
        $this->assertStringContainsString('>Save</button>', $html);
    }

    public function testCreateWithEmptyNameShowsError(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (['', '   '] as $name) {
            $html = $this->assertPage($this->post('topic/create', ['Topic' => ['name' => $name]]));

            $this->assertStringContainsString('has-error', $html);
            $this->assertStringContainsString('Name cannot be blank.', $html);
        }
        $this->assertSame(0, (int)Topic::find()->count());
        $this->assertNull($this->getFlash('success'));
    }

    public function testCreateWithDuplicateNameShowsError(): void
    {
        $this->createTopic(['name' => 'Forestry']);
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (['Forestry', '  Forestry  '] as $name) {
            $html = $this->assertPage($this->post('topic/create', ['Topic' => ['name' => $name]]));

            $this->assertStringContainsString('has-error', $html);
            $this->assertStringContainsString('has already been taken', $html);
        }
        $this->assertSame(1, (int)Topic::find()->count());
    }

    public function testCreateSavesAndRedirects(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('topic/create', ['Topic' => ['name' => '  Forestry ']]);

        $this->assertRedirectsTo(['topic/index']);
        $this->assertSame('Topic "Forestry" saved.', $this->getFlash('success'));
        $this->assertNotNull(Topic::findOne(['name' => 'Forestry']));
    }

    public function testSavedFlashIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('topic/create', ['Topic' => ['name' => 'Forst']]);

        $this->assertSame('Thema „Forst“ gespeichert.', $this->getFlash('success'));
    }

    public function testRename(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('topic/update', ['Topic' => ['name' => 'Hunting']], ['id' => $topic->id]);

        $this->assertRedirectsTo(['topic/index']);
        $this->assertSame('Topic "Hunting" saved.', $this->getFlash('success'));
        $this->assertSame('Hunting', Topic::findOne($topic->id)->name);
        $this->assertSame(1, (int)Topic::find()->count());
    }

    public function testRenameToExistingNameShowsError(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->createTopic(['name' => 'Hunting']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->post('topic/update', ['Topic' => ['name' => ' Hunting']], ['id' => $topic->id]));

        $this->assertStringContainsString('has already been taken', $html);
        $this->assertSame('Forestry', Topic::findOne($topic->id)->name);
    }

    public function testDeleteUnusedTopic(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('topic/delete', [], ['id' => $topic->id]);

        $this->assertRedirectsTo(['topic/index']);
        $this->assertSame('Topic "Forestry" deleted.', $this->getFlash('success'));
        $this->assertNull(Topic::findOne($topic->id));
    }

    public function testDeleteTopicInUseIsBlocked(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $this->assignTopic($this->insertItem($this->createType()->id), $topic->id);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('topic/delete', [], ['id' => $topic->id]);

        $this->assertRedirectsTo(['topic/index']);
        $this->assertSame(
            'This topic is used by knowledge items and cannot be deleted.',
            $this->getFlash('error')
        );
        $this->assertNull($this->getFlash('success'));
        $this->assertNotNull(Topic::findOne($topic->id));
    }

    public function testIndexRendersDeleteButtons(): void
    {
        $used = $this->createTopic(['name' => 'Used']);
        $this->assignTopic($this->insertItem($this->createType()->id), $used->id);
        $unused = $this->createTopic(['name' => 'Unused']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/index'));

        $deleteUrl = Html::encode(Url::to(['/knowledge-library/topic/delete', 'id' => $unused->id]));
        $this->assertMatchesRegularExpression(
            '/<a [^>]*href="' . preg_quote($deleteUrl, '/') . '"[^>]*data-method="post"[^>]*>/',
            $html
        );
        $this->assertStringContainsString('data-confirm="Delete topic &quot;Unused&quot;?"', $html);
        $this->assertStringNotContainsString(
            Html::encode(Url::to(['/knowledge-library/topic/delete', 'id' => $used->id])),
            $html
        );
        $this->assertSame(1, substr_count($html, ' disabled'));
        $this->assertMatchesRegularExpression(
            '/<span [^>]*title="In use, cannot be deleted"[^>]*><button [^>]*disabled[^>]*>/',
            $html
        );
    }

    public function testIndexShowsCountLinks(): void
    {
        $topic = $this->createTopic(['name' => 'Forestry']);
        $type = $this->createType();
        $this->assignTopic($this->insertItem($type->id), $topic->id);
        $archived = $this->insertItem($type->id);
        Yii::$app->db->createCommand()
            ->update('{{%knowledge_library_item}}', ['is_archived' => 1], ['id' => $archived])
            ->execute();
        $this->assignTopic($archived, $topic->id);
        $other = $this->createTopic(['name' => 'Hunting']);
        $this->assignTopic($archived, $other->id);
        $empty = $this->createTopic(['name' => 'Empty']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('topic/index'));

        $url = Url::to(['/knowledge-library/item/index', 'ItemSearch' => ['topicIds' => [$topic->id], 'archived' => 'all']]);
        $this->assertStringContainsString('ItemSearch%5BtopicIds%5D%5B0%5D=' . $topic->id, $url);
        $this->assertStringContainsString('ItemSearch%5Barchived%5D=all', $url);
        $this->assertStringContainsString('href="' . Html::encode($url) . '">2</a>', $html);
        $otherUrl = Url::to(['/knowledge-library/item/index', 'ItemSearch' => ['topicIds' => [$other->id], 'archived' => 'all']]);
        $this->assertStringContainsString('href="' . Html::encode($otherUrl) . '">1</a>', $html);
        $emptyUrl = Url::to(['/knowledge-library/item/index', 'ItemSearch' => ['topicIds' => [$empty->id], 'archived' => 'all']]);
        $this->assertStringContainsString('href="' . Html::encode($emptyUrl) . '">0</a>', $html);
        $this->assertLessThan(strpos($html, '<td>Forestry</td>'), strpos($html, '<td>Empty</td>'));
        $this->assertLessThan(strpos($html, '<td>Hunting</td>'), strpos($html, '<td>Forestry</td>'));
    }

    public function testIndexNeedsNoQueryPerRow(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);
        $type = $this->createType();
        $this->assignTopic($this->insertItem($type->id), $this->createTopic()->id);
        // Warm up schema and RBAC caches.
        $this->assertPage($this->get('topic/index'));

        $few = $this->countQueries(fn () => $this->assertPage($this->get('topic/index')));
        for ($i = 0; $i < 5; $i++) {
            $this->assignTopic($this->insertItem($type->id), $this->createTopic()->id);
        }
        $many = $this->countQueries(fn () => $this->assertPage($this->get('topic/index')));

        $this->assertSame($few, $many);
    }

    private function assignTopic(string $itemId, string $topicId): void
    {
        Yii::$app->db->createCommand()->insert('{{%knowledge_library_item_topic}}', [
            'item_id' => $itemId,
            'topic_id' => $topicId,
        ])->execute();
    }

    private function countQueries(callable $callback): int
    {
        $count = static fn () => count(Yii::getLogger()->getProfiling(['yii\db\Command::query']));
        $before = $count();
        $callback();

        return $count() - $before;
    }
}
