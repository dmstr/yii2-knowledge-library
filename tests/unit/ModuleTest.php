<?php

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\Module;
use dmstr\knowledgeLibrary\tests\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use stdClass;
use Yii;
use yii\base\InvalidConfigException;

class ModuleTest extends TestCase
{
    public function testFileOptionsHaveDefaults(): void
    {
        $module = $this->createModule();

        $this->assertSame('fs', $module->fileStorage);
        $this->assertSame('knowledge-library', $module->targetPath);
        $this->assertSame(
            ['pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
            $module->allowedExtensions
        );
        $this->assertSame(20 * 1024 * 1024, $module->maxFileSize);
    }

    public function testGetFilesystemReturnsFilesystemComponent(): void
    {
        $filesystem = $this->createModule()->getFilesystem();

        $this->assertInstanceOf(Filesystem::class, $filesystem);
        $this->assertSame(Yii::$app->get('fs'), $filesystem);

        $filesystem->write('knowledge-library/test.txt', 'Content');
        $this->assertFileExists($this->getStoragePath('knowledge-library/test.txt'));
    }

    public function testGetFilesystemUsesTheConfiguredComponent(): void
    {
        $other = new Filesystem(new LocalFilesystemAdapter($this->getStorageDir() . '/other'));
        Yii::$app->set('otherStorage', $other);

        $this->assertSame($other, $this->createModule(['fileStorage' => 'otherStorage'])->getFilesystem());
    }

    public function testGetFilesystemReturnsFilesystemOfWrapperComponent(): void
    {
        $filesystem = Yii::$app->get('fs');
        Yii::$app->set('wrappedStorage', new class ($filesystem) {
            public function __construct(private FilesystemOperator $filesystem)
            {
            }

            public function getFilesystem(): FilesystemOperator
            {
                return $this->filesystem;
            }
        });

        $this->assertSame($filesystem, $this->createModule(['fileStorage' => 'wrappedStorage'])->getFilesystem());
    }

    public function testGetFilesystemFailsForComponentWithoutFilesystem(): void
    {
        Yii::$app->set('plainStorage', new stdClass());

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The file storage component "plainStorage" must implement');

        $this->createModule(['fileStorage' => 'plainStorage'])->getFilesystem();
    }

    public function testGetFilesystemFailsForWrapperReturningNoFilesystem(): void
    {
        Yii::$app->set('brokenStorage', new class {
            public function getFilesystem()
            {
                return null;
            }
        });

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The file storage component "brokenStorage" must implement');

        $this->createModule(['fileStorage' => 'brokenStorage'])->getFilesystem();
    }

    public function testGetFilesystemFailsForUnknownComponent(): void
    {
        $this->expectException(InvalidConfigException::class);

        $this->createModule(['fileStorage' => 'missingStorage'])->getFilesystem();
    }

    private function createModule(array $config = []): Module
    {
        return new Module('knowledge-library', Yii::$app, $config);
    }
}
