<?php
declare(strict_types=1);

namespace Flownative\Azure\BlobStorage;

/*
 * This file is part of the Flownative.Azure.BlobStorage package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use AzureOss\Storage\Blob\Exceptions\BlobNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ResourceManagement\CollectionInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Flow\ResourceManagement\Storage\StorageObject;
use Neos\Flow\ResourceManagement\Storage\WritableStorageInterface;
use Neos\Flow\Utility\Environment;
use Neos\Utility\Exception\FilesException;
use Psr\Log\LoggerInterface;

/**
 * A resource storage based on Azure Blob Storage
 */
class AbsStorage implements WritableStorageInterface
{
    /**
     * Name of the container which should be used as a storage
     *
     * @var string
     */
    protected $containerName = '';

    /**
     * A prefix to use for the key of container objects used by this storage
     *
     * @var string
     */
    protected $keyPrefix = '';

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ResourceRepository
     */
    protected $resourceRepository;

    /**
     * @Flow\Inject
     * @var BlobServiceFactory
     */
    protected $blobServiceFactory;

    /**
     * @var BlobServiceClient
     */
    protected $blobServiceClient;

    /**
     * @var BlobContainerClient
     */
    protected $containerClient;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     *
     * @param string $name Name of this storage instance, according to the resource settings
     * @param array $options Options for this storage
     * @throws Exception
     */
    public function __construct(protected string $name, array $options = [])
    {
        $this->containerName = $name;
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'container':
                    $this->containerName = $value;
                    break;
                case 'keyPrefix':
                    $this->keyPrefix = ltrim($value, '/');
                    break;
                default:
                    if ($value !== null) {
                        throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource AbsStorage. Please check your settings.', $key, $name), 1621582717);
                    }
            }
        }

        if (empty($this->containerName)) {
            throw new Exception(sprintf('No collection name was specified in the configuration of the "%s" resource AbsStorage. Please check your settings.', $name), 1621582731);
        }
    }

    /**
     * Initialize the Azure Blob Storage service instance
     *
     * @throws Exception
     */
    public function initializeObject(): void
    {
        $this->blobServiceClient = $this->blobServiceFactory->create();
        $this->containerClient = $this->blobServiceClient->getContainerClient($this->containerName);
    }

    /**
     * Returns the instance name of this storage
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the container name used as a storage
     */
    public function getContainerName(): string
    {
        return $this->containerName;
    }

    /**
     * Returns the object key prefix
     */
    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * Imports a resource (file) from the given URI or PHP resource stream into this storage.
     *
     * On a successful import this method returns a PersistentResource object representing the newly
     * imported persistent resource.
     *
     * @param string|resource $source The URI (or local path and filename) or the PHP resource stream to import the resource from
     * @param string $collectionName Name of the collection the new PersistentResource belongs to
     * @return PersistentResource A resource object representing the imported resource
     * @throws Exception
     * @throws \Neos\Flow\Utility\Exception
     * @throws FilesException
     */
    public function importResource($source, $collectionName): PersistentResource
    {
        $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('Flownative_Azure_BlobStorage_', true);

        if (is_resource($source)) {
            try {
                $target = fopen($temporaryTargetPathAndFilename, 'wb');
                stream_copy_to_stream($source, $target);
                fclose($target);
            } catch (\Exception $e) {
                throw new Exception(sprintf('Could import the content stream to temporary file "%s".', $temporaryTargetPathAndFilename), 1621595541, $e);
            }
        } else {
            try {
                copy($source, $temporaryTargetPathAndFilename);
            } catch (\Exception $e) {
                throw new Exception(sprintf('Could not copy the file from "%s" to temporary file "%s".', $source, $temporaryTargetPathAndFilename), 1621595551, $e);
            }
        }

        try {
            $resource = $this->importTemporaryFile($temporaryTargetPathAndFilename, $collectionName);
        } catch (\Exception $e) {
            $message = sprintf('Azure Blob Storage: Could not import the temporary file from "%s" to to collection "%s": %s', $temporaryTargetPathAndFilename, $collectionName, $e->getMessage());
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            throw new Exception($message, 1621583216, $e);
        }
        unlink($temporaryTargetPathAndFilename);

        return $resource;
    }

    /**
     * Imports a resource from the given string content into this storage.
     *
     * On a successful import this method returns a PersistentResource object representing the newly
     * imported persistent resource.
     *
     * The specified filename will be used when presenting the resource to a user. Its file extension is
     * important because the resource management will derive the IANA Media Type from it.
     *
     * @param string $content The actual content to import
     * @param string $collectionName Name of the collection the new PersistentResource belongs to
     * @return PersistentResource A resource object representing the imported resource
     * @api
     */
    public function importResourceFromContent($content, $collectionName): PersistentResource
    {
        $sha1Hash = sha1($content);
        $filename = $sha1Hash;

        $resource = new PersistentResource();
        $resource->setFilename($filename);
        $resource->setFileSize(strlen($content));
        $resource->setCollectionName($collectionName);
        $resource->setSha1($sha1Hash);

        $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $sha1Hash);
        $uploadBlobOptions = new UploadBlobOptions();
        $uploadBlobOptions->contentType = $resource->getMediaType();
        $blobClient->upload($content, $uploadBlobOptions);

        return $resource;
    }

    /**
     * Imports a resource (file) as specified in the given upload info array as a
     * persistent resource.
     *
     * On a successful import this method returns a PersistentResource object representing
     * the newly imported persistent resource.
     *
     * @param array $uploadInfo An array detailing the resource to import (expected keys: name, tmp_name)
     * @param string $collectionName Name of the collection this uploaded resource should be part of
     * @return PersistentResource A resource object representing the imported resource
     * @throws Exception
     * @throws \Exception
     * @api
     */
    public function importUploadedResource(array $uploadInfo, string $collectionName): PersistentResource
    {
        $pathInfo = pathinfo($uploadInfo['name']);
        $originalFilename = $pathInfo['basename'];
        $sourcePathAndFilename = $uploadInfo['tmp_name'];

        if (!file_exists($sourcePathAndFilename)) {
            throw new Exception(sprintf('The temporary file "%s" of the file upload does not exist (anymore).', $sourcePathAndFilename), 1446667850);
        }

        $newSourcePathAndFilename = $this->environment->getPathToTemporaryDirectory() . 'Flownative_Azure_BlobStorage_' . uniqid('', true) . '.tmp';
        if (move_uploaded_file($sourcePathAndFilename, $newSourcePathAndFilename) === false) {
            throw new Exception(sprintf('The uploaded file "%s" could not be moved to the temporary location "%s".', $sourcePathAndFilename, $newSourcePathAndFilename), 1446667851);
        }

        $sha1Hash = sha1_file($newSourcePathAndFilename);

        $resource = new PersistentResource();
        $resource->setFilename($originalFilename);
        $resource->setCollectionName($collectionName);
        $resource->setFileSize(filesize($newSourcePathAndFilename));
        $resource->setSha1($sha1Hash);

        try {
            $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $sha1Hash);
            $blobOptions = new UploadBlobOptions();
            $blobOptions->contentType = $resource->getMediaType();
            $blobClient->upload(
                fopen($newSourcePathAndFilename, 'rb'),
                $blobOptions
            );
        } catch (\Exception $exception) {
            $this->logger->error(sprintf('Azure Blob Storage: Failed importing uploaded resource %s into container %s: %s', $this->keyPrefix . $sha1Hash, $this->getContainerName(), $exception->getMessage()), LogEnvironment::fromMethodName(__METHOD__));
            throw $exception;
        }

        return $resource;
    }

    /**
     * Deletes the storage data related to the given PersistentResource object
     *
     * @param PersistentResource $resource The PersistentResource to delete the storage data of
     * @return bool true if removal was successful
     * @throws \Exception
     * @api
     */
    public function deleteResource(PersistentResource $resource): bool
    {
        try {
            $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $resource->getSha1());
            $blobClient->delete();
            return true;
        } catch (BlobNotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            $message = sprintf('Azure Blob Storage: Could not delete blob for resource %s (/%s/%s%s). %s', $resource->getFilename(), $this->containerName, $this->keyPrefix, $resource->getSha1(), $e->getMessage());
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            throw new Exception($message, 1621627220);
        }
    }

    /**
     * Returns a stream handle which can be used internally to open / copy the given resource
     * stored in this storage.
     *
     * @param PersistentResource $resource The resource stored in this storage
     * @return bool|resource resource handle or FALSE if it does not exist
     * @throws Exception
     * @api
     */
    public function getStreamByResource(PersistentResource $resource)
    {
        try {
            $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $resource->getSha1());
            $result = $blobClient->downloadStreaming();
            return $result->content->detach();
        } catch (BlobNotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            $message = sprintf('Azure Blob Storage: Could not retrieve stream for resource %s (/%s/%s%s). %s', $resource->getFilename(), $this->containerName, $this->keyPrefix, $resource->getSha1(), $e->getMessage());
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            throw new Exception($message, 1621596208, $e);
        }
    }

    /**
     * Returns a stream handle which can be used internally to open / copy the given resource
     * stored in this storage.
     *
     * @param string $relativePath A path relative to the storage root, for example "MyFirstDirectory/SecondDirectory/Foo.css"
     * @return bool|resource resource handle or FALSE if it does not exist
     * @throws Exception
     * @api
     */
    public function getStreamByResourcePath($relativePath)
    {
        try {
            $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . ltrim($relativePath, '/'));
            $result = $blobClient->downloadStreaming();
            return $result->content->detach();
        } catch (BlobNotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            $message = sprintf('Azure Blob Storage: Could not retrieve stream for resource (/%s/%s%s). %s', $this->containerName, $this->keyPrefix, ltrim($relativePath, '/'), $e->getMessage());
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            throw new Exception($message, 1621596215);
        }
    }

    /**
     * Retrieve all Objects stored in this storage.
     *
     * @return StorageObject[]
     * @api
     */
    public function getObjects(): array
    {
        $objects = [];
        foreach ($this->resourceManager->getCollectionsByStorage($this) as $collection) {
            $objects[] = $this->getObjectsByCollection($collection);
        }
        // the empty array covers cases when no loops were made
        return array_merge([], ...$objects);
    }

    /**
     * Retrieve all Objects stored in this storage, filtered by the given collection name
     *
     * @return StorageObject[]
     * @api
     */
    public function getObjectsByCollection(CollectionInterface $collection): array
    {
        $objects = [];
        $containerClient = $this->containerClient;
        $keyPrefix = $this->keyPrefix;

        /** @noinspection PhpUndefinedMethodInspection */
        foreach ($this->resourceRepository->findByCollectionName($collection->getName()) as $resource) {
            /** @var PersistentResource $resource */
            $object = new StorageObject();
            $object->setFilename($resource->getFilename());
            $object->setSha1($resource->getSha1());
            $object->setStream(static function () use ($containerClient, $keyPrefix, $resource) {
                $blobClient = $containerClient->getBlobClient($keyPrefix . $resource->getSha1());
                $result = $blobClient->downloadStreaming();
                return $result->content->detach();
            });
            $objects[] = $object;
        }

        return $objects;
    }

    /**
     * Imports the given temporary file into the storage and creates the new resource object.
     *
     * @param string $temporaryPathAndFilename Path and filename leading to the temporary file
     * @param string $collectionName Name of the collection to import into
     * @return PersistentResource The imported resource
     * @throws \Exception
     */
    protected function importTemporaryFile(string $temporaryPathAndFilename, string $collectionName): PersistentResource
    {
        $sha1Hash = sha1_file($temporaryPathAndFilename);

        $resource = new PersistentResource();
        $resource->setFileSize(filesize($temporaryPathAndFilename));
        $resource->setCollectionName($collectionName);
        $resource->setSha1($sha1Hash);

        try {
            $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $sha1Hash);

            // Check if blob already exists
            if ($blobClient->exists()) {
                $this->logger->info(sprintf('Azure Blob Storage: Did not import resource as object "%s" into container "%s" because that object already existed.', $sha1Hash, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
            } else {
                $uploadBlobOptions = new UploadBlobOptions();
                $uploadBlobOptions->contentType = $resource->getMediaType();
                $blobClient->upload(fopen($temporaryPathAndFilename, 'rb'), $uploadBlobOptions);
            }
        } catch (\Exception $exception) {
            $this->logger->error(sprintf('Azure Blob Storage: Failed importing the temporary file into storage collection "%s": %s', $collectionName, $exception->getMessage()), LogEnvironment::fromMethodName(__METHOD__));
            throw $exception;
        }
        $this->logger->info(sprintf('Azure Blob Storage: Successfully imported resource as object "%s" into container "%s" with SHA1 hash "%s"', $sha1Hash, $this->containerName, $resource->getSha1() ?: 'unknown'), LogEnvironment::fromMethodName(__METHOD__));

        return $resource;
    }
}
