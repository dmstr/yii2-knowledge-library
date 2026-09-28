<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\History;
use dmstr\knowledgeLibrary\models\Relation;
use dmstr\knowledgeLibrary\tests\support\DummyUserProvider;
use dmstr\knowledgeLibrary\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Yii;

/**
 * Tests of the details and descriptions of history entries.
 */
class HistoryTest extends TestCase
{
    public function testLogStoresDetailsAsJson(): void
    {
        $item = $this->createItem();

        $history = History::log($item, History::ACTION_RELATION_ADDED, null, null, [
            'relation' => Relation::TYPE_SUPPLEMENTS,
            'target' => 'Forest „law“',
        ]);

        $history = History::findOne($history->id);
        $this->assertSame('{"relation":"supplements","target":"Forest „law“"}', $history->details);
        $this->assertSame(['relation' => 'supplements', 'target' => 'Forest „law“'], $history->getDetails());
    }

    public function testLogAddsTheVersionNumber(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);

        $history = History::log($item, History::ACTION_DRAFT_SAVED, $version);

        $this->assertSame(['number' => 1], History::findOne($history->id)->getDetails());
    }

    public function testLogKeepsAGivenNumber(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);

        $history = History::log($item, History::ACTION_CORRECTED, $version, null, ['number' => 7, 'correction' => 8]);

        $this->assertSame(['number' => 7, 'correction' => 8], History::findOne($history->id)->getDetails());
    }

    public function testLogWithoutDetailsStoresNull(): void
    {
        $history = History::log($this->createItem(), History::ACTION_ARCHIVED, null, '');

        $history = History::findOne($history->id);
        $this->assertNull($history->details);
        $this->assertNull($history->reason);
        $this->assertSame([], $history->getDetails());
    }

    public function testInvalidDetailsAreIgnored(): void
    {
        $history = new History(['details' => 'not json']);
        $this->assertSame([], $history->getDetails());

        $history->details = '"string"';
        $this->assertSame([], $history->getDetails());
    }

    public function testDetailsSurviveDeletingTheVersion(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);
        $history = History::log($item, History::ACTION_DRAFT_DISCARDED, $version);

        $this->assertSame(1, $version->delete());

        $history = History::findOne($history->id);
        $this->assertNull($history->version_id);
        $this->assertSame('Draft of version 1 discarded', $history->describe(new DummyUserProvider()));
    }

    #[DataProvider('descriptionProvider')]
    public function testDescribe(string $action, array $details, string $expected, string $expectedGerman): void
    {
        $history = new History([
            'action' => $action,
            'actor_id' => 'user-1',
        ]);
        $history->setDetails($details);
        $users = new DummyUserProvider();

        $this->assertSame($expected, $history->describe($users));

        Yii::$app->language = 'de';
        // The formatter keeps the locale it was created with.
        Yii::$app->formatter->locale = 'de';
        $this->assertSame($expectedGerman, $history->describe($users));
    }

    public static function descriptionProvider(): array
    {
        return [
            'created' => [History::ACTION_CREATED, [], 'Knowledge object created', 'Wissensobjekt angelegt'],
            'master data' => [History::ACTION_MASTER_DATA_CHANGED, [], 'Master data changed', 'Stammdaten geändert'],
            'source' => [History::ACTION_SOURCE_CHANGED, [], 'Source & origin changed', 'Quelle & Herkunft geändert'],
            'draft saved' => [
                History::ACTION_DRAFT_SAVED,
                ['number' => 2],
                'Version 2 saved as draft',
                'Version 2 als Entwurf gespeichert',
            ],
            'draft discarded' => [
                History::ACTION_DRAFT_DISCARDED,
                ['number' => 2],
                'Draft of version 2 discarded',
                'Entwurf von Version 2 verworfen',
            ],
            'review requested' => [
                History::ACTION_REVIEW_REQUESTED,
                ['number' => 3, 'reviewer' => 'user-2'],
                'Version 3 sent to User user-2 for approval',
                'Version 3 zur Freigabe an User user-2 gesendet',
            ],
            'reviewer changed' => [
                History::ACTION_REVIEWER_CHANGED,
                ['number' => 3, 'reviewer' => 'user-3', 'previous_reviewer' => 'user-2'],
                'Review of version 3 handed over to User user-3 (previously User user-2)',
                'Prüfung von Version 3 an User user-3 übergeben (vorher User user-2)',
            ],
            'returned' => [
                History::ACTION_RETURNED,
                ['number' => 3, 'reviewer' => 'user-2'],
                'Version 3 returned by User user-1',
                'Version 3 von User user-1 zurückgegeben',
            ],
            'approved' => [
                History::ACTION_APPROVED,
                ['number' => 3],
                'Version 3 approved by User user-1',
                'Version 3 freigegeben durch User user-1',
            ],
            'published with period' => [
                History::ACTION_PUBLISHED,
                ['number' => 3, 'valid_from' => '2026-03-01'],
                'Version 3 published, valid from Mar 1, 2026',
                'Version 3 veröffentlicht, gilt ab 01.03.2026',
            ],
            'published without period' => [
                History::ACTION_PUBLISHED,
                ['number' => 3],
                'Version 3 published',
                'Version 3 veröffentlicht',
            ],
            'withdrawn with successor' => [
                History::ACTION_WITHDRAWN,
                ['number' => 3, 'successor' => 2],
                'Version 3 withdrawn, version 2 remains valid',
                'Version 3 zurückgezogen, Version 2 gilt weiter',
            ],
            'withdrawn without successor' => [
                History::ACTION_WITHDRAWN,
                ['number' => 3],
                'Version 3 withdrawn, no valid version',
                'Version 3 zurückgezogen, kein gültiger Stand',
            ],
            'corrected' => [
                History::ACTION_CORRECTED,
                ['number' => 3, 'correction' => 4],
                'Version 3 corrected by version 4',
                'Version 3 korrigiert durch Version 4',
            ],
            'archived' => [History::ACTION_ARCHIVED, [], 'Archived', 'Archiviert'],
            'restored' => [History::ACTION_RESTORED, [], 'Restored', 'Wiederhergestellt'],
            'relation added' => [
                History::ACTION_RELATION_ADDED,
                ['relation' => Relation::TYPE_SUPPLEMENTS, 'target' => 'Guideline'],
                'Relation added: supplements Guideline',
                'Beziehung hinzugefügt: ergänzt Guideline',
            ],
            'relation removed, inverse' => [
                History::ACTION_RELATION_REMOVED,
                ['relation' => Relation::TYPE_REPLACES, 'direction' => 'inverse', 'target' => 'Guideline'],
                'Relation removed: is replaced by Guideline',
                'Beziehung entfernt: wird ersetzt durch Guideline',
            ],
        ];
    }

    public function testDescribeUsesReferenceForUnknownUsersAndDashWithoutUser(): void
    {
        $users = new class extends DummyUserProvider {
            public function getDisplayName(string $reference): ?string
            {
                return null;
            }
        };
        $history = new History(['action' => History::ACTION_REVIEWER_CHANGED]);
        $history->setDetails(['number' => 1, 'reviewer' => 'gone']);

        $this->assertSame('Review of version 1 handed over to gone (previously –)', $history->describe($users));
    }

    public function testDescribeUsesVersionNumberWithoutDetails(): void
    {
        $item = $this->createItem();
        $version = $this->createVersion($item);
        $history = new History([
            'item_id' => $item->id,
            'version_id' => $version->id,
            'action' => History::ACTION_DRAFT_SAVED,
        ]);

        $this->assertSame('Version 1 saved as draft', $history->describe(new DummyUserProvider()));
    }

    public function testDescribeOfUnknownActionIsTheAction(): void
    {
        $history = new History(['action' => 'imported']);

        $this->assertSame('imported', $history->describe(new DummyUserProvider()));
    }

    public function testFullReviewWritesOneEntryPerStep(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['requires_review' => true])->id]);
        $version = $this->createVersion($item, ['valid_from' => '2026-01-01']);

        $this->assertTrue($version->submitForReview('user-2', 'Please check'));
        DummyUserProvider::$currentReference = 'user-2';
        $this->assertTrue($version->returnToDraft('Fix it'));
        DummyUserProvider::$currentReference = 'user-1';
        $this->assertTrue($version->submitForReview('user-2'));
        $this->assertTrue($version->changeReviewer('user-3', 'Holiday'));
        DummyUserProvider::$currentReference = 'user-3';
        $this->assertTrue($version->approve('Fine'));

        $entries = History::find()->where(['item_id' => $item->id])->all();
        $actions = array_column($entries, 'action');
        sort($actions);
        $this->assertSame([
            History::ACTION_APPROVED,
            History::ACTION_PUBLISHED,
            History::ACTION_RETURNED,
            History::ACTION_REVIEW_REQUESTED,
            History::ACTION_REVIEW_REQUESTED,
            History::ACTION_REVIEWER_CHANGED,
        ], $actions);

        $described = array_map(static fn (History $entry) => $entry->describe(new DummyUserProvider()), $entries);
        sort($described);
        $this->assertSame([
            'Review of version 1 handed over to User user-3 (previously User user-2)',
            'Version 1 approved by User user-3',
            'Version 1 published, valid from Jan 1, 2026',
            'Version 1 returned by User user-2',
            'Version 1 sent to User user-2 for approval',
            'Version 1 sent to User user-2 for approval',
        ], $described);
    }
}
