<?php

namespace Oro\Bundle\GaufretteBundle\Tests\Unit;

use Gaufrette\File;
use Gaufrette\Filesystem;
use Gaufrette\Stream;
use Gaufrette\Stream\InMemoryBuffer;
use Gaufrette\Stream\Local as LocalStream;
use Gaufrette\StreamMode;
use Knp\Bundle\GaufretteBundle\FilesystemMap;
use Oro\Bundle\GaufretteBundle\Exception\FlushFailedException;
use Oro\Bundle\GaufretteBundle\FileManager;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class FileManagerTest extends \PHPUnit\Framework\TestCase
{
    private const TEST_FILE_SYSTEM_NAME = 'testFileSystem';
    private const TEST_PROTOCOL         = 'testProtocol';

    /** @var \PHPUnit\Framework\MockObject\MockObject|Filesystem */
    private $filesystem;

    /** @var FileManager */
    private $fileManager;

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $filesystemMap = $this->createMock(FilesystemMap::class);
        $filesystemMap->expects($this->once())
            ->method('get')
            ->with(self::TEST_FILE_SYSTEM_NAME)
            ->willReturn($this->filesystem);

        $this->fileManager = new FileManager(self::TEST_FILE_SYSTEM_NAME);
        $this->fileManager->setFilesystemMap($filesystemMap);
        $this->fileManager->setProtocol(self::TEST_PROTOCOL);
    }

    public function testGetProtocol()
    {
        self::assertEquals(self::TEST_PROTOCOL, $this->fileManager->getProtocol());
    }

    public function testGetFilePath()
    {
        self::assertEquals(
            sprintf('%s://%s/test.txt', self::TEST_PROTOCOL, self::TEST_FILE_SYSTEM_NAME),
            $this->fileManager->getFilePath('test.txt')
        );
    }

    public function testGetFilePathWithConfiguredPathPrefixDirectory()
    {
        $fileManager = new FileManager(self::TEST_FILE_SYSTEM_NAME, 'testSubDir');
        $fileManager->setProtocol(self::TEST_PROTOCOL);

        self::assertEquals(
            sprintf('%s://%s/test.txt', self::TEST_PROTOCOL, 'testSubDir'),
            $fileManager->getFilePath('test.txt')
        );
    }

    public function testGetFilePathWhenProtocolIsNotConfigured()
    {
        $this->expectException(\Oro\Bundle\GaufretteBundle\Exception\ProtocolConfigurationException::class);
        $this->fileManager->setProtocol('');
        $this->fileManager->getFilePath('test.txt');
    }

    public function testFindAllFiles()
    {
        $this->filesystem->expects(self::once())
            ->method('listKeys')
            ->with(self::identicalTo(self::TEST_FILE_SYSTEM_NAME . '/'))
            ->willReturn([
                'keys' => [self::TEST_FILE_SYSTEM_NAME . '/file1', self::TEST_FILE_SYSTEM_NAME . '/file2'],
                'dirs' => ['dir1']
            ]);

        self::assertEquals(
            ['file1', 'file2'],
            $this->fileManager->findFiles()
        );
    }

    public function testFindFilesByPrefix()
    {
        $this->filesystem->expects(self::once())
            ->method('listKeys')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/prefix')
            ->willReturn([
                'keys' => [self::TEST_FILE_SYSTEM_NAME . '/file1', self::TEST_FILE_SYSTEM_NAME . '/file2'],
                'dirs' => ['dir1']
            ]);

        self::assertEquals(
            ['file1', 'file2'],
            $this->fileManager->findFiles('prefix')
        );
    }

    public function testFindFilesWhenNoFilesFound()
    {
        $this->filesystem->expects(self::once())
            ->method('listKeys')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/prefix')
            ->willReturn([]);

        self::assertSame(
            [],
            $this->fileManager->findFiles('prefix')
        );
    }

    /**
     * E.g. this may happens when AwsS3 or GoogleCloudStorage adapters are used
     */
    public function testFindFilesWhenAdapterReturnsOnlyKeys()
    {
        $this->filesystem->expects(self::once())
            ->method('listKeys')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/prefix')
            ->willReturn(['file1', 'file2']);

        self::assertEquals(
            ['file1', 'file2'],
            $this->fileManager->findFiles('prefix')
        );
    }

    public function testHasFileWhenFileExists()
    {
        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(true);

        $this->assertTrue($this->fileManager->hasFile($fileName));
    }

    public function testHasFileWhenFileDoesNotExist()
    {
        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);

        $this->assertFalse($this->fileManager->hasFile($fileName));
    }

    public function testGetFileByFileName()
    {
        $fileName = 'testFile.txt';

        $file = $this->createMock(File::class);
        $file->expects($this->once())
            ->method('getName')
            ->willReturn(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);
        $file->expects($this->once())
            ->method('setName')
            ->with($fileName);

        $this->filesystem->expects($this->never())
            ->method('has');
        $this->filesystem->expects($this->once())
            ->method('get')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($file);

        $this->assertSame($file, $this->fileManager->getFile($fileName));
    }

    public function testGetFileWhenFileDoesNotExistAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);
        $this->filesystem->expects($this->never())
            ->method('get');

        $this->assertNull($this->fileManager->getFile($fileName, false));
    }

    public function testGetFileWhenFileExistsAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';

        $file = $this->createMock(File::class);
        $file->expects($this->once())
            ->method('getName')
            ->willReturn(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);
        $file->expects($this->once())
            ->method('setName')
            ->with($fileName);

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(true);
        $this->filesystem->expects($this->once())
            ->method('get')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($file);

        $this->assertSame($file, $this->fileManager->getFile($fileName, false));
    }

    public function testGetStreamWhenFileDoesNotExist()
    {
        $this->expectException(\Gaufrette\Exception\FileNotFound::class);
        $this->expectExceptionMessage('The file "testFileSystem/testFile.txt" was not found.');

        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);
        $this->filesystem->expects($this->never())
            ->method('createStream');

        $this->fileManager->getStream($fileName);
    }

    public function testGetStreamWhenFileDoesNotExistAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);
        $this->filesystem->expects($this->never())
            ->method('createStream');

        $this->assertNull($this->fileManager->getStream($fileName, false));
    }

    public function testGetStreamWhenFileExistsAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';
        $stream = new LocalStream('test');

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(true);
        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($stream);

        $this->assertSame($stream, $this->fileManager->getStream($fileName, false));
    }

    public function testGetFileContentByFileName()
    {
        $fileName = 'testFile.txt';
        $fileContent = 'test data';

        $file = $this->createMock(File::class);
        $file->expects($this->once())
            ->method('getContent')
            ->willReturn($fileContent);
        $file->expects($this->once())
            ->method('getName')
            ->willReturn(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);
        $file->expects($this->once())
            ->method('setName')
            ->with($fileName);

        $this->filesystem->expects($this->never())
            ->method('has');
        $this->filesystem->expects($this->once())
            ->method('get')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($file);

        $this->assertEquals($fileContent, $this->fileManager->getFileContent($fileName));
    }

    public function testGetFileContentWhenFileDoesNotExistAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);
        $this->filesystem->expects($this->never())
            ->method('get');

        $this->assertNull($this->fileManager->getFileContent($fileName, false));
    }

    public function testGetFileContentWhenFileExistsAndRequestedIgnoreException()
    {
        $fileName = 'testFile.txt';
        $fileContent = 'test data';

        $file = $this->createMock(File::class);
        $file->expects($this->once())
            ->method('getContent')
            ->willReturn($fileContent);
        $file->expects($this->once())
            ->method('getName')
            ->willReturn(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);
        $file->expects($this->once())
            ->method('setName')
            ->with($fileName);

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(true);
        $this->filesystem->expects($this->once())
            ->method('get')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($file);

        $this->assertEquals($fileContent, $this->fileManager->getFileContent($fileName, false));
    }

    public function testDeleteFile()
    {
        $fileName = 'text.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(true);
        $this->filesystem->expects($this->once())
            ->method('delete')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $this->fileManager->deleteFile($fileName);
    }

    public function testDeleteFileForNotExistingFile()
    {
        $fileName = 'text.txt';

        $this->filesystem->expects($this->once())
            ->method('has')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn(false);
        $this->filesystem->expects($this->never())
            ->method('delete');

        $this->fileManager->deleteFile($fileName);
    }

    public function testDeleteFileWhenFileNameIsEmpty()
    {
        $this->filesystem->expects($this->never())
            ->method('has');
        $this->filesystem->expects($this->never())
            ->method('delete');

        $this->fileManager->deleteFile('');
    }

    public function testDeleteAllFiles()
    {
        $fileNames = [self::TEST_FILE_SYSTEM_NAME . '/' . 'text1.txt', self::TEST_FILE_SYSTEM_NAME . '/' . 'text2.txt'];

        $this->filesystem->expects(self::once())
            ->method('listKeys')
            ->willReturn([
                'keys' => $fileNames,
                'dirs' => ['dir1']
            ]);
        $this->filesystem->expects($this->exactly(2))
            ->method('delete')
            ->withConsecutive(
                [$fileNames[0]],
                [$fileNames[1]]
            );

        $this->fileManager->deleteAllFiles();
    }

    public function testWriteToStorage()
    {
        $content = 'Test data';
        $fileName = 'test2.txt';

        $resultStream = new InMemoryBuffer($this->filesystem, $fileName);

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $this->fileManager->writeToStorage($content, $fileName);

        $resultStream->open(new StreamMode('rb+'));
        $resultStream->seek(0);
        $this->assertEquals($content, $resultStream->read(100));
    }

    public function testWriteToStorageWhenFlushFailed()
    {
        $this->expectException(\Oro\Bundle\GaufretteBundle\Exception\FlushFailedException::class);
        $this->expectExceptionMessage('Failed to flush data to the "test2.txt" file.');

        $content = 'Test data';
        $fileName = 'test2.txt';

        $resultStream = $this->createMock(Stream::class);
        $resultStream->expects($this->once())
            ->method('open')
            ->with(new StreamMode('wb+'));
        $resultStream->expects($this->once())
            ->method('write')
            ->with($content);
        $resultStream->expects($this->once())
            ->method('flush')
            ->willReturn(false);
        $resultStream->expects($this->once())
            ->method('close');

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $this->fileManager->writeToStorage($content, $fileName);
    }

    public function testWriteFileToStorage()
    {
        $localFilePath = __DIR__ . '/Fixtures/test.txt';
        $fileName = 'test2.txt';

        $resultStream = new InMemoryBuffer($this->filesystem, $fileName);

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $this->fileManager->writeFileToStorage($localFilePath, $fileName);

        $resultStream->open(new StreamMode('rb+'));
        $resultStream->seek(0);
        $this->assertStringEqualsFile($localFilePath, $resultStream->read(100));
    }

    public function testWriteStreamToStorage()
    {
        $localFilePath = __DIR__ . '/Fixtures/test.txt';
        $fileName = 'test2.txt';

        $srcStream = new LocalStream($localFilePath);
        $resultStream = new InMemoryBuffer($this->filesystem, $fileName);

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $result = $this->fileManager->writeStreamToStorage($srcStream, $fileName);

        $resultStream->open(new StreamMode('rb'));
        $resultStream->seek(0);
        $this->assertStringEqualsFile($localFilePath, $resultStream->read(100));
        $this->assertTrue($result);
        // test that the input stream is closed
        $this->assertFalse($srcStream->cast(1));
    }

    public function testWriteStreamToStorageWhenFlushFailed()
    {
        $localFilePath = __DIR__ . '/Fixtures/test.txt';
        $fileName = 'test2.txt';

        $srcStream = new LocalStream($localFilePath);
        $resultStream = $this->createMock(Stream::class);
        $resultStream->expects($this->once())
            ->method('open')
            ->with(new StreamMode('wb+'));
        $resultStream->expects($this->once())
            ->method('write')
            ->with(file_get_contents($localFilePath));
        $resultStream->expects($this->once())
            ->method('flush')
            ->willReturn(false);
        $resultStream->expects($this->once())
            ->method('close');

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        try {
            $this->fileManager->writeStreamToStorage($srcStream, $fileName);
            $this->fail('Expected FlushFailedException');
        } catch (FlushFailedException $e) {
            self::assertEquals('Failed to flush data to the "test2.txt" file.', $e->getMessage());
            // test that the input stream is closed
            $this->assertFalse($srcStream->cast(1));
        }
    }

    public function testWriteStreamToStorageWithEmptyStreamAndAvoidWriteEmptyStream()
    {
        $localFilePath = __DIR__ . '/Fixtures/emptyFile.txt';
        $fileName = 'test2.txt';

        $srcStream = new LocalStream($localFilePath);

        $this->filesystem->expects($this->never())
            ->method('createStream')
            ->with($fileName);
        $this->filesystem->expects($this->never())
            ->method('removeFromRegister')
            ->with($fileName);

        $result = $this->fileManager->writeStreamToStorage($srcStream, $fileName, true);

        $this->assertFalse($result);
        // test that the input stream is closed
        $this->assertFalse($srcStream->cast(1));
    }

    public function testWriteStreamToStorageWithEmptyStream()
    {
        $localFilePath = __DIR__ . '/Fixtures/emptyFile.txt';
        $fileName = 'test2.txt';

        $srcStream = new LocalStream($localFilePath);
        $resultStream = new InMemoryBuffer($this->filesystem, $fileName);

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $result = $this->fileManager->writeStreamToStorage($srcStream, $fileName);

        $resultStream->open(new StreamMode('rb'));
        $resultStream->seek(0);
        $this->assertEmpty($resultStream->read(100));
        $this->assertTrue($result);
        // test that the input stream is closed
        $this->assertFalse($srcStream->cast(1));
    }

    public function testWriteStreamToStorageAndAvoidWriteEmptyStream()
    {
        $localFilePath = __DIR__ . '/Fixtures/test.txt';
        $fileName = 'test2.txt';

        $srcStream = new LocalStream($localFilePath);
        $resultStream = new InMemoryBuffer($this->filesystem, $fileName);

        $this->filesystem->expects($this->once())
            ->method('createStream')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName)
            ->willReturn($resultStream);
        $this->filesystem->expects($this->once())
            ->method('removeFromRegister')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/' . $fileName);

        $result = $this->fileManager->writeStreamToStorage($srcStream, $fileName, true);

        $resultStream->open(new StreamMode('rb'));
        $resultStream->seek(0);
        $this->assertStringEqualsFile($localFilePath, $resultStream->read(100));
        $this->assertTrue($result);
        // test that the input stream is closed
        $this->assertFalse($srcStream->cast(1));
    }

    public function testWriteToTemporaryFile()
    {
        $content = 'Test data';

        $resultFile = null;
        try {
            $resultFile = $this->fileManager->writeToTemporaryFile($content);
            try {
                self::assertEquals($content, file_get_contents($resultFile->getRealPath()));
            } finally {
                @unlink($resultFile->getRealPath());
            }
        } catch (IOException $e) {
            // no access to temporary file - ignore this error
        }
    }

    public function testWriteStreamToTemporaryFile()
    {
        $content = 'Test data';

        $srcStream = new InMemoryBuffer($this->filesystem, 'test.txt');
        $srcStream->open(new StreamMode('wb+'));
        $srcStream->write($content);
        $srcStream->seek(0);
        $srcStream->close();

        $resultFile = null;
        try {
            $resultFile = $this->fileManager->writeStreamToTemporaryFile($srcStream);
            try {
                self::assertEquals($content, file_get_contents($resultFile->getRealPath()));
            } finally {
                @unlink($resultFile->getRealPath());
            }
        } catch (\RuntimeException $e) {
            if (false === strpos($e->getMessage(), 'cannot be opened')) {
                throw $e;
            }
            /**
             * cannot open temporary file - ignore this error
             * @see \Gaufrette\Stream\Local::open
             */
        }
    }

    public function testGetTemporaryFileNameWithoutSuggestedFileName()
    {
        $tmpFileName = $this->fileManager->getTemporaryFileName();
        self::assertNotEmpty($tmpFileName);

        $parts = explode(DIRECTORY_SEPARATOR, $tmpFileName);
        if (0 === strpos($tmpFileName, DIRECTORY_SEPARATOR)) {
            array_shift($parts);
        }
        foreach ($parts as $part) {
            self::assertNotEmpty(
                $part,
                sprintf('Several directory separators follow each other. File Name: %s', $tmpFileName)
            );
        }
    }

    public function testGetTemporaryFileNameWithSuggestedFileNameWithoutExtension()
    {
        $suggestedFileName = sprintf('TestFile%s', str_replace('.', '', uniqid('', true)));
        $tmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
        self::assertNotEmpty($tmpFileName);
        self::assertStringEndsWith(DIRECTORY_SEPARATOR . $suggestedFileName, $tmpFileName);
    }

    public function testGetTemporaryFileNameWithSuggestedFileNameWithExtension()
    {
        $suggestedFileName = sprintf('TestFile%s', str_replace('.', '', uniqid('', true))) . '.txt';
        $tmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
        self::assertNotEmpty($tmpFileName);
        self::assertStringEndsWith(DIRECTORY_SEPARATOR . $suggestedFileName, $tmpFileName);
    }

    public function testGetTemporaryFileNameWithSuggestedFileNameWithoutExtensionWhenFileAlreadyExists()
    {
        $suggestedFileName = sprintf('TestFile%s', str_replace('.', '', uniqid('', true)));
        $tmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
        try {
            if (false !== @file_put_contents($tmpFileName, 'test')) {
                // guard
                self::assertFileExists($tmpFileName, 'guard');

                $anotherTmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
                self::assertNotEmpty($anotherTmpFileName);
                self::assertNotEquals($tmpFileName, $anotherTmpFileName);
                self::assertFileDoesNotExist($anotherTmpFileName);
            }
        } finally {
            @unlink($tmpFileName);
        }
    }

    public function testGetTemporaryFileNameWithSuggestedFileNameWithExtensionWhenFileAlreadyExists()
    {
        $fileExtension = '.txt';
        $suggestedFileName = sprintf('TestFile%s', str_replace('.', '', uniqid('', true))) . $fileExtension;
        $tmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
        try {
            if (false !== @file_put_contents($tmpFileName, 'test')) {
                // guard
                self::assertFileExists($tmpFileName, 'guard');

                $anotherTmpFileName = $this->fileManager->getTemporaryFileName($suggestedFileName);
                self::assertNotEmpty($anotherTmpFileName);
                self::assertNotEquals($tmpFileName, $anotherTmpFileName);
                self::assertStringEndsWith($fileExtension, $anotherTmpFileName);
                self::assertFileDoesNotExist($anotherTmpFileName);
            }
        } finally {
            @unlink($tmpFileName);
        }
    }

    public function testGetPrefixDirectory()
    {
        self::assertEquals(self::TEST_FILE_SYSTEM_NAME, $this->fileManager->getPrefixDirectory());
    }

    public function testGetPrefixDirectoryWithPredefinedDirectory()
    {
        $fileManager = new FileManager(self::TEST_FILE_SYSTEM_NAME, 'predefinedDir');

        self::assertEquals('predefinedDir', $fileManager->getPrefixDirectory());
    }

    public function testMimeType()
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file(__DIR__ . '/Fixtures/test.txt');

        $this->filesystem->expects(self::once())
            ->method('mimeType')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/test_file.txt')
            ->willReturn($mimeType);

        self::assertEquals($mimeType, $this->fileManager->mimeType('test_file.txt'));
    }

    public function testMimeTypeOnNotSupportedMimeAdapter()
    {
        $this->filesystem->expects(self::once())
            ->method('mimeType')
            ->with(self::TEST_FILE_SYSTEM_NAME . '/test_file.txt')
            ->willThrowException(new \LogicException());

        self::assertNull($this->fileManager->mimeType('test_file.txt'));
    }
}
