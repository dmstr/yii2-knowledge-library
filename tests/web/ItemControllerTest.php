<?php

namespace dmstr\knowledgeLibrary\tests\web;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\models\Topic;
use dmstr\knowledgeLibrary\models\Type;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\WebTestCase;
use Yii;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\Query;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the item library pages (`item/*`).
 */
class ItemControllerTest extends WebTestCase
{
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private const ROW_CLASS = 'class="knowledge-library-item-row"';

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'index' => ['GET', 'item/index'],
            'create' => ['GET', 'item/create'],
            'view' => ['GET', 'item/view'],
            'update' => ['GET', 'item/update'],
            'source' => ['GET', 'item/source'],
            'delete' => ['POST', 'item/delete'],
        ];
    }

    public function testGuestIsRedirectedToLoginOnAllActions(): void
    {
        $item = $this->createItem();
        $this->loginAsGuest();

        foreach (self::routes() as [$method, $route]) {
            $this->assertNull($this->request($method, $route, ['id' => $item->id]), $route);
            $this->assertRedirectsToLogin();
        }
        $this->assertNotNull(Item::findOne($item->id));
    }

    public function testUserWithoutRoleIsForbiddenOnAllActions(): void
    {
        $item = $this->createItem();
        $this->loginAs();

        foreach (self::routes() as [$method, $route]) {
            $this->assertForbidden($method, $route, ['id' => $item->id]);
        }
        $this->assertNotNull(Item::findOne($item->id));
    }

    public function testEditorAndReviewerMayUseAllPagesButDelete(): void
    {
        $item = $this->createItem();

        foreach ([Module::ROLE_EDITOR, Module::ROLE_REVIEWER] as $role) {
            $this->loginAs($role);
            foreach (self::routes() as $action => [$method, $route]) {
                if ($action === 'delete') {
                    $this->assertForbidden($method, $route, ['id' => $item->id]);
                    // Access is checked before the verb filter.
                    $this->assertForbidden('GET', $route, ['id' => $item->id]);
                    continue;
                }
                $this->assertPage($this->request($method, $route, ['id' => $item->id]));
            }

            $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
            $this->assertStringNotContainsString('knowledge-library-item-delete', $html, $role);
            $this->assertStringNotContainsString('data-method="post"', $html, $role);
        }
        $this->assertNotNull(Item::findOne($item->id));
    }

    public function testAdminMayUseAllActions(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (self::routes() as $action => [$method, $route]) {
            if ($action !== 'delete') {
                $this->assertPage($this->request($method, $route, ['id' => $item->id]));
            }
        }

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $deleteUrl = Html::encode(Url::to(['/knowledge-library/item/delete', 'id' => $item->id]));
        $this->assertMatchesRegularExpression(
            '/<a [^>]*href="' . preg_quote($deleteUrl, '/') . '"[^>]*data-method="post"[^>]*>/',
            $html
        );

        $this->post('item/delete', [], ['id' => $item->id]);
        $this->assertRedirectsTo(['item/index']);
        $this->assertNull(Item::findOne($item->id));
    }

    public function testDeleteRequiresPost(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);

        $this->assertMethodNotAllowed('GET', 'item/delete', ['id' => $item->id]);
        $this->assertNotNull(Item::findOne($item->id));
    }

    public function testUnknownIdIsNotFound(): void
    {
        $this->loginAs(Module::ROLE_ADMIN);

        foreach (['view', 'update', 'source'] as $action) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', "item/$action", ['id' => self::UNKNOWN_ID]);
        }
        $this->assertHttpException(NotFoundHttpException::class, 'POST', 'item/delete', ['id' => self::UNKNOWN_ID]);
    }

    public function testModuleUrlOpensTheList(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get(''));

        $this->assertStringContainsString('Create knowledge object', $html);
        $this->assertSame('item', Yii::$app->controller->id);
        $this->assertSame('index', Yii::$app->controller->action->id);
    }

    public function testIndexPageTitleAndBreadcrumbs(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $this->assertMatchesRegularExpression('#<h1>\s*Knowledge Library\s*<small>List</small>\s*</h1>#', $html);
        $this->assertSame('Knowledge Library', Yii::$app->getView()->title);
        $this->assertSame(
            [
                ['label' => 'Knowledge Library', 'url' => ['/knowledge-library/item/index']],
                'List',
            ],
            Yii::$app->getView()->params['breadcrumbs']
        );
        $this->assertStringContainsString(
            'href="' . Url::to(['/knowledge-library/item/create']) . '"',
            $html
        );
    }

    public function testEmptyLibraryShowsHint(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringContainsString('callout callout-info', $html);
        $this->assertStringContainsString(
            'No knowledge objects yet. Create the first one with &quot;Create knowledge object&quot;.',
            $html
        );
        $this->assertStringNotContainsString('grid-view', $html);
        $this->assertStringNotContainsString('No entries for these filters.', $html);
    }

    public function testNoHitsForFiltersShowsTableRow(): void
    {
        $this->createItem(['title' => 'Guideline']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['title' => 'nothing matches']]));

        $this->assertStringNotContainsString('callout callout-info', $html);
        $this->assertStringContainsString('grid-view', $html);
        $this->assertMatchesRegularExpression(
            '#<tr><td colspan="4"><div class="text-muted">No entries for these filters.</div></td></tr>#',
            $html
        );
        // The filter keeps its value.
        $this->assertStringContainsString('value="nothing matches"', $html);
    }

    public function testArchivedItemsOnlyWithoutHitsShowTableRow(): void
    {
        $this->createItem(['title' => 'Old', 'is_archived' => true]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringNotContainsString('callout callout-info', $html);
        $this->assertStringContainsString('No entries for these filters.', $html);
    }

    public function testDefaultFilterHidesArchivedAndAllShowsThemStruckThrough(): void
    {
        $active = $this->createItem(['title' => 'Active item']);
        $archived = $this->createItem(['title' => 'Archived item', 'is_archived' => true]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));
        $this->assertStringContainsString('Active item', $html);
        $this->assertStringNotContainsString('Archived item', $html);
        $this->assertMatchesRegularExpression('#<option value="active" selected>Active</option>#', $html);

        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['archived' => 'all']]));
        $this->assertStringContainsString('Active item', $html);
        $this->assertMatchesRegularExpression(
            '#<a class="knowledge-library-archived" href="'
            . preg_quote(Html::encode(Url::to(['/knowledge-library/item/view', 'id' => $archived->id])), '#')
            . '" style="color: \#999; text-decoration: line-through">Archived item</a>#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<a href="'
            . preg_quote(Html::encode(Url::to(['/knowledge-library/item/view', 'id' => $active->id])), '#')
            . '">Active item</a>#',
            $html
        );

        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['archived' => 'archived']]));
        $this->assertStringNotContainsString('Active item', $html);
        $this->assertStringContainsString('Archived item', $html);
    }

    public function testRowsLinkToTheDetailPage(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $url = Html::encode(Url::to(['/knowledge-library/item/view', 'id' => $item->id]));
        $this->assertStringContainsString('<tr ' . self::ROW_CLASS . ' data-href="' . $url . '"', $html);
        $this->assertStringContainsString('<a href="' . $url . '">Guideline</a>', $html);
    }

    public function testTitleFilterTreatsWildcardsLiterally(): void
    {
        $this->createItem(['title' => 'Rate 100% binding']);
        $this->createItem(['title' => 'Rate 1000 binding']);
        $this->createItem(['title' => 'Key a_b']);
        $this->createItem(['title' => 'Key axb']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['title' => '100%']]));
        $this->assertStringContainsString('Rate 100% binding', $html);
        $this->assertStringNotContainsString('Rate 1000 binding', $html);
        $this->assertSame(1, substr_count($html, self::ROW_CLASS));

        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['title' => 'a_b']]));
        $this->assertStringContainsString('Key a_b', $html);
        $this->assertStringNotContainsString('Key axb', $html);
        $this->assertSame(1, substr_count($html, self::ROW_CLASS));
    }

    public function testTwoTopicFilterReturnsItemOnce(): void
    {
        $law = $this->createTopic(['name' => 'Law']);
        $forest = $this->createTopic(['name' => 'Forest']);
        $other = $this->createTopic(['name' => 'Other']);
        $both = $this->createItem(['title' => 'Both topics', 'topicIds' => [$law->id, $forest->id]]);
        $this->createItem(['title' => 'Only law', 'topicIds' => [$law->id]]);
        $this->createItem(['title' => 'Other topic', 'topicIds' => [$other->id]]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', [
            'ItemSearch' => ['topicIds' => [$law->id, $forest->id]],
        ]));

        $this->assertSame(2, substr_count($html, self::ROW_CLASS));
        $this->assertSame(1, substr_count($html, '>Both topics</a>'));
        $this->assertStringContainsString('>Only law</a>', $html);
        $this->assertStringNotContainsString('>Other topic</a>', $html);
        // Topic names of the item, sorted.
        $this->assertStringContainsString('<td>Forest, Law</td>', $html);
        $this->assertNotNull($both);
    }

    public function testTopicFilterIsASelect2MultiSelect(): void
    {
        $this->createTopic(['name' => 'Zeta']);
        $this->createTopic(['name' => 'Alpha']);
        $this->createItem();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $this->assertMatchesRegularExpression(
            '#<select id="knowledge-library-item-topic-filter"[^>]* name="ItemSearch\[topicIds\]\[\]" multiple[^>]*>#',
            $html
        );
        $this->assertLessThan(strpos($html, '>Zeta</option>'), strpos($html, '>Alpha</option>'));
        $this->assertStringContainsString('select2', $html);
    }

    public function testLastChangeSortPutsLaterVersionChangeFirst(): void
    {
        $first = $this->createItem(['title' => 'Changed by version']);
        $second = $this->createItem(['title' => 'Changed itself']);
        $version = $this->createVersion($first);
        $db = Yii::$app->db;
        $db->createCommand()->update(Item::tableName(), ['updated_at' => '2026-01-01 10:00:00'], ['id' => $first->id])->execute();
        $db->createCommand()->update(Item::tableName(), ['updated_at' => '2026-02-01 10:00:00'], ['id' => $second->id])->execute();
        $db->createCommand()->update(Version::tableName(), ['updated_at' => '2026-03-01 10:00:00'], ['id' => $version->id])->execute();
        $this->loginAs(Module::ROLE_EDITOR);

        // Default order: last change descending.
        $html = $this->assertPage($this->get('item/index'));
        $this->assertLessThan(strpos($html, '>Changed itself</a>'), strpos($html, '>Changed by version</a>'));

        $html = $this->assertPage($this->get('item/index', ['sort' => 'lastChange']));
        $this->assertLessThan(strpos($html, '>Changed by version</a>'), strpos($html, '>Changed itself</a>'));

        $html = $this->assertPage($this->get('item/index', ['sort' => 'title']));
        $this->assertLessThan(strpos($html, '>Changed itself</a>'), strpos($html, '>Changed by version</a>'));
    }

    public function testHeaderHasSortLinksForTitleAndLastChange(): void
    {
        $this->createItem();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index'));

        $this->assertMatchesRegularExpression('#<a href="[^"]*sort=title"[^>]*>Title</a>#', $html);
        // Currently sorted by last change descending, the link switches to ascending.
        $this->assertMatchesRegularExpression(
            '#In Force<small class="knowledge-library-last-change"><a class="desc" href="[^"]*sort=lastChange"[^>]*>Last Change</a></small>#',
            $html
        );
    }

    public function testPagerShowsTwentyItemsPerPage(): void
    {
        $type = $this->createType();
        for ($i = 1; $i <= 21; $i++) {
            $this->createItem(['title' => sprintf('Item %02d', $i), 'type_id' => $type->id]);
        }
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', ['sort' => 'title']));
        $this->assertSame(20, substr_count($html, self::ROW_CLASS));
        $this->assertStringContainsString('class="pagination"', $html);
        $this->assertStringNotContainsString('>Item 21</a>', $html);

        $html = $this->assertPage($this->get('item/index', ['sort' => 'title', 'page' => 2]));
        $this->assertSame(1, substr_count($html, self::ROW_CLASS));
        $this->assertStringContainsString('>Item 21</a>', $html);
    }

    public function testLinksFromTypeAndTopicCountsPreselectFilters(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->createType(['name' => 'Other']);
        $topic = $this->createTopic(['name' => 'Forest']);
        $this->createItem(['type_id' => $type->id, 'topicIds' => [$topic->id], 'is_archived' => true]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', [
            'ItemSearch' => ['type_id' => $type->id, 'archived' => 'all'],
        ]));
        $this->assertStringContainsString('<option value="' . $type->id . '" selected>Law</option>', $html);
        $this->assertStringContainsString('<option value="all" selected>All</option>', $html);
        $this->assertSame(1, substr_count($html, self::ROW_CLASS));

        $html = $this->assertPage($this->get('item/index', [
            'ItemSearch' => ['topicIds' => [$topic->id], 'archived' => 'all'],
        ]));
        $this->assertStringContainsString('<option value="' . $topic->id . '" selected>Forest</option>', $html);
        $this->assertStringContainsString('<option value="all" selected>All</option>', $html);
        $this->assertSame(1, substr_count($html, self::ROW_CLASS));
    }

    public function testInvalidFilterValuesAreIgnored(): void
    {
        $this->createItem(['title' => 'Guideline']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/index', [
            'ItemSearch' => ['archived' => 'bogus', 'title' => ['array']],
        ]));

        $this->assertStringContainsString('>Guideline</a>', $html);
    }

    public function testStateBadges(): void
    {
        $type = $this->createType(['has_validity_period' => true, 'requires_review' => true]);
        $noPeriod = $this->createType(['has_validity_period' => false, 'requires_review' => false]);

        $archived = $this->createItem(['title' => 'S archived', 'type_id' => $type->id, 'is_archived' => true]);
        $this->createPublishedVersion($archived, ['valid_from' => '2020-01-01']);

        $inReview = $this->createItem(['title' => 'S in review', 'type_id' => $type->id]);
        $this->assertTrue($this->createVersion($inReview, ['valid_from' => '2020-01-01'])->submitForReview('user-2'));

        $this->createItem(['title' => 'S no versions', 'type_id' => $type->id]);

        $expired = $this->createItem(['title' => 'S expired', 'type_id' => $type->id]);
        $this->createPublishedVersion($expired, ['valid_from' => '2020-01-01', 'valid_until' => '2020-12-31']);

        $valid = $this->createItem(['title' => 'S valid', 'type_id' => $type->id]);
        $this->createPublishedVersion($valid, ['valid_from' => '2021-03-15']);

        $published = $this->createItem(['title' => 'S no period', 'type_id' => $noPeriod->id]);
        $this->createPublishedVersion($published);

        $this->loginAs(Module::ROLE_EDITOR);
        $html = $this->assertPage($this->get('item/index', ['ItemSearch' => ['archived' => 'all'], 'sort' => 'title']));

        $this->assertSame(6, substr_count($html, self::ROW_CLASS));
        $this->assertMatchesRegularExpression(
            '#>S archived</a>.*?<span class="knowledge-library-state knowledge-library-state-archived" style="[^"]*">Archived</span>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#>S in review</a>.*?<span class="knowledge-library-state knowledge-library-state-in_review" style="[^"]*">In Review</span>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#>S no versions</a>.*?<span class="knowledge-library-state knowledge-library-state-draft" style="[^"]*">Draft</span>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#>S expired</a>.*?<span class="knowledge-library-state knowledge-library-state-none" style="[^"]*">No valid version</span>#s',
            $html
        );
        $since = Yii::$app->formatter->asDate('2021-03-15');
        $this->assertMatchesRegularExpression(
            '#>S valid</a>.*?<span>Version 1</span> <span class="knowledge-library-since" style="color: \#777">since '
            . preg_quote(Html::encode($since), '#') . '</span>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#>S no period</a>.*?<span>Version 1</span> <span class="knowledge-library-since" style="color: \#777">since #s',
            $html
        );
        // Colours of the click dummy.
        foreach (['#d2d6de', '#f39c12', '#dd4b39'] as $colour) {
            $this->assertStringContainsString($colour, $html);
        }
    }

    public function testIndexNeedsNoQueryPerRow(): void
    {
        $type = $this->createType(['has_validity_period' => false, 'requires_review' => false]);
        $topic = $this->createTopic();
        $createItems = function (int $count) use ($type, $topic) {
            for ($i = 0; $i < $count; $i++) {
                $item = $this->createItem(['type_id' => $type->id, 'topicIds' => [$topic->id]]);
                $this->createPublishedVersion($item);
            }
        };
        $this->loginAs(Module::ROLE_EDITOR);
        $createItems(5);
        // Warm up schema and RBAC caches.
        $this->assertPage($this->get('item/index'));

        $few = $this->countQueries(fn () => $this->assertPage($this->get('item/index')));
        $createItems(20);
        $many = $this->countQueries(fn () => $this->assertPage($this->get('item/index')));

        $this->assertSame($few, $many);
    }

    public function testCreatePage(): void
    {
        $this->createType(['name' => 'Zeta']);
        $this->createType(['name' => 'Alpha']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/create'));

        $this->assertSame('Create knowledge object', Yii::$app->getView()->title);
        $this->assertSame('Create knowledge object', Yii::$app->getView()->params['breadcrumbs'][1]);
        $this->assertStringContainsString('name="Item[title]"', $html);
        $this->assertStringContainsString('name="Item[type_id]"', $html);
        $this->assertStringContainsString('>Create</button>', $html);
        $this->assertLessThan(strpos($html, '>Zeta</option>'), strpos($html, '>Alpha</option>'));
    }

    public function testCreateWithoutTitleAndTypeShowsErrors(): void
    {
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post('item/create', ['Item' => ['title' => '  ', 'type_id' => '']]));

        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('Title cannot be blank.', $html);
        $this->assertStringContainsString('Type cannot be blank.', $html);
        $this->assertSame(0, (int)Item::find()->count());
        $this->assertNull($this->getFlash('success'));
    }

    public function testCreateSavesAndRedirectsToView(): void
    {
        $type = $this->createType();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('item/create', ['Item' => [
            'title' => '  Forest law ',
            'type_id' => $type->id,
            // Not part of the create scenario.
            'is_archived' => '1',
            'source_name' => 'Injected',
        ]]);

        $item = Item::findOne(['title' => 'Forest law']);
        $this->assertNotNull($item);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Knowledge object created.', $this->getFlash('success'));
        $this->assertSame($type->id, $item->type_id);
        $this->assertFalse((bool)$item->is_archived);
        $this->assertNull($item->source_name);
        $this->assertSame(Item::SOURCE_IMPORT_MANUAL, $item->source_import_mode);
    }

    public function testUpdateTypeWithoutVersionsIsAllowed(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $other = $this->createType();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/update', ['id' => $item->id]));
        $this->assertSame('Edit knowledge object', Yii::$app->getView()->title);
        $this->assertSame(
            ['label' => 'Guideline', 'url' => ['view', 'id' => $item->id]],
            Yii::$app->getView()->params['breadcrumbs'][1]
        );
        $this->assertDoesNotMatchRegularExpression('#<select [^>]*name="Item\[type_id\]"[^>]*disabled#', $html);

        $this->post('item/update', ['Item' => ['title' => 'Renamed', 'type_id' => $other->id]], ['id' => $item->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Knowledge object saved.', $this->getFlash('success'));
        $item->refresh();
        $this->assertSame('Renamed', $item->title);
        $this->assertSame($other->id, $item->type_id);
    }

    public function testUpdateWithEmptyTitleShowsError(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->post('item/update', ['Item' => ['title' => '']], ['id' => $item->id]));

        $this->assertStringContainsString('Title cannot be blank.', $html);
        $this->assertSame('Guideline', Item::findOne($item->id)->title);
    }

    public function testTypeIsLockedOnceVersionsExist(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $this->createVersion($item);
        $other = $this->createType();
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/update', ['id' => $item->id]));
        $this->assertMatchesRegularExpression('#<select id="item-type_id"[^>]*name="Item\[type_id\]"[^>]*disabled#', $html);
        $this->assertStringContainsString('The type cannot be changed once the item has versions.', $html);

        // A manipulated request still sends another type.
        $html = $this->assertPage($this->post('item/update', ['Item' => [
            'title' => 'Renamed',
            'type_id' => $other->id,
        ]], ['id' => $item->id]));

        $this->assertStringContainsString('has-error', $html);
        $this->assertNull($this->getFlash('success'));
        $item->refresh();
        $this->assertNotSame($other->id, $item->type_id);
        $this->assertSame('Guideline', $item->title);

        // Without the type (as the disabled field sends) saving works.
        $this->post('item/update', ['Item' => ['title' => 'Renamed']], ['id' => $item->id]);
        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Renamed', Item::findOne($item->id)->title);
    }

    public function testSourcePage(): void
    {
        $item = $this->createItem(['title' => 'Guideline']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/source', ['id' => $item->id]));

        $this->assertSame('Edit source & origin', Yii::$app->getView()->title);
        foreach (['source_name', 'source_reference', 'source_url'] as $attribute) {
            $this->assertStringContainsString('name="Item[' . $attribute . ']"', $html);
        }
        $this->assertStringContainsString('type="radio" name="Item[source_import_mode]" value="manual" checked', $html);
        $this->assertStringContainsString('type="radio" name="Item[source_import_mode]" value="automatic"', $html);
        $this->assertStringContainsString('>Save</button>', $html);
    }

    public function testSourceValidation(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_EDITOR);
        $valid = ['source_name' => 'Ministry', 'source_url' => '', 'source_import_mode' => 'manual'];

        $html = $this->assertPage($this->post('item/source', ['Item' => array_merge($valid, ['source_name' => ' '])], ['id' => $item->id]));
        $this->assertStringContainsString('Source cannot be blank.', $html);

        $html = $this->assertPage($this->post('item/source', ['Item' => array_merge($valid, ['source_url' => 'not a url'])], ['id' => $item->id]));
        $this->assertStringContainsString('Source URL is not a valid URL.', $html);

        $html = $this->assertPage($this->post('item/source', ['Item' => array_merge($valid, ['source_import_mode' => 'magic'])], ['id' => $item->id]));
        $this->assertStringContainsString('Import Mode is invalid.', $html);

        $this->assertNull($this->getFlash('success'));
        $this->assertEmpty(Item::findOne($item->id)->source_name);
    }

    public function testSourceSavesAndDetailShowsValues(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_EDITOR);

        $this->post('item/source', ['Item' => [
            'source_name' => ' Ministry ',
            'source_reference' => 'Section 4 <b>',
            'source_url' => 'https://example.org/law?a=1&b=2',
            'source_import_mode' => 'automatic',
            // Display only, set by the upload.
            'source_uploaded_by' => 'intruder',
        ]], ['id' => $item->id]);

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('Source & origin saved.', $this->getFlash('success'));
        $item->refresh();
        $this->assertSame('Ministry', $item->source_name);
        $this->assertSame('automatic', $item->source_import_mode);
        $this->assertNull($item->source_uploaded_by);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('<td>Ministry</td>', $html);
        $this->assertStringContainsString('<td>Section 4 &lt;b&gt;</td>', $html);
        $this->assertStringContainsString(
            '<a href="https://example.org/law?a=1&amp;b=2" rel="noopener noreferrer" target="_blank">https://example.org/law?a=1&amp;b=2</a>',
            $html
        );
        $this->assertStringContainsString('<td>Automatic</td>', $html);
        // Uploaded at and by are empty.
        $this->assertSame(2, substr_count($html, '<td>–</td>'));
    }

    public function testDetailShowsUploadedValues(): void
    {
        $item = $this->createItem(['source_uploaded_at' => '2026-09-01 10:30:00', 'source_uploaded_by' => 'user-7']);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertStringContainsString(
            '<td>' . Html::encode(Yii::$app->formatter->asDatetime('2026-09-01 10:30:00')) . '</td>',
            $html
        );
        $this->assertStringContainsString('<td>User user-7</td>', $html);
        // Source, reference and URL are empty.
        $this->assertSame(3, substr_count($html, '<td>–</td>'));
    }

    public function testDetailPage(): void
    {
        $type = $this->createType(['name' => 'Guideline type']);
        $item = $this->createItem(['title' => 'Forest <law>', 'type_id' => $type->id]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertSame('Forest <law>', Yii::$app->getView()->title);
        $this->assertSame('Forest <law>', Yii::$app->getView()->params['breadcrumbs'][1]);
        $this->assertStringContainsString('<h1>Forest &lt;law&gt;</h1>', $html);
        $this->assertMatchesRegularExpression(
            '#<span class="knowledge-library-badge knowledge-library-badge-type" style="[^"]*background: \\#061d42[^"]*">Guideline type</span>#',
            $html
        );
        $this->assertStringNotContainsString('knowledge-library-badge-archived">', $html);
        $this->assertStringContainsString(
            'href="' . Html::encode(Url::to(['/knowledge-library/item/update', 'id' => $item->id])) . '"',
            $html
        );
        $this->assertStringContainsString(
            'href="' . Html::encode(Url::to(['/knowledge-library/item/source', 'id' => $item->id])) . '"',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<a class="btn btn-default knowledge-library-item-list" href="'
            . preg_quote(Url::to(['/knowledge-library/item/index']), '#') . '"><i class="fa fa-list"></i> Full list</a>#',
            $html
        );

        $this->assertStringContainsString('<ul class="nav nav-tabs"', $html);
        $this->assertSame(5, substr_count($html, 'data-toggle="tab"'));
        foreach (['Versions', 'Content', 'Relations', 'Source &amp; origin', 'History'] as $label) {
            $this->assertMatchesRegularExpression('#data-toggle="tab"[^>]*>' . preg_quote($label, '#') . '</a>#', $html);
        }
        $this->assertMatchesRegularExpression('#<li role="presentation" class="active">\s*<a href="\#knowledge-library-tab-source"#', $html);
        $this->assertStringContainsString('class="tab-pane active" id="knowledge-library-tab-source"', $html);
        $this->assertSame(4, substr_count($html, 'knowledge-library-placeholder'));
        $this->assertStringContainsString('Versions will be available in a later release.', $html);
        $this->assertStringContainsString('The change history will be available in a later release.', $html);
    }

    public function testDetailShowsArchivedBadge(): void
    {
        $item = $this->createItem(['is_archived' => true]);
        $this->loginAs(Module::ROLE_EDITOR);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertMatchesRegularExpression(
            '#<span class="knowledge-library-badge knowledge-library-badge-archived" style="[^"]*background: \\#d2d6de[^"]*">Archived</span>#',
            $html
        );
    }

    public function testDeleteRemovesItemWithAllDependentRows(): void
    {
        $type = $this->createType(['has_validity_period' => false, 'requires_review' => false]);
        $topic = $this->createTopic();
        $item = $this->createItem(['title' => 'To be "deleted" <b>', 'type_id' => $type->id, 'topicIds' => [$topic->id]]);
        $other = $this->createItem(['type_id' => $type->id, 'topicIds' => [$topic->id]]);

        $published = $this->createPublishedVersion($item);
        $draft = $this->createVersion($item);
        $this->createFile($published);
        $otherVersion = $this->createVersion($other);
        $this->createFile($otherVersion);
        $this->createRelation($item, $other);
        $this->createRelation($other, $item);
        History::log($item, History::ACTION_CREATED);
        History::log($item, History::ACTION_PUBLISHED, $published);
        History::log($other, History::ACTION_CREATED);
        $admin = $this->loginAs(Module::ROLE_ADMIN);

        $this->post('item/delete', [], ['id' => $item->id]);

        $this->assertRedirectsTo(['item/index']);
        $this->assertSame(
            'Knowledge object "To be &quot;deleted&quot; &lt;b&gt;" deleted.',
            $this->getFlash('success')
        );
        $this->assertNull(Item::findOne($item->id));
        $this->assertSame(0, $this->countRows('{{%knowledge_library_version}}', ['item_id' => $item->id]));
        $this->assertSame(0, $this->countRows('{{%knowledge_library_file}}', ['version_id' => [$published->id, $draft->id]]));
        $this->assertSame(0, $this->countRows('{{%knowledge_library_relation}}', ['or', ['source_item_id' => $item->id], ['target_item_id' => $item->id]]));
        $this->assertSame(0, $this->countRows('{{%knowledge_library_history}}', ['item_id' => $item->id]));
        $this->assertSame(0, $this->countRows('{{%knowledge_library_item_topic}}', ['item_id' => $item->id]));

        // Everything else is kept.
        $this->assertNotNull(Type::findOne($type->id));
        $this->assertNotNull(Topic::findOne($topic->id));
        $this->assertNotNull(Item::findOne($other->id));
        $this->assertSame(1, $this->countRows('{{%knowledge_library_version}}', ['item_id' => $other->id]));
        $this->assertSame(1, $this->countRows('{{%knowledge_library_file}}', ['version_id' => $otherVersion->id]));
        $this->assertSame(1, $this->countRows('{{%knowledge_library_history}}', ['item_id' => $other->id]));
        $this->assertSame(1, $this->countRows('{{%knowledge_library_item_topic}}', ['item_id' => $other->id]));

        $messages = $this->getLogMessages('knowledge-library');
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($admin->uuid, $messages[0]);
        $this->assertStringContainsString($item->id, $messages[0]);
        $this->assertStringContainsString('To be "deleted" <b>', $messages[0]);
        $this->assertStringContainsString('2 version(s)', $messages[0]);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/', $messages[0]);
    }

    public function testFailedDeleteShowsErrorAndKeepsItem(): void
    {
        $item = $this->createItem();
        $this->loginAs(Module::ROLE_ADMIN);
        $handler = static function (ModelEvent $event) {
            $event->isValid = false;
        };
        Event::on(Item::class, Item::EVENT_BEFORE_DELETE, $handler);

        try {
            $this->post('item/delete', [], ['id' => $item->id]);
        } finally {
            Event::off(Item::class, Item::EVENT_BEFORE_DELETE, $handler);
        }

        $this->assertRedirectsTo(['item/view', 'id' => $item->id]);
        $this->assertSame('The knowledge object could not be deleted.', $this->getFlash('error'));
        $this->assertNull($this->getFlash('success'));
        $this->assertNotNull(Item::findOne($item->id));
    }

    public function testDeleteButtonConfirmsWithTitleAndScope(): void
    {
        $item = $this->createItem(['title' => 'Forest "law"']);
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertStringContainsString(
            'data-confirm="Delete &quot;Forest &quot;law&quot;&quot; together with all versions, file entries, relations and history?"',
            $html
        );
    }

    public function testPagesAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $this->loginAs(Module::ROLE_ADMIN);

        $html = $this->assertPage($this->get('item/index'));
        $this->assertStringContainsString('Wissensbibliothek', $html);
        $this->assertStringContainsString('Wissensobjekt anlegen', $html);
        $this->assertStringContainsString('Noch keine Wissensobjekte vorhanden. Legen Sie das erste über „Wissensobjekt anlegen“ an.', $html);

        $item = $this->createItem(['title' => 'Waldgesetz']);
        $html = $this->assertPage($this->get('item/index'));
        $this->assertStringContainsString('In Kraft', $html);
        $this->assertStringContainsString('Letzte Änderung', $html);
        $this->assertMatchesRegularExpression('#knowledge-library-state-draft" style="[^"]*">Entwurf</span>#', $html);

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $this->assertStringContainsString('Gesamte Liste', $html);
        $this->assertStringContainsString('Quelle &amp; Herkunft', $html);
        $this->assertStringContainsString('„Waldgesetz“ samt allen Versionen, Dateizeilen, Beziehungen und Historie löschen?', $html);

        $this->post('item/delete', [], ['id' => $item->id]);
        $this->assertSame('Wissensobjekt „Waldgesetz“ gelöscht.', $this->getFlash('success'));
    }

    private function createRelation(Item $source, Item $target): Relation
    {
        $relation = new Relation([
            'source_item_id' => $source->id,
            'target_item_id' => $target->id,
            'type' => Relation::TYPE_SUPPLEMENTS,
        ]);
        $this->assertTrue($relation->save(), 'Relation not saved: ' . json_encode($relation->getErrors()));

        return $relation;
    }

    private function countRows(string $table, array $condition): int
    {
        return (int)(new Query())->from($table)->where($condition)->count('*', Yii::$app->db);
    }

    private function countQueries(callable $callback): int
    {
        $count = static fn () => count(Yii::getLogger()->getProfiling(['yii\db\Command::query']));
        $before = $count();
        $callback();

        return $count() - $before;
    }
}
