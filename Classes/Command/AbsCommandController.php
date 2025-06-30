<?php
declare(strict_types=1);

namespace Flownative\Azure\BlobStorage\Command;

/*
 * This file is part of the Flownative.Azure.BlobStorage package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\Common\Persistence\ObjectManager as DoctrineObjectManager;
use Doctrine\DBAL\Driver\Exception as DbalDriverException;
use Doctrine\ORM\EntityManagerInterface;
use Flownative\Azure\BlobStorage\AbsTarget;
use Flownative\Azure\BlobStorage\BlobServiceFactory;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\Storage\StorageObject;

/**
 * Azure Blob Storage command controller
 *
 * @Flow\Scope("singleton")
 */
final class AbsCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var BlobServiceFactory
     */
    protected $blobServiceFactory;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * Checks the connection
     *
     * This command checks if the configured credentials and connectivity allows for connecting with the Azure API.
     *
     * @param string $container The container which is used for trying to upload and retrieve some test data
     */
    public function connectCommand(string $container): void
    {
        try {
            $blobServiceClient = $this->blobServiceFactory->create();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('Writing test object into container (%s) ...', [$container]);
        try {
            $containerClient = $blobServiceClient->getContainerClient($container);
            $blobClient = $containerClient->getBlobClient('Flownative.Azure.BlobStorage.ConnectionTest.txt');
            $options = new UploadBlobOptions(contentType: 'text/plain');
            $blobClient->upload('I am a teapot', $options);
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('Retrieving test object from container ...');
        try {
            $containerClient = $blobServiceClient->getContainerClient($container);
            $blobClient = $containerClient->getBlobClient('Flownative.Azure.BlobStorage.ConnectionTest.txt');
            $result = $blobClient->downloadStreaming();
            $content = $result->content->getContents();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }
        $this->outputLine('Content read back: <em>%s</em>', [$content]);

        $this->outputLine('Deleting test object from container ...');
        try {
            $containerClient = $blobServiceClient->getContainerClient($container);
            $blobClient = $containerClient->getBlobClient('Flownative.Azure.BlobStorage.ConnectionTest.txt');
            $blobClient->delete();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        $this->outputLine('OK');
    }

    /**
     * Republish a collection
     *
     * This command forces publishing resources of the given collection by copying resources from the respective storage
     * to target container.
     *
     * @param string $collection Name of the collection to publish
     */
    public function republishCommand(string $collection = 'persistent'): void
    {
        [$resourceCollection, $target] = $this->getResourceCollectionAndTarget($collection);

        $this->outputLine('Republishing collection ...');
        $this->output->progressStart();
        try {
            foreach ($resourceCollection->getObjects() as $object) {
                /** @var StorageObject $object */
                $resource = $this->resourceManager->getResourceBySha1($object->getSha1());
                if ($resource) {
                    $target->publishResource($resource, $resourceCollection);
                }
                $this->output->progressAdvance();
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Publishing failed</error>');
            $this->outputLine($e->getMessage());
            $this->outputLine($e::class);
            exit(2);
        }
        $this->output->progressFinish();
        $this->outputLine();
    }

    /**
     * Update resource metadata
     *
     * This command iterates through all known resources of a collection and sets the metadata in the configured target.
     * The resource must exist in the target, but metadata like "content-type" will be updated.
     *
     * The resources are processed in alphabetical order of their SHA1 content hashes. That allows you to resume updates
     * at a specific resource (using the --start-sha1 option) in case a large update was interrupted.
     *
     * @param string $collection Name of the collection to publish
     * @param string|null $startSha1 If specified, updates are starting at this SHA1 in alphabetical order
     * @throws \Exception
     * @throws DbalDriverException
     */
    public function updateResourceMetadataCommand(string $collection = 'persistent', string $startSha1 = null): void
    {
        /** @var AbsTarget $target */
        [, $target] = $this->getResourceCollectionAndTarget($collection);
        $targetContainer = $target->getContainerName();

        $this->outputLine();
        $this->outputLine('Updating metadata for resources in container %s ...', [$targetContainer]);
        $this->outputLine();

        try {
            $blobServiceClient = $this->blobServiceFactory->create();
        } catch (\Exception $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            exit(1);
        }

        if ($this->objectManager->isRegistered(EntityManagerInterface::class)) {
            $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        } else {
            $entityManager = $this->objectManager->get(DoctrineObjectManager::class);
        }

        if ($startSha1 === null) {
            $queryResult = $entityManager->getConnection()->executeQuery(
                'SELECT sha1, filename, mediatype FROM neos_flow_resourcemanagement_persistentresource AS r WHERE collectionname = :collectionName ORDER BY sha1',
                ['collectionName' => $collection]
            );
        } else {
            $queryResult = $entityManager->getConnection()->executeQuery(
                'SELECT sha1, filename, mediatype FROM neos_flow_resourcemanagement_persistentresource AS r WHERE collectionname = :collectionName AND sha1 > :startSha1 ORDER BY sha1',
                [
                    'collectionName' => $collection,
                    'startSha1' => $startSha1
                ]
            );
        }

        try {
            $containerClient = $blobServiceClient->getContainerClient($targetContainer);
            $targetKeyPrefix = $target->getKeyPrefix();
            $previousSha1 = null;
            while ($resourceRecord = $queryResult->fetchAssociative()) {
                if ($resourceRecord['sha1'] === $previousSha1) {
                    continue;
                }
                $previousSha1 = $resourceRecord['sha1'];

                try {
                    $blobClient = $containerClient->getBlobClient($targetKeyPrefix . $resourceRecord['sha1'] . '/' . $resourceRecord['filename']);

                    // Get current blob properties
                    $properties = $blobClient->getProperties();
                    $currentContentType = $properties->contentType;
                    $expectedContentType = $resourceRecord['mediatype'];

                    if ($currentContentType === $expectedContentType) {
                        $this->outputLine('   ✅  %s %s (content-type: %s)', [$resourceRecord['sha1'], $resourceRecord['filename'], $currentContentType]);
                    } else {
                        // Content type mismatch - we need to re-upload the blob with correct content type
                        $this->outputLine('   🔄  %s %s (updating content-type from "%s" to "%s")', [$resourceRecord['sha1'], $resourceRecord['filename'], $currentContentType, $expectedContentType]);

                        // Download current content
                        $downloadResult = $blobClient->downloadStreaming();
                        $content = $downloadResult->content;

                        // Re-upload with correct content type
                        $options = new \AzureOss\Storage\Blob\Models\UploadBlobOptions(contentType: $expectedContentType);
                        $blobClient->upload($content, $options);

                        $this->outputLine('   ✅  %s %s (content-type updated)', [$resourceRecord['sha1'], $resourceRecord['filename']]);
                    }
                } catch (\AzureOss\Storage\Blob\Exceptions\BlobNotFoundException $exception) {
                    $this->outputLine('   ❌  <error>%s %s (not found)</error>', [$resourceRecord['sha1'], $resourceRecord['filename']]);
                } catch (\Exception $exception) {
                    $this->outputLine('   ❌  <error>%s %s</error>', [$resourceRecord['sha1'], $resourceRecord['filename']]);
                    $this->outputLine('      %s', [$exception->getMessage()]);
                }
            }
        } catch (\Exception $e) {
            $this->outputLine('<error>Update failed</error>');
            $this->outputLine($e->getMessage());
            exit(2);
        }
        $this->outputLine();
    }

    /**
     * @return array [CollectionInterface, TargetInterface]
     */
    private function getResourceCollectionAndTarget(string $collectionName): array
    {
        $resourceCollection = $this->resourceManager->getCollection($collectionName);
        if (!$resourceCollection) {
            $this->outputLine('<error>The collection %s does not exist.</error>', [$collectionName]);
            exit(1);
        }

        $target = $resourceCollection->getTarget();
        if (!$target instanceof AbsTarget) {
            $this->outputLine('<error>The target defined in collection %s is not an Azure Blob Storage target.</error>', [$collectionName]);
            exit(1);
        }
        return [$resourceCollection, $target];
    }
}