<?php

namespace dmstr\knowledgeLibrary\tests\web\frontend;

use dmstr\knowledgeLibrary\models\File;
use dmstr\knowledgeLibrary\tests\FrontendWebTestCase;
use dmstr\knowledgeLibrary\tests\support\FrontendFixtures;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

/**
 * Tests of the frontend list (`item/index`) and detail page (`item/view`).
 */
class ItemControllerTest extends FrontendWebTestCase
{
    use FrontendFixtures;

    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    private const XSS = '<img src=x onerror=alert(2)>';

    public function testIndexListsExactlyTheValidActiveItemsSorted(): void
    {
        $type = $this->createType(['name' => 'Law']);
        $this->createInvalidItems();
        $this->createValidItem(['title' => 'Charlie', 'type_id' => $type->id]);
        [$alphaSecond] = $this->createValidItem(['title' => 'Alpha', 'type_id' => $type->id]);
        [$alphaFirst] = $this->createValidItem(['title' => 'Alpha', 'type_id' => $type->id]);
        $this->createValidItem(['title' => 'Bravo', 'type_id' => $type->id]);
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/index'));

        $this->assertSame(['Alpha', 'Alpha', 'Bravo', 'Charlie'], $this->listedTitles($html));
        $ids = [$alphaFirst->id, $alphaSecond->id];
        sort($ids);
        preg_match_all('/<li data-item-id="([^"]+)"/', $html, $matches);
        $this->assertSame($ids, array_slice($matches[1], 0, 2));
    }

    public function testIndexShowsLinkTypeAndSortedTopics(): void
    {
        $type = $this->createType(['name' => 'Guideline']);
        $zulu = $this->createTopic(['name' => 'Zulu']);
        $alpha = $this->createTopic(['name' => 'Alpha']);
        [$item] = $this->createValidItem([
            'title' => 'Forest law',
            'type_id' => $type->id,
            'topicIds' => [$zulu->id, $alpha->id],
        ]);
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringContainsString('<h1>Valid knowledge objects</h1>', $html);
        $this->assertStringContainsString(
            Html::a('Forest law', Url::to(['/knowledge/item/view', 'id' => $item->id]), ['class' => 'knowledge-item-link']),
            $html
        );
        $this->assertStringContainsString('<span class="knowledge-item-type">Type: Guideline</span>', $html);
        $this->assertStringContainsString('<span class="knowledge-item-topics">Topics: Alpha, Zulu</span>', $html);
    }

    public function testIndexOmitsTopicsOfItemsWithoutTopics(): void
    {
        $this->createValidItem();
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringNotContainsString('knowledge-item-topics', $html);
    }

    public function testIndexWithoutValidItemsShowsHint(): void
    {
        $this->createInvalidItems();
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringContainsString('No valid knowledge objects.', $html);
        $this->assertStringNotContainsString('<ul class="knowledge-items">', $html);
    }

    public function testIndexOutputIsStable(): void
    {
        $topicIds = [
            $this->createTopic(['name' => 'Topic C'])->id,
            $this->createTopic(['name' => 'Topic A'])->id,
            $this->createTopic(['name' => 'Topic B'])->id,
        ];
        foreach (['Bravo', 'Alpha', 'Alpha', 'Charlie'] as $title) {
            $this->createValidItem(['title' => $title, 'topicIds' => $topicIds]);
        }
        $this->loginAs('knowledge');

        $first = $this->assertPage($this->get('item/index'));
        $second = $this->assertPage($this->get('item/index'));

        $this->assertSame($first, $second);
        $this->assertSame(['Alpha', 'Alpha', 'Bravo', 'Charlie'], $this->listedTitles($first));
        $this->assertSame(4, substr_count($first, 'Topics: Topic A, Topic B, Topic C</span>'));
    }

    public function testIndexSetsTitleAndBreadcrumbs(): void
    {
        $this->loginAs('knowledge');

        $this->assertPage($this->get('item/index'));

        $view = Yii::$app->getView();
        $this->assertSame('Valid knowledge objects', $view->title);
        $this->assertSame([
            ['label' => 'Knowledge Library', 'url' => ['/knowledge/item/index']],
            'Valid knowledge objects',
        ], $view->params['breadcrumbs']);
    }

    public function testIndexIsGerman(): void
    {
        $this->createValidItem(['topicIds' => [$this->createTopic()->id]]);
        $this->loginAs('knowledge');
        Yii::$app->language = 'de';

        $html = $this->assertPage($this->get('item/index'));

        $this->assertStringContainsString('<h1>Gültige Wissensobjekte</h1>', $html);
        $this->assertStringContainsString('Typ: ', $html);
        $this->assertStringContainsString('Themen: ', $html);
    }

    public function testViewShowsAllFields(): void
    {
        $type = $this->createType(['name' => 'Guideline', 'has_validity_period' => true]);
        [$item, $version] = $this->createValidItem([
            'title' => 'Forest law',
            'type_id' => $type->id,
            'summary' => "First line\nSecond line",
            'source_name' => 'Ministry',
            'source_reference' => 'Section 5',
            'source_url' => 'https://example.org/law?a=1&b=2',
            'topicIds' => [$this->createTopic(['name' => 'Zulu'])->id, $this->createTopic(['name' => 'Alpha'])->id],
        ], [
            'valid_from' => $this->day(-30),
            'valid_until' => $this->day(30),
            'content' => "Intro **bold**\n\n- one\n- two",
        ]);
        $main = $this->createStoredFile($version, 'law.pdf', ['position' => 1, 'size' => 2048]);
        $attachment = $this->createStoredFile($version, 'annex.pdf', [
            'kind' => File::KIND_ATTACHMENT,
            'title' => 'Annex 1',
            'position' => 2,
            'size' => 1536,
        ]);
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));
        $formatter = Yii::$app->formatter;

        $this->assertStringContainsString('<article class="knowledge-item" data-item-id="' . $item->id . '">', $html);
        $this->assertStringContainsString('<h1>Forest law</h1>', $html);
        $this->assertStringContainsString('<dd class="knowledge-item-type">Guideline</dd>', $html);
        $this->assertStringContainsString('<dd class="knowledge-item-topics">Alpha, Zulu</dd>', $html);
        $this->assertStringContainsString(
            '<dd class="knowledge-item-validity">Valid from ' . Html::encode($formatter->asDate($this->day(-30)))
            . ' until ' . Html::encode($formatter->asDate($this->day(30))) . '</dd>',
            $html
        );
        $this->assertStringContainsString('<dd class="knowledge-item-source">Ministry</dd>', $html);
        $this->assertStringContainsString('<dd class="knowledge-item-source-reference">Section 5</dd>', $html);
        $this->assertStringContainsString(
            '<a href="https://example.org/law?a=1&amp;b=2" rel="noopener">https://example.org/law?a=1&amp;b=2</a>',
            $html
        );
        $this->assertStringContainsString("<p>First line<br />\nSecond line</p>", $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<section class="knowledge-summary">', $html);
        $this->assertStringContainsString('<section class="knowledge-content">', $html);
        $this->assertStringContainsString('<section class="knowledge-files">', $html);

        $mainUrl = Html::encode(Url::to(['/knowledge/file/download', 'id' => $main->id]));
        $attachmentUrl = Html::encode(Url::to(['/knowledge/file/download', 'id' => $attachment->id]));
        $this->assertStringContainsString('<a href="' . $mainUrl . '">law.pdf</a>', $html);
        $this->assertStringContainsString('<a href="' . $attachmentUrl . '">Annex 1</a>', $html);
        $this->assertStringContainsString('<span class="knowledge-file-name">annex.pdf</span>', $html);
        $this->assertStringContainsString('(' . $formatter->asShortSize(2048, 1) . ')', $html);
        $this->assertStringContainsString('(' . $formatter->asShortSize(1536, 1) . ')', $html);
        $this->assertStringNotContainsString('No main documents.', $html);
        $this->assertStringNotContainsString('No attachments.', $html);

        $view = Yii::$app->getView();
        $this->assertSame('Forest law', $view->title);
        $this->assertSame([
            ['label' => 'Knowledge Library', 'url' => ['/knowledge/item/index']],
            'Forest law',
        ], $view->params['breadcrumbs']);
    }

    public function testViewOmitsEmptyFieldsAndShowsEmptyHints(): void
    {
        [$item] = $this->createValidItem(['title' => 'Bare'], ['valid_from' => $this->day(-1)]);
        // A version needs a text or a main file; the row is changed directly.
        Yii::$app->db->createCommand()
            ->update('{{%knowledge_library_version}}', ['content' => null], ['item_id' => $item->id])
            ->execute();
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        foreach (['knowledge-item-topics', 'knowledge-item-source', 'knowledge-summary'] as $class) {
            $this->assertStringNotContainsString($class, $html);
        }
        $this->assertStringContainsString(
            '<dd class="knowledge-item-validity">Valid from ' . Html::encode(Yii::$app->formatter->asDate($this->day(-1)))
            . ' until open-ended</dd>',
            $html
        );
        $this->assertStringContainsString('No text available.', $html);
        $this->assertStringContainsString('No main documents.', $html);
        $this->assertStringContainsString('No attachments.', $html);
    }

    public function testViewOfTypeWithoutValidityPeriodShowsPublicationDate(): void
    {
        $type = $this->createType(['has_validity_period' => false]);
        [$item, $version] = $this->createValidItem(['type_id' => $type->id], ['valid_from' => null]);
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertNotNull($version->published_at);
        $this->assertStringContainsString(
            '<dd class="knowledge-item-validity">Valid since '
            . Html::encode(Yii::$app->formatter->asDate($version->published_at)) . '</dd>',
            $html
        );
    }

    public function testViewShowsTheVersionValidToday(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => $this->day(-60), 'content' => 'Old text']);
        $current = $this->createPublishedVersion($item, ['valid_from' => $this->day(-10), 'content' => 'Current text']);
        $this->createPublishedVersion($item, ['valid_from' => $this->day(10), 'content' => 'Future text']);
        $this->createVersion($item, ['content' => 'Draft text']);
        $this->createStoredFile($current, 'current.pdf');
        $this->loginAs('knowledge');

        $html = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        $this->assertStringContainsString('Current text', $html);
        $this->assertStringContainsString('current.pdf', $html);
        foreach (['Old text', 'Future text', 'Draft text'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
    }

    public function testViewOfItemsNotValidTodayIsNotFound(): void
    {
        $items = $this->createInvalidItems();
        $this->loginAs('knowledge');

        foreach ($items as $reason => [$item]) {
            $exception = $this->assertHttpException(NotFoundHttpException::class, 'GET', 'item/view', ['id' => $item->id]);
            $this->assertSame('The requested knowledge object does not exist.', $exception->getMessage(), $reason);
        }
    }

    public function testViewOfRestoredItemIsFoundAgain(): void
    {
        [$item] = $this->createValidItem();
        $this->assertTrue($item->archive());
        $this->loginAs('knowledge');
        $this->assertHttpException(NotFoundHttpException::class, 'GET', 'item/view', ['id' => $item->id]);

        $this->assertTrue($item->restore());

        $this->assertPage($this->get('item/view', ['id' => $item->id]));
    }

    public function testViewOfUnknownIdIsNotFound(): void
    {
        [, $version] = $this->createValidItem();
        $file = $this->createStoredFile($version, 'law.pdf');
        $this->loginAs('knowledge');

        // IDs of other records are no item IDs.
        foreach ([self::UNKNOWN_ID, 'not-a-uuid', $version->id, $file->id] as $id) {
            $this->assertHttpException(NotFoundHttpException::class, 'GET', 'item/view', ['id' => $id]);
        }
    }

    public function testListAndViewEncodeAllOutput(): void
    {
        $type = $this->createType(['name' => 'Type ' . self::XSS]);
        [$item, $version] = $this->createValidItem([
            'title' => 'Title ' . self::XSS,
            'type_id' => $type->id,
            'summary' => 'Summary ' . self::XSS,
            'source_name' => 'Source ' . self::XSS,
            'source_reference' => 'Reference ' . self::XSS,
            'source_url' => 'https://example.org/?q="><script>alert(1)</script>',
            'topicIds' => [$this->createTopic(['name' => 'Topic ' . self::XSS])->id],
        ], [
            'content' => "Intro **bold**\n\n<script>alert(1)</script>\n\n" . self::XSS,
        ]);
        $this->createStoredFile($version, 'main ' . self::XSS . '.pdf');
        $this->createStoredFile($version, 'annex.pdf', ['kind' => File::KIND_ATTACHMENT, 'title' => 'Annex ' . self::XSS]);
        $this->loginAs('knowledge');

        $list = $this->assertPage($this->get('item/index'));
        $detail = $this->assertPage($this->get('item/view', ['id' => $item->id]));

        foreach (['list' => $list, 'detail' => $detail] as $page => $html) {
            $this->assertStringNotContainsString('<img', $html, $page);
            $this->assertStringNotContainsString('<script', $html, $page);
            $this->assertStringContainsString('Title &lt;img src=x onerror=alert(2)&gt;', $html, $page);
            $this->assertStringContainsString('Type &lt;img src=x onerror=alert(2)&gt;', $html, $page);
            $this->assertStringContainsString('Topic &lt;img src=x onerror=alert(2)&gt;', $html, $page);
        }
        $this->assertStringContainsString('<strong>bold</strong>', $detail);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detail);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $detail);
        foreach (['Summary', 'Source', 'Reference', 'Annex', 'main'] as $prefix) {
            $this->assertStringContainsString($prefix . ' &lt;img src=x onerror=alert(2)&gt;', $detail, $prefix);
        }
        $this->assertStringContainsString('href="https://example.org/?q=&quot;&gt;&lt;script&gt;', $detail);
    }

    /**
     * Titles of the list entries in the order of the page.
     *
     * @return string[]
     */
    private function listedTitles(string $html): array
    {
        preg_match_all('/<a class="knowledge-item-link" href="[^"]*">([^<]*)<\/a>/', $html, $matches);

        return array_map('html_entity_decode', $matches[1]);
    }
}
