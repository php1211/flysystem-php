<?php

declare(strict_types=1);

namespace League\Flysystem\AwsS3V3;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use PHPUnit\Framework\TestCase;

class AwsS3V3FilesystemTest extends TestCase
{
    private $shouldCleanUp = false;

    private static $adapterPrefix = 'test-prefix';

    public static function setUpBeforeClass(): void
    {
        static::$adapterPrefix = 'travis-ci/' . bin2hex(random_bytes(10));
    }

    protected function tearDown(): void
    {
        if ( ! $this->shouldCleanUp) {
            return;
        }

        $adapter = $this->adapter();
        /** @var StorageAttributes[] $listing */
        $listing = $adapter->listContents('', false);

        foreach ($listing as $item) {
            if ($item->isFile()) {
                $adapter->delete($item->path());
            } else {
                $adapter->deleteDirectory($item->path());
            }
        }
    }

    private function s3Client(): S3ClientInterface
    {
        $key = getenv('FLYSYSTEM_AWS_S3_KEY');
        $secret = getenv('FLYSYSTEM_AWS_S3_SECRET');
        $bucket = getenv('FLYSYSTEM_AWS_S3_BUCKET');
        $region = getenv('FLYSYSTEM_AWS_S3_REGION') ?: 'eu-central-1';

        if ( ! $key || ! $secret || ! $bucket) {
            $this->markTestSkipped('No AWS credentials present for testing.');
        }

        $this->shouldCleanUp = true;
        $options = ['version' => 'latest', 'credentials' => compact('key', 'secret'), 'region' => $region];

        return new S3Client($options);
    }

    private function adapter(): AwsS3V3Filesystem
    {
        $client = $this->s3Client();
        $bucket = getenv('FLYSYSTEM_AWS_S3_BUCKET');
        $prefix = getenv('FLYSYSTEM_AWS_S3_PREFIX') ?: static::$adapterPrefix;

        return new AwsS3V3Filesystem($client, $bucket, $prefix);
    }

    /**
     * @test
     */
    public function writing_and_reading()
    {
        $adapter = $this->adapter();
        $adapter->write('some/path.txt', 'contents', new Config());
        $contents = $adapter->read('some/path.txt');
        $this->assertEquals('contents', $contents);
    }

    /**
     * @test
     */
    public function writing_with_a_specific_mime_type()
    {
        $adapter = $this->adapter();
        $adapter->write('some/path.txt', 'contents', new Config(['ContentType' => 'text/plain+special']));
        $mimeType = $adapter->mimeType('some/path.txt')->mimeType();
        $this->assertEquals('text/plain+special', $mimeType);
    }

    /**
     * @test
     */
    public function checking_if_files_exist()
    {
        $adapter = $this->adapter();

        $this->assertFalse($adapter->fileExists('some/path.txt'));

        $adapter->write('some/path.txt', 'contents', new Config());

        $this->assertTrue($adapter->fileExists('some/path.txt'));
    }

    /**
     * @test
     */
    public function listing_contents_shallow()
    {
        $adapter = $this->adapter();
        $adapter->write('0_something/here.txt', 'contents', new Config());
        $adapter->write('1_here.txt', 'contents', new Config());

        $contents = iterator_to_array($adapter->listContents('', false));

        $this->assertCount(2, $contents);
        $this->assertContainsOnlyInstancesOf(StorageAttributes::class, $contents);
        /** @var DirectoryAttributes $directory */
        $directory = $contents[0];
        $this->assertInstanceOf(DirectoryAttributes::class, $directory);
        $this->assertEquals('0_something/', $directory->path());
        /** @var FileAttributes $directory */
        $file = $contents[1];
        $this->assertInstanceOf(FileAttributes::class, $file);
        $this->assertEquals('1_here.txt', $file->path());
    }

    /**
     * @test
     */
    public function listing_contents_recursive()
    {
        $adapter = $this->adapter();
        $adapter->write('something/0/here.txt', 'contents', new Config());
        $adapter->write('something/1/also/here.txt', 'contents', new Config());

        $contents = iterator_to_array($adapter->listContents('', true));

        $this->assertCount(2, $contents);
        $this->assertContainsOnlyInstancesOf(FileAttributes::class, $contents);
        /** @var FileAttributes $file */
        $file = $contents[0];
        $this->assertEquals('something/0/here.txt', $file->path());
        /** @var FileAttributes $file */
        $file = $contents[1];
        $this->assertEquals('something/1/also/here.txt', $file->path());
    }
}
