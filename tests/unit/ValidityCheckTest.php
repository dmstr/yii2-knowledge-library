<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\models\Item;
use dmstr\knowledgeLibrary\models\ValidityCheck;
use dmstr\knowledgeLibrary\models\Version;
use dmstr\knowledgeLibrary\tests\TestCase;
use Yii;

class ValidityCheckTest extends TestCase
{
    private const TODAY = '2026-09-28';

    public function testFirstVersionOpenEnded(): void
    {
        $draft = $this->createVersion($this->createItem(), ['valid_from' => '2026-03-01']);

        $check = new ValidityCheck($draft, self::TODAY);

        $this->assertTrue($check->isValid());
        $this->assertSame([], $check->getErrors());
        $this->assertSame(
            ['Version 1 is valid from ' . $this->date('2026-03-01') . ', open-ended.'],
            $check->getConsequences()
        );
        $this->assertSame('2026-03-01', $check->getValidFrom());
        $this->assertNull($check->getValidUntil());
        $this->assertNull($check->getPredecessor());
    }

    public function testOpenPredecessorEndsTheDayBefore(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01', 'valid_until' => '2026-12-31']);

        $check = new ValidityCheck($draft, self::TODAY);

        $this->assertSame([
            'Version 1 ends on ' . $this->date('2026-02-28') . '.',
            'Version 2 is valid from ' . $this->date('2026-03-01') . ' until ' . $this->date('2026-12-31') . '.',
        ], $check->getConsequences());
    }

    public function testPredecessorEndingAfterTheStartEndsTheDayBefore(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-03-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01']);

        $consequences = (new ValidityCheck($draft, self::TODAY))->getConsequences();

        $this->assertSame('Version 1 ends on ' . $this->date('2026-02-28') . '.', $consequences[0]);
    }

    public function testGapAfterPredecessor(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01']);

        $check = new ValidityCheck($draft, self::TODAY);

        $this->assertSame([
            'From ' . $this->date('2026-02-01') . ' to ' . $this->date('2026-02-28') . ' no version is valid.',
            'Version 2 is valid from ' . $this->date('2026-03-01') . ', open-ended.',
        ], $check->getConsequences());
    }

    public function testPredecessorEndingTheDayBeforeHasNoConsequence(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-02-28']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01']);

        $this->assertSame(
            ['Version 2 is valid from ' . $this->date('2026-03-01') . ', open-ended.'],
            (new ValidityCheck($draft, self::TODAY))->getConsequences()
        );
    }

    public function testMissingOrInvalidValidFrom(): void
    {
        $draft = $this->createVersion($this->createItem());

        foreach ([null, '', '01.03.2026', '2026-02-30', '2026-3-1'] as $value) {
            $draft->valid_from = $value;
            $check = new ValidityCheck($draft, self::TODAY);

            $this->assertFalse($check->isValid(), var_export($value, true));
            $this->assertSame(['Enter "Valid From" in the format DD.MM.YYYY.'], $check->getErrors());
            $this->assertSame([], $check->getConsequences());
            $this->assertNull($check->getValidFrom());
        }
    }

    public function testInvalidValidUntil(): void
    {
        $draft = $this->createVersion($this->createItem());
        $draft->valid_from = '2026-03-01';
        $draft->valid_until = '31.12.2026';

        $this->assertSame(
            ['Enter "Valid Until" in the format DD.MM.YYYY.'],
            (new ValidityCheck($draft, self::TODAY))->getErrors()
        );
    }

    public function testValidUntilBeforeValidFrom(): void
    {
        $draft = $this->createVersion($this->createItem());
        $draft->valid_from = '2026-03-01';
        $draft->valid_until = '2026-02-28';

        $this->assertSame(
            ['Valid Until must not be before Valid From.'],
            (new ValidityCheck($draft, self::TODAY))->getErrors()
        );

        $draft->valid_until = '2026-03-01';
        $this->assertTrue((new ValidityCheck($draft, self::TODAY))->isValid());
    }

    public function testRetroactiveStartIsRejected(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-02']);
        $expected = 'The date is before the start of version 1 (' . $this->date('2026-03-01')
            . '). Retroactive changes are only possible with "Correct".';

        foreach (['2026-02-01', '2026-03-01'] as $validFrom) {
            $draft->valid_from = $validFrom;
            $this->assertSame([$expected], (new ValidityCheck($draft, self::TODAY))->getErrors(), $validFrom);
        }

        $draft->valid_from = '2026-03-02';
        $this->assertTrue((new ValidityCheck($draft, self::TODAY))->isValid());
    }

    public function testRetroactiveStartIsAllowedForCorrection(): void
    {
        $item = $this->createItem();
        $published = $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);
        $draft = $this->createVersion($item, [
            'valid_from' => '2026-03-01',
            'corrects_version_id' => $published->id,
        ]);

        $this->assertTrue((new ValidityCheck($draft, self::TODAY))->isValid());
    }

    public function testMessagesMatchTheVersionValidators(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-03-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-04-01']);
        $draft->scenario = Version::SCENARIO_VALIDITY;

        $cases = [
            ['', null, 'valid_from'],
            ['01.04.2026', null, 'valid_from'],
            ['2026-04-01', '2026-13-01', 'valid_until'],
            ['2026-04-01', '2026-03-31', 'valid_until'],
            ['2026-02-01', null, 'valid_from'],
        ];
        foreach ($cases as [$from, $until, $attribute]) {
            $draft->clearErrors();
            $draft->valid_from = $from;
            $draft->valid_until = $until;

            $errors = (new ValidityCheck($draft, self::TODAY))->getErrors();
            $this->assertFalse($draft->validate(), $from);
            $this->assertCount(1, $errors);
            $this->assertSame($errors[0], $draft->getFirstError($attribute), "$from / $until");
        }
    }

    public function testTypeWithoutValidityPeriod(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $draft = $this->createVersion($item);

        $check = new ValidityCheck($draft, self::TODAY);
        $this->assertTrue($check->isValid());
        $this->assertSame(['Version 1 is valid from publication, open-ended.'], $check->getConsequences());
        $this->assertSame(self::TODAY, $check->getValidFrom());

        $draft->delete();
        $this->createPublishedVersion($item);
        $draft = $this->createVersion($item);
        $check = new ValidityCheck($draft, self::TODAY);
        $this->assertSame([
            'Version 2 replaces version 1 upon publication.',
            'Version 2 is valid from publication, open-ended.',
        ], $check->getConsequences());

        $draft->valid_from = '2026-03-01';
        $check = new ValidityCheck($draft, self::TODAY);
        $this->assertSame(
            ['The type of this item has no validity period, leave the date empty.'],
            $check->getErrors()
        );
        $this->assertSame([], $check->getConsequences());
    }

    public function testConsequencesAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-01-31']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-03-01']);

        $consequences = (new ValidityCheck($draft, self::TODAY))->getConsequences();
        $this->assertStringStartsWith('Vom ', $consequences[0]);
        $this->assertStringContainsString('gilt keine Version.', $consequences[0]);
        $this->assertStringContainsString('unbefristet', $consequences[1]);

        $draft->valid_from = '2026-01-01';
        $this->assertSame(
            ['Das Datum liegt vor dem Beginn von Version 1 (' . $this->date('2026-01-01')
                . '). Rückwirkende Änderungen gehen nur über „Korrigieren“.'],
            (new ValidityCheck($draft, self::TODAY))->getErrors()
        );
    }

    public function testPreviewContainsTheNewVersionAndTheShortenedPredecessor(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-10-01', 'valid_until' => '2029-06-30']);

        $preview = (new ValidityCheck($draft, self::TODAY))->getPreview();

        $this->assertSame(2024, $preview['y0']);
        $this->assertSame(2031, $preview['y1']);
        $this->assertSame([
            ['n' => 1, 'a' => '2025-01-01', 'b' => '2026-09-30', 's' => ValidityCheck::STATE_IN_FORCE, 'hl' => true, 'lane' => 0],
            ['n' => 2, 'a' => '2026-10-01', 'b' => '2029-06-30', 's' => ValidityCheck::STATE_NEW, 'hl' => false, 'lane' => 0],
        ], $preview['v']);
    }

    public function testPreviewKeepsPredecessorEndingEarlier(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01', 'valid_until' => '2025-12-31']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-10-01']);

        $bars = (new ValidityCheck($draft, self::TODAY))->getPreview()['v'];

        $this->assertSame('2025-12-31', $bars[0]['b']);
        $this->assertFalse($bars[0]['hl']);
        $this->assertSame(ValidityCheck::STATE_HISTORICAL, $bars[0]['s']);
    }

    public function testPreviewWithoutNewVersionIfInvalid(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $draft = $this->createVersion($item, ['valid_from' => '2026-01-01']);
        $draft->valid_from = '2025-01-01';

        $bars = (new ValidityCheck($draft, self::TODAY))->getPreview()['v'];

        $this->assertCount(1, $bars);
        $this->assertNull($bars[0]['b']);
        $this->assertFalse($bars[0]['hl']);
    }

    public function testTimelineStatesAndLanes(): void
    {
        $item = $this->createItem();
        $first = $this->createPublishedVersion($item, ['valid_from' => '2023-05-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2027-01-01']);
        $withdrawn = $this->createPublishedVersion($item, ['valid_from' => '2027-06-01']);
        $withdrawn->updateAttributes(['status' => Version::STATUS_WITHDRAWN]);
        $this->assertTrue($this->createVersion($item, ['valid_from' => '2028-01-01'])->submitForReview('user-2'));
        $this->createVersion($item, ['valid_from' => '2028-02-01']);

        $timeline = ValidityCheck::timeline(Item::findOne($item->id), self::TODAY);

        $this->assertSame(2023, $timeline['y0']);
        $this->assertSame(2029, $timeline['y1']);
        $this->assertSame([
            ['n' => 1, 'a' => '2023-05-01', 'b' => '2025-12-31', 's' => 'hist', 'hl' => false, 'lane' => 0],
            ['n' => 2, 'a' => '2026-01-01', 'b' => '2026-12-31', 's' => 'kraft', 'hl' => false, 'lane' => 0],
            ['n' => 3, 'a' => '2027-01-01', 'b' => '2027-05-31', 's' => 'bev', 'hl' => false, 'lane' => 0],
            ['n' => 4, 'a' => '2027-06-01', 'b' => null, 's' => 'zur', 'hl' => false, 'lane' => 1],
        ], $timeline['v']);
        $this->assertSame('2025-12-31', Version::findOne($first->id)->valid_until);
    }

    public function testTimelineWithoutVersions(): void
    {
        $this->assertSame(
            ['y0' => 2024, 'y1' => 2028, 'v' => []],
            ValidityCheck::timeline($this->createItem(), self::TODAY)
        );
    }

    public function testTimelineOfTypeWithoutValidityPeriodUsesPublicationDates(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $first = $this->createPublishedVersion($item);
        $first->updateAttributes(['published_at' => '2025-02-10 10:00:00']);
        $second = $this->createPublishedVersion($item);
        $second->updateAttributes(['published_at' => '2026-04-01 09:00:00']);

        $bars = ValidityCheck::timeline(Item::findOne($item->id), self::TODAY)['v'];
        $this->assertSame([
            ['n' => 1, 'a' => '2025-02-10', 'b' => '2026-03-31', 's' => 'hist', 'hl' => false, 'lane' => 0],
            ['n' => 2, 'a' => '2026-04-01', 'b' => null, 's' => 'kraft', 'hl' => false, 'lane' => 0],
        ], $bars);

        $draft = $this->createVersion($item);
        $bars = (new ValidityCheck($draft, self::TODAY))->getPreview()['v'];
        $this->assertSame(['n' => 2, 'a' => '2026-04-01', 'b' => '2026-09-27', 's' => 'kraft', 'hl' => true, 'lane' => 0], $bars[1]);
        $this->assertSame(['n' => 3, 'a' => self::TODAY, 'b' => null, 's' => 'neu', 'hl' => false, 'lane' => 0], $bars[2]);
    }

    public function testUnsavedVersionGetsTheNextNumber(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $version = new Version(['item_id' => $item->id, 'valid_from' => '2026-05-01']);

        $consequences = (new ValidityCheck($version, self::TODAY))->getConsequences();

        $this->assertStringStartsWith('Version 2 is valid from', end($consequences));
    }

    public function testCorrectionTakesOverThePeriodWithoutErrors(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        $correction = Version::createCorrection($faulty);
        // Entered dates are ignored, the correction keeps the period.
        $correction->valid_from = 'invalid';
        $correction->valid_until = '2020-01-01';

        $check = new ValidityCheck($correction, self::TODAY);

        $this->assertTrue($check->isValid());
        $this->assertTrue($check->isCorrection());
        $this->assertSame($faulty->id, $check->getCorrectedVersion()->id);
        $this->assertSame('2026-01-01', $check->getValidFrom());
        $this->assertSame('2026-12-31', $check->getValidUntil());
        $this->assertSame([
            'Version 2 is withdrawn.',
            'Version 3 is valid from ' . $this->date('2026-01-01') . ' until ' . $this->date('2026-12-31') . '.',
        ], $check->getConsequences());

        $draft = $this->createVersion($this->createItem(), ['valid_from' => '2026-03-01']);
        $this->assertFalse((new ValidityCheck($draft, self::TODAY))->isCorrection());
        $this->assertNull((new ValidityCheck($draft, self::TODAY))->getCorrectedVersion());
    }

    public function testCorrectionPreviewShowsTheCorrectedVersionAsWithdrawn(): void
    {
        $item = $this->createItem();
        $this->createPublishedVersion($item, ['valid_from' => '2025-01-01']);
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01']);
        $this->createPublishedVersion($item, ['valid_from' => '2027-01-01']);
        $correction = Version::createCorrection(Version::findOne($faulty->id));

        $preview = (new ValidityCheck($correction, self::TODAY))->getPreview();

        $this->assertSame([
            ['n' => 1, 'a' => '2025-01-01', 'b' => '2025-12-31', 's' => 'hist', 'hl' => false, 'lane' => 0],
            ['n' => 2, 'a' => '2026-01-01', 'b' => '2026-12-31', 's' => 'zur', 'hl' => false, 'lane' => 1],
            ['n' => 3, 'a' => '2027-01-01', 'b' => null, 's' => 'bev', 'hl' => false, 'lane' => 0],
            ['n' => 4, 'a' => '2026-01-01', 'b' => '2026-12-31', 's' => 'neu', 'hl' => false, 'lane' => 0],
        ], $preview['v']);
    }

    public function testCorrectionOfTypeWithoutValidityPeriod(): void
    {
        $item = $this->createItem(['type_id' => $this->createType(['has_validity_period' => false])->id]);
        $faulty = $this->createPublishedVersion($item);
        $correction = Version::createCorrection($faulty);

        $check = new ValidityCheck($correction, self::TODAY);

        $this->assertTrue($check->isValid());
        $this->assertSame([
            'Version 1 is withdrawn.',
            'Version 2 is valid from publication, open-ended.',
        ], $check->getConsequences());
        $this->assertNull($check->getPastYears());
        $this->assertNull($check->getPastHint());
        $bars = $check->getPreview()['v'];
        $this->assertSame('zur', $bars[0]['s']);
        $this->assertSame(1, $bars[0]['lane']);
        $this->assertSame(['n' => 2, 'a' => self::TODAY, 'b' => null, 's' => 'neu', 'hl' => false, 'lane' => 0], $bars[1]);
    }

    public function testCorrectionPastHint(): void
    {
        $item = $this->createItem();
        $old = $this->createPublishedVersion($item, ['valid_from' => '2024-03-01']);
        $current = $this->createPublishedVersion($item, ['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        $open = $this->createPublishedVersion($item, ['valid_from' => '2027-01-01']);

        $cases = [
            // From 2024 until the end of 2025.
            [$old, [2024, 2025], 'Changes the answers to questions about 2024 to 2025.'],
            // Ends after today: until the current year.
            [$current, [2026, 2026], 'Changes the answers to questions about 2026.'],
            // Starts in the future: no hint.
            [$open, null, null],
        ];
        foreach ($cases as [$version, $years, $hint]) {
            $correction = Version::createCorrection(Version::findOne($version->id));
            $check = new ValidityCheck($correction, self::TODAY);
            $this->assertSame($years, $check->getPastYears(), (string)$version->number);
            $this->assertSame($hint, $check->getPastHint(), (string)$version->number);
            $correction->delete();
        }

        $draft = $this->createVersion($item, ['valid_from' => '2027-06-01']);
        $this->assertNull((new ValidityCheck($draft, self::TODAY))->getPastHint());
    }

    public function testCorrectionTextsAreTranslated(): void
    {
        Yii::$app->language = 'de';
        $item = $this->createItem();
        $faulty = $this->createPublishedVersion($item, ['valid_from' => '2025-06-01']);
        $correction = Version::createCorrection($faulty);

        $check = new ValidityCheck($correction, self::TODAY);

        $this->assertSame('Version 1 wird zurückgezogen.', $check->getConsequences()[0]);
        $this->assertSame('Ändert die Antworten auf Fragen zu 2025 bis 2026.', $check->getPastHint());
    }

    private function date(string $date): string
    {
        return Yii::$app->formatter->asDate($date);
    }
}
