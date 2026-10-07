<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the type management pages (`type/*`).
 */
class TypeControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'index' => ['GET', 'type/index'],
            'create' => ['GET', 'type/create'],
            'update' => ['GET', 'type/update'],
            'delete' => ['POST', 'type/delete'],
        ];
    }

    public function testGuestIsRedirectedToLoginOnAllActions(): void
    {
        $type = $this->createType();
        $this->loginAsGuest();

        foreach (self::routes() as [$method, $route]) {
            $this->assertNull($this->request($method, $route, ['id' => $type->id]), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testUsersWithoutAdminRoleAreForbidden(): void
    {
        $type = $this->createType();

        foreach ([null, Module::ROLE_EDITOR, Module::ROLE_REVIEWER] as $role) {
            $this->loginAs($role);
            foreach (self::routes() as [$method, $route]) {
                $this->assertForbidden($method, $route, ['id' => $type->id]);
            }
        }
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testAdminSeesPages(): void
    {
        $type = $this->createType(['name' => 'Guideline']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/index'));
        $this->assertStringContainsString('Guideline', $html);
        $this->assertSame('Types', Yii::$app->getView()->title);
        $this->assertSame('Types', Yii::$app->getView()->params['breadcrumbs'][1]);

        $html = $this->assertPage($this->get('type/create'));
        $this->assertStringContainsString('Create type', $html);
        $this->assertSame('Create type', Yii::$app->getView()->title);
        $this->assertSame('Create type', Yii::$app->getView()->params['breadcrumbs'][2]);

        $html = $this->assertPage($this->get('type/update', ['id' => $type->id]));
        $this->assertStringContainsString('value="Guideline"', $html);
        $this->assertSame('Edit type', Yii::$app->getView()->title);
        $this->assertSame(
            ['label' => 'Types', 'url' => ['index']],
            Yii::$app->getView()->params['breadcrumbs'][1]
        );
    }

    public function testDeleteRequiresPost(): void
    {
        $type = $this->createType();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertMethodNotAllowed('GET', 'type/delete', ['id' => $type->id]);
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testUnknownIdIsNotFound(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'type/update', ['id' => self::UNKNOWN_ID]);
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'type/update', ['id' => self::UNKNOWN_ID]);
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'type/delete', ['id' => self::UNKNOWN_ID]);
    }

    public function testEmptyListShowsHint(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/index'));

        $this->assertStringContainsString('callout callout-info', $html);
        $this->assertStringContainsString('No types yet. Create the first one with &quot;Create type&quot;.', $html);
        $this->assertStringNotContainsString('grid-view', $html);
        $this->assertStringContainsString('href="' . Url::to(['/knowledge-library/type/create']) . '"', $html);
    }

    public function testIndexIsTranslated(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/index'));
        $this->assertStringContainsString('Noch keine Typen vorhanden.', $html);

        $this->createType();
        $html = $this->assertPage($this->get('type/index'));
        $this->assertStringContainsString('Gültigkeitszeitraum', $html);
        $this->assertStringContainsString('Freigabe durch zweite Person', $html);
        $this->assertStringContainsString('Wissensobjekte', $html);
        $this->assertStringContainsString('Typ anlegen', $html);
    }

    public function testCreateFormHasCheckboxesWithUncheckValue(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/create'));

        foreach (['has_validity_period', 'requires_review'] as $attribute) {
            $this->assertStringContainsString('<input type="hidden" name="Type[' . $attribute . ']" value="0">', $html);
            $this->assertStringContainsString('type="checkbox" id="type-' . $attribute . '" name="Type[' . $attribute . ']" value="1"', $html);
        }
        $this->assertStringContainsString('Has a validity period (Valid From, Valid Until)', $html);
        $this->assertStringContainsString('Publication requires approval by a second person', $html);
        $this->assertStringContainsString('>Save</button>', $html);
    }

    public function testCreateFormStartsWithValidityPeriodButWithoutApproval(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/create'));

        $this->assertMatchesRegularExpression('/<input type="checkbox" id="type-has_validity_period"[^>]* checked>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input type="checkbox" id="type-requires_review"[^>]* checked>/', $html);
    }

    public function testCreateWithEmptyNameShowsError(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (['', '   '] as $name) {
            $html = $this->assertPage($this->post('type/create', ['Type' => ['name' => $name]]));

            $this->assertStringContainsString('has-error', $html);
            $this->assertStringContainsString('Name cannot be blank.', $html);
        }
        $this->assertSame(0, (int)Type::find()->count());
        $this->assertNull($this->getFlash('success'));
    }

    public function testCreateWithDuplicateNameShowsError(): void
    {
        $this->createType(['name' => 'Law']);
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (['Law', '  Law  '] as $name) {
            $html = $this->assertPage($this->post('type/create', ['Type' => ['name' => $name]]));

            $this->assertStringContainsString('has-error', $html);
            $this->assertStringContainsString('has already been taken', $html);
        }
        $this->assertSame(1, (int)Type::find()->count());
    }

    public function testCreateSavesAndRedirects(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/create', ['Type' => [
            'name' => '  Guideline ',
            'has_validity_period' => '0',
            'requires_review' => '1',
        ]]);

        $this->assertRedirectsTo(['type/index']);
        $this->assertSame('Type "Guideline" saved.', $this->getFlash('success'));
        $type = Type::findOne(['name' => 'Guideline']);
        $this->assertNotNull($type);
        $this->assertFalse((bool)$type->has_validity_period);
        $this->assertTrue((bool)$type->requires_review);
    }

    public function testSavedFlashIsTranslatedAndEncoded(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/create', ['Type' => ['name' => 'A <b>&</b>']]);

        $this->assertSame('Typ „A &lt;b&gt;&amp;&lt;/b&gt;“ gespeichert.', $this->getFlash('success'));
    }

    public function testRename(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/update', ['Type' => ['name' => 'Statute']], ['id' => $type->id]);

        $this->assertRedirectsTo(['type/index']);
        $this->assertSame('Type "Statute" saved.', $this->getFlash('success'));
        $this->assertSame('Statute', Type::findOne($type->id)->name);
        $this->assertSame(1, (int)Type::find()->count());
    }

    public function testRenameToOwnNameIsAllowedAndToOtherNameIsNot(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->createType(['name' => 'Statute']);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/update', ['Type' => ['name' => ' Law ']], ['id' => $type->id]);
        $this->assertRedirectsTo(['type/index']);

        $html = $this->assertPage($this->post('type/update', ['Type' => ['name' => 'Statute']], ['id' => $type->id]));
        $this->assertStringContainsString('has already been taken', $html);
        $this->assertSame('Law', Type::findOne($type->id)->name);
    }

    public function testCheckboxesCanBeSwitchedOffAndOn(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);
        $this->loginAs(Module::ROLE_ADMIN);

        // An unchecked checkbox only sends the hidden input.
        $this->post('type/update', ['Type' => [
            'name' => $type->name,
            'has_validity_period' => '0',
            'requires_review' => '0',
        ]], ['id' => $type->id]);
        $this->assertRedirectsTo(['type/index']);
        $type->refresh();
        $this->assertFalse((bool)$type->has_validity_period);
        $this->assertFalse((bool)$type->requires_review);

        $html = $this->assertPage($this->get('type/update', ['id' => $type->id]));
        $this->assertStringNotContainsString('checked', $html);

        $this->post('type/update', ['Type' => [
            'name' => $type->name,
            'has_validity_period' => '1',
            'requires_review' => '1',
        ]], ['id' => $type->id]);
        $type->refresh();
        $this->assertTrue((bool)$type->has_validity_period);
        $this->assertTrue((bool)$type->requires_review);

        $html = $this->assertPage($this->get('type/update', ['id' => $type->id]));
        $this->assertSame(2, substr_count($html, 'checked'));
    }

    public function testValidityPeriodCannotBeChangedOnceItemsHaveVersions(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);
        $this->createVersion($this->createItem(['type_id' => $type->id]));
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/update', ['id' => $type->id]));
        $this->assertMatchesRegularExpression('/<input type="checkbox" id="type-has_validity_period"[^>]* checked disabled>/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" id="type-requires_review"[^>]* checked>/', $html);
        $this->assertStringContainsString(Type::validityPeriodLockedMessage(), $html);

        // A manipulated request is rejected by the model, nothing is saved.
        $html = $this->assertPage($this->post('type/update', ['Type' => [
            'name' => $type->name,
            'has_validity_period' => '0',
            'requires_review' => '0',
        ]], ['id' => $type->id]));
        $this->assertStringContainsString(Type::validityPeriodLockedMessage(), $html);
        $type->refresh();
        $this->assertTrue((bool)$type->has_validity_period);
        $this->assertTrue((bool)$type->requires_review);

        // The review flag and the name are still editable; the disabled
        // checkbox sends no value and keeps the stored one.
        $this->post('type/update', ['Type' => [
            'name' => 'Renamed',
            'requires_review' => '0',
        ]], ['id' => $type->id]);
        $this->assertRedirectsTo(['type/index']);
        $type->refresh();
        $this->assertTrue((bool)$type->has_validity_period);
        $this->assertFalse((bool)$type->requires_review);
        $this->assertSame('Renamed', $type->name);
    }

    public function testDeleteUnusedType(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/delete', [], ['id' => $type->id]);

        $this->assertRedirectsTo(['type/index']);
        $this->assertSame('Type "Law" deleted.', $this->getFlash('success'));
        $this->assertNull(Type::findOne($type->id));
    }

    public function testDeleteTypeInUseIsBlocked(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->insertItem($type->id);
        $this->loginAs(Module::ROLE_ADMIN);

        $this->post('type/delete', [], ['id' => $type->id]);

        $this->assertSame(302, $this->getResponse()->getStatusCode());
        $this->assertRedirectsTo(['type/index']);
        $this->assertSame(
            'This type is used by knowledge items and cannot be deleted.',
            $this->getFlash('error')
        );
        $this->assertNull($this->getFlash('success'));
        $this->assertNotNull(Type::findOne($type->id));
    }

    public function testIndexRendersDeleteButtons(): void
    {
        $used = $this->createType(['name' => 'Used']);
        $this->insertItem($used->id);
        $unused = $this->createType(['name' => 'Unused']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/index'));

        $deleteUrl = Html::encode(Url::to(['/knowledge-library/type/delete', 'id' => $unused->id]));
        $this->assertMatchesRegularExpression(
            '/<a [^>]*href="' . preg_quote($deleteUrl, '/') . '"[^>]*data-method="post"[^>]*>/',
            $html
        );
        $this->assertStringContainsString('data-confirm="Delete type &quot;Unused&quot;?"', $html);
        $this->assertStringNotContainsString(
            Html::encode(Url::to(['/knowledge-library/type/delete', 'id' => $used->id])),
            $html
        );
        $this->assertSame(1, substr_count($html, ' disabled'));
        $this->assertMatchesRegularExpression(
            '/<span [^>]*title="In use, cannot be deleted"[^>]*><button [^>]*disabled[^>]*>/',
            $html
        );
        $this->assertStringContainsString(
            'href="' . Html::encode(Url::to(['/knowledge-library/type/update', 'id' => $used->id])) . '"',
            $html
        );
    }

    public function testIndexShowsFlagsAndCountLinks(): void
    {
        $type = $this->createType(['name' => 'Law', 'has_validity_period' => true, 'requires_review' => false]);
        $this->insertItem($type->id);
        $archived = $this->insertItem($type->id);
        Yii::$app->db->createCommand()
            ->update('{{%knowledge_library_item}}', ['is_archived' => 1], ['id' => $archived])
            ->execute();
        $this->insertItem($this->createType(['name' => 'Other'])->id);
        $empty = $this->createType(['name' => 'Empty']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('type/index'));

        $url = Url::to(['/knowledge-library/item/index', 'ItemSearch' => ['type_id' => $type->id, 'archived' => 'all']]);
        $this->assertStringContainsString('ItemSearch%5Btype_id%5D=' . $type->id, $url);
        $this->assertStringContainsString('ItemSearch%5Barchived%5D=all', $url);
        $this->assertStringContainsString('href="' . Html::encode($url) . '">2</a>', $html);
        $emptyUrl = Url::to(['/knowledge-library/item/index', 'ItemSearch' => ['type_id' => $empty->id, 'archived' => 'all']]);
        $this->assertStringContainsString('href="' . Html::encode($emptyUrl) . '">0</a>', $html);
        $this->assertMatchesRegularExpression('#<td>Law</td><td>Yes</td><td>No</td>#', $html);
        $this->assertLessThan(strpos($html, '<td>Law</td>'), strpos($html, '<td>Empty</td>'));
        $this->assertLessThan(strpos($html, '<td>Other</td>'), strpos($html, '<td>Law</td>'));
    }

    public function testIndexNeedsNoQueryPerRow(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);
        $this->insertItem($this->createType()->id);
        // Warm up schema and RBAC caches.
        $this->assertPage($this->get('type/index'));

        $few = $this->countQueries(fn () => $this->assertPage($this->get('type/index')));
        for ($i = 0; $i < 5; $i++) {
            $this->insertItem($this->createType()->id);
        }
        $many = $this->countQueries(fn () => $this->assertPage($this->get('type/index')));

        $this->assertSame($few, $many);
    }

    private function countQueries(callable $callback): int
    {
        $count = static fn () => count(Yii::getLogger()->getProfiling(['yii\db\Command::query']));
        $before = $count();
        $callback();

        return $count() - $before;
    }
}
