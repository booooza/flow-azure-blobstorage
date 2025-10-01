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

use Flownative\Azure\BlobStorage\Exception as BlobStorageException;
use GuzzleHttp\Psr7\Uri;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\Models\UploadBlobOptions;
use AzureOss\Storage\Blob\Models\GetBlobsOptions;
use AzureOss\Storage\Blob\Models\BlobHttpHeaders;
use AzureOss\Storage\Blob\Exceptions\BlobNotFoundException;
use Neos\Error\Messages\Error;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\ResourceManagement\CollectionInterface;
use Neos\Flow\ResourceManagement\Exception;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\Publishing\MessageCollector;
use Neos\Flow\ResourceManagement\ResourceMetaDataInterface;
use Neos\Flow\ResourceManagement\Storage\StorageObject;
use Neos\Flow\ResourceManagement\Target\TargetInterface;
use Neos\Flow\Utility\Environment;
use Neos\Utility\MediaTypes;
use Psr\Log\LoggerInterface;

/**
 * A resource publishing target based on Azure Blob Storage
 */
class AbsTarget implements TargetInterface
{
    /**
     * Name of the Azure Blob Storage container which should be used for publication
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
     * @var string
     */
    protected $persistentResourceUriPattern = '';

    /**
     * @string
     */
    private const DEFAULT_PERSISTENT_RESOURCE_URI_PATTERN = '{baseUri}{keyPrefix}{sha1}/{filename}';

    /**
     * @var bool
     */
    protected $persistentResourceUriEnableSigning = false;

    /**
     * @var int
     */
    protected $persistentResourceUriSignatureLifetime = 600;

    /**
     * CORS (Cross-Origin Resource Sharing) allowed origins for published content
     *
     * @var string
     */
    protected $corsAllowOrigin = '*';

    /**
     * @var string
     */
    protected $baseUri = '';

    /**
     * @var array
     */
    protected $customBaseUriMethod = [];

    /**
     * @var int
     */
    protected $gzipCompressionLevel = 9;

    /**
     * @var string[]
     */
    protected $gzipCompressionMediaTypes = [
        'text/plain',
        'text/css',
        'text/xml',
        'text/mathml',
        'text/javascript',
        'application/x-javascript',
        'application/xml',
        'application/rss+xml',
        'application/atom+xml',
        'application/javascript',
        'application/json',
        'application/x-font-woff',
        'image/svg+xml'
    ];

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var MessageCollector
     */
    protected $messageCollector;

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
     * @var Environment
     */
    protected $environment;

    /**
     * @var array
     */
    protected $existingObjectsInfo;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Constructor
     *
     * @param string $name Name of this target instance, according to the resource settings
     * @param array $options Options for this target
     * @throws Exception
     */
    public function __construct(protected string $name, array $options = [])
    {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'container':
                    $this->containerName = $value;
                    break;
                case 'keyPrefix':
                    $this->keyPrefix = ltrim($value, '/');
                    break;
                case 'persistentResourceUris':
                    if (!is_array($value)) {
                        throw new Exception(sprintf('The option "%s" which was specified in the configuration of the "%s" resource AbsTarget is not a valid array. Please check your settings.', $key, $name), 1621597002);
                    }
                    foreach ($value as $uriOptionKey => $uriOptionValue) {
                        switch ($uriOptionKey) {
                            case 'pattern':
                                $this->persistentResourceUriPattern = (string)$uriOptionValue;
                                break;
                            case 'enableSigning':
                                $this->persistentResourceUriEnableSigning = (bool)$uriOptionValue;
                                break;
                            case 'signatureLifetime':
                                $this->persistentResourceUriSignatureLifetime = (int)$uriOptionValue;
                                break;
                            default:
                                if ($uriOptionValue !== null) {
                                    throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource AbsTarget. Please check your settings.', $uriOptionKey, $name), 1621597006);
                                }
                        }
                    }
                    break;
                case 'corsAllowOrigin':
                    $this->corsAllowOrigin = $value;
                    break;
                case 'baseUri':
                    if (!empty($value)) {
                        $this->baseUri = $value;
                    }
                    break;
                case 'customBaseUriMethod':
                    if (!is_array($value)) {
                        throw new Exception(sprintf('The option "%s" which was specified in the configuration of the "%s" resource AbsTarget is not a valid array. Please check your settings.', $key, $name), 1621597146);
                    }
                    if (!isset($value['objectName'], $value['methodName'])) {
                        throw new Exception(sprintf('The option "%s" which was specified in the configuration of the "%s" resource AbsTarget requires exactly two keys ("objectName" and "methodName"). Please check your settings.', $key, $name), 1621597150);
                    }
                    $this->customBaseUriMethod = $value;
                    break;
                case 'gzipCompressionLevel':
                    $this->gzipCompressionLevel = (int)$value;
                    break;
                case 'gzipCompressionMediaTypes':
                    if (!is_array($value)) {
                        throw new Exception(sprintf('The option "%s" which was specified in the configuration of the "%s" resource AbsTarget is not a valid array. Please check your settings.', $key, $name), 1621597162);
                    }
                    foreach ($value as $mediaType) {
                        if (!is_string($mediaType)) {
                            throw new Exception(sprintf('The option "%s" which was specified in the configuration of the "%s" resource AbsTarget is not a valid array of strings. Please check your settings.', $key, $name), 1621597167);
                        }
                    }
                    $this->gzipCompressionMediaTypes = $value;
                    break;
                default:
                    if ($value !== null) {
                        throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource AbsTarget. Please check your settings.', $key, $name), 1621597192);
                    }
            }
        }
    }

    /**
     * Initialize the Azure Blob Storage instance
     *
     * @throws BlobStorageException
     * @throws Exception
     */
    public function initializeObject(): void
    {
        $this->blobServiceClient = $this->blobServiceFactory->create();
        $this->containerClient = $this->blobServiceClient->getContainerClient($this->containerName);

        if ($this->customBaseUriMethod !== []) {
            if (!$this->objectManager->isRegistered($this->customBaseUriMethod['objectName'])) {
                throw new Exception(sprintf('Unknown object "%s" defined as custom base URI method in the configuration of the "%s" resource AbsTarget. Please check your settings.', $this->customBaseUriMethod['objectName'], $this->name), 1621597225);
            }
            $object = $this->objectManager->get($this->customBaseUriMethod['objectName']);
            $methodName = $this->customBaseUriMethod['methodName'];
            if (!method_exists($object, $methodName)) {
                throw new Exception(sprintf('Unknown method "%s->%s" defined as custom base URI method in the configuration of the "%s" resource AbsTarget. Please check your settings.', $this->customBaseUriMethod['objectName'], $methodName, $this->name), 1621597231);
            }
            $this->baseUri = $object->$methodName(
                [
                    'targetClass' => $this::class,
                    'containerName' => $this->containerName,
                    'keyPrefix' => $this->keyPrefix,
                    'baseUri' => $this->baseUri,
                    'persistentResourceUriEnableSigning' => $this->persistentResourceUriEnableSigning
                ]
            );
        }
    }

    /**
     * Returns the name of this target instance
     *
     * @return string The target instance name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the object key prefix
     */
    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getContainerName(): string
    {
        return $this->containerName;
    }

    /**
     * Publishes the whole collection to this target
     *
     * @param CollectionInterface $collection The collection to publish
     * @throws \Exception
     * @throws \Neos\Flow\Exception
     */
    public function publishCollection(CollectionInterface $collection): void
    {
        if ($this->isOneContainerSetup($collection)) {
            throw new Exception(sprintf('Could not publish collection %s because the source and target collection is the same. Choose a different container for the target.', $collection->getName()), 1622103455);
        }

        $storage = $collection->getStorage();

        if (!isset($this->existingObjectsInfo)) {
            $this->existingObjectsInfo = [];

            $options = new GetBlobsOptions();
            foreach ($this->containerClient->getBlobs($this->keyPrefix, $options) as $blob) {
                $this->existingObjectsInfo[$blob->name] = true;
            }
        }

        $obsoleteObjects = $this->existingObjectsInfo;

        if ($storage instanceof AbsStorage) {
            $this->publishCollectionFromDifferentAzureBlobStorage($collection, $storage, $this->existingObjectsInfo, $obsoleteObjects);
        } else {
            foreach ($collection->getObjects() as $object) {
                /** @var StorageObject $object */
                $this->publishFile($object->getStream(), $this->getRelativePublicationPathAndFilename($object), $object);
                unset($obsoleteObjects[$this->getRelativePublicationPathAndFilename($object)]);
            }
        }

        $this->logger->info(sprintf('Removing %s obsolete objects from target container "%s".', count($obsoleteObjects), $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
        foreach (array_keys($obsoleteObjects) as $relativePathAndFilename) {
            try {
                $blobClient = $this->containerClient->getBlobClient($this->keyPrefix . $relativePathAndFilename);
                $blobClient->delete();
            } catch (BlobNotFoundException $e) {
                // Ignore if blob doesn't exist
            }
        }
    }

    /**
     * @throws \Neos\Flow\Exception
     */
    private function publishCollectionFromDifferentAzureBlobStorage(CollectionInterface $collection, AbsStorage $storage, array $existingObjects, array &$obsoleteObjects): void
    {
        $storageContainer = $storage->getContainerName();
        $iteration = 0;

        $this->logger->info(sprintf('Found %s existing objects in target container "%s".', count($existingObjects), $this->containerName), LogEnvironment::fromMethodName(__METHOD__));

        foreach ($collection->getObjects() as $object) {
            /** @var StorageObject $object */
            $targetObjectName = $this->keyPrefix . $this->getRelativePublicationPathAndFilename($object);
            if (isset($existingObjects[$targetObjectName])) {
                $this->logger->debug(sprintf('Skipping object "%s" because it already exists in container "%s"', $targetObjectName, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
                unset($obsoleteObjects[$targetObjectName]);
                continue;
            }

            if (in_array($object->getMediaType(), $this->gzipCompressionMediaTypes, true)) {
                try {
                    $this->publishFile($object->getStream(), $this->getRelativePublicationPathAndFilename($object), $object);
                } catch (\Exception $e) {
                    $this->messageCollector->append(sprintf('Could not publish resource with SHA1 hash %s of collection %s from container %s to %s: %s', $object->getSha1(), $collection->getName(), $storageContainer, $this->containerName, $e->getMessage()));
                }
                $this->logger->debug(sprintf('Successfully copied resource as object "%s" (SHA1: %s) from container "%s" to container "%s" (with GZIP compression)', $targetObjectName, $object->getSha1() ?: 'unknown', $storageContainer, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
            } else {
                $this->logger->debug(sprintf('Copy object "%s" to container "%s"', $targetObjectName, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
                try {
                    $sourceBlobClient = $this->blobServiceClient->getContainerClient($storageContainer)->getBlobClient($storage->getKeyPrefix() . $object->getSha1());
                    $targetBlobClient = $this->containerClient->getBlobClient($targetObjectName);

                    $targetBlobClient->startCopyFromUri($sourceBlobClient->uri);
                    $targetBlobClient->setHttpHeaders(new BlobHttpHeaders(
                        contentType: MediaTypes::getMediaTypeFromFilename($targetObjectName),
                    ));
                } catch (\Exception $e) {
                    $this->messageCollector->append(sprintf('Could not copy resource with SHA1 hash %s of collection %s from container %s to %s: %s', $object->getSha1(), $collection->getName(), $storageContainer, $this->containerName, $e->getMessage()));
                    continue;
                }
                $this->logger->debug(sprintf('Successfully copied resource as object "%s" (SHA1: %s) from container "%s" to container "%s"', $targetObjectName, $object->getSha1() ?: 'unknown', $storageContainer, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
            }
            unset($targetObjectName);
            $iteration++;
        }

        $this->logger->info(sprintf('Published %s new objects to target container "%s".', $iteration, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
    }

    /**
     * Returns the web accessible URI pointing to the given static resource
     *
     * @param string $relativePathAndFilename Relative path and filename of the static resource
     * @return string The URI
     */
    public function getPublicStaticResourceUri($relativePathAndFilename): string
    {
        $relativePathAndFilename = $this->encodeRelativePathAndFilenameForUri($relativePathAndFilename);
        return sprintf('https://%s.blob.core.windows.net/%s/%s%s', $this->blobServiceClient->uri->getHost(), $this->containerName, $this->keyPrefix, $relativePathAndFilename);
    }

    /**
     * Publishes the given persistent resource from the given storage
     *
     * @param PersistentResource $resource The resource to publish
     * @param CollectionInterface $collection The collection the given resource belongs to
     * @throws Exception
     * @throws \Exception
     */
    public function publishResource(PersistentResource $resource, CollectionInterface $collection): void
    {
        if ($this->isOneContainerSetup($collection)) {
            throw new Exception(sprintf('Could not publish resource with SHA1 hash %s of collection %s because the source and target collection is the same. Choose a different container for the target.', $resource->getSha1(), $collection->getName()), 1622103493);
        }

        $storage = $collection->getStorage();
        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
        $storageContainer = $storage->getContainerName();

        if ($storage instanceof AbsStorage && !in_array($resource->getMediaType(), $this->gzipCompressionMediaTypes, true)) {
            $targetObjectName = $this->keyPrefix . $this->getRelativePublicationPathAndFilename($resource);

            try {
                $sourceBlobClient = $this->blobServiceClient->getContainerClient($storageContainer)->getBlobClient($storage->getKeyPrefix() . $resource->getSha1());
                $targetBlobClient = $this->containerClient->getBlobClient($targetObjectName);

                $targetBlobClient->startCopyFromUri($sourceBlobClient->uri);
                $targetBlobClient->setHttpHeaders(new BlobHttpHeaders(
                    contentType: $resource->getMediaType(),
                ));
            } catch (\Exception $e) {
                $this->messageCollector->append(sprintf('Could not copy resource with SHA1 hash %s of collection %s from container %s to %s: %s', $resource->getSha1(), $collection->getName(), $storageContainer, $this->containerName, $e->getMessage()), Error::SEVERITY_ERROR, 1621630147);
                return;
            }

            $this->logger->debug(sprintf('Successfully published resource as object "%s" (SHA1: %s) by copying from container "%s" to container "%s"', $targetObjectName, $resource->getSha1() ?: 'unknown', $storageContainer, $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
        } else {
            $sourceStream = $resource->getStream();
            if ($sourceStream === false) {
                $this->messageCollector->append(sprintf('Could not publish resource with SHA1 hash %s of collection %s because there seems to be no corresponding data in the storage.', $resource->getSha1(), $collection->getName()), Error::SEVERITY_ERROR, 1621630156);
                return;
            }
            $this->publishFile($sourceStream, $this->getRelativePublicationPathAndFilename($resource), $resource);
        }
    }

    /**
     * Unpublishes the given persistent resource
     *
     * @param PersistentResource $resource The resource to unpublish
     * @throws \Exception
     */
    public function unpublishResource(PersistentResource $resource): void
    {
        $objectName = $this->keyPrefix . $this->getRelativePublicationPathAndFilename($resource);
        try {
            $blobClient = $this->containerClient->getBlobClient($objectName);
            $blobClient->delete();
        } catch (BlobNotFoundException $e) {
            // Ignore if blob doesn't exist
        }

        $this->logger->debug(sprintf('Successfully unpublished resource as object "%s" (SHA1: %s) from container "%s"', $objectName, $resource->getSha1() ?: 'unknown', $this->containerName), LogEnvironment::fromMethodName(__METHOD__));
    }

    /**
     * Returns the web accessible URI pointing to the specified persistent resource
     *
     * @param PersistentResource $resource PersistentResource object or the resource hash of the resource
     * @return string The URI
     */
    public function getPublicPersistentResourceUri(PersistentResource $resource): string
    {
        $baseUri = $this->baseUri;
        $customUri = $this->persistentResourceUriPattern;
        if (empty($customUri)) {
            if (empty($baseUri)) {
                $baseUri = sprintf('https://%s.blob.core.windows.net/', $this->getAccountNameFromServiceClient());
                $customUri = '{baseUri}{containerName}/{keyPrefix}{sha1}/{filename}';
            } else {
                $customUri = self::DEFAULT_PERSISTENT_RESOURCE_URI_PATTERN;
            }
        }

        $variables = [
            '{baseUri}' => $baseUri,
            '{containerName}' => $this->containerName,
            '{keyPrefix}' => $this->keyPrefix,
            '{sha1}' => $resource->getSha1(),
            '{filename}' => $resource->getFilename(),
            '{fileExtension}' => $resource->getFileExtension()
        ];

        foreach ($variables as $placeholder => $replacement) {
            $customUri = str_replace($placeholder, $replacement, $customUri);
        }

        // Let Uri implementation take care of encoding the Uri
        $uri = new Uri($customUri);
        return (string)$uri;
    }

    /**
     * Publishes the specified source file to this target, with the given relative path.
     *
     * @param resource $sourceStream
     * @throws \Exception
     */
    protected function publishFile($sourceStream, string $relativeTargetPathAndFilename, ResourceMetaDataInterface $metaData): void
    {
        $objectName = $this->keyPrefix . $relativeTargetPathAndFilename;

        $options = new UploadBlobOptions(contentType: $metaData->getMediaType());

        if (in_array($metaData->getMediaType(), $this->gzipCompressionMediaTypes, true)) {
            try {
                $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('Flownative_Azure_BlobStorage_', true);
                $temporaryTargetStream = gzopen($temporaryTargetPathAndFilename, 'wb' . $this->gzipCompressionLevel);
                while (!feof($sourceStream)) {
                    gzwrite($temporaryTargetStream, fread($sourceStream, 524288));
                }
                fclose($sourceStream);
                fclose($temporaryTargetStream);

                /** @noinspection CallableParameterUseCaseInTypeContextInspection */
                $sourceStream = fopen($temporaryTargetPathAndFilename, 'rb');

                $this->logger->debug(sprintf('Converted resource data of object "%s" in container "%s" with SHA1 hash "%s" to GZIP with level %s.', $objectName, $this->containerName, $metaData->getSha1() ?: 'unknown', $this->gzipCompressionLevel), LogEnvironment::fromMethodName(__METHOD__));
            } catch (\Exception $e) {
                $this->messageCollector->append(sprintf('Failed publishing resource as object "%s" in container "%s" with SHA1 hash "%s": %s', $objectName, $this->containerName, $metaData->getSha1() ?: 'unknown', $e->getMessage()), Error::SEVERITY_WARNING, 1621598552);
            }
        }

        try {
            $blobClient = $this->containerClient->getBlobClient($objectName);
            $blobClient->upload($sourceStream, $options);
            $this->logger->debug(sprintf('Successfully published resource as object "%s" in container "%s" with SHA1 hash "%s"', $objectName, $this->containerName, $metaData->getSha1() ?: 'unknown'), LogEnvironment::fromMethodName(__METHOD__));
        } catch (\Exception $e) {
            $this->messageCollector->append(sprintf('Failed publishing resource as object "%s" in container "%s" with SHA1 hash "%s": %s', $objectName, $this->containerName, $metaData->getSha1() ?: 'unknown', $e->getMessage()), Error::SEVERITY_WARNING, 1621598556);
        } finally {
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }
            if (isset($temporaryTargetPathAndFilename) && file_exists($temporaryTargetPathAndFilename)) {
                unlink($temporaryTargetPathAndFilename);
            }
        }
    }

    /**
     * Determines and returns the relative path and filename for the given Storage Object or PersistentResource. If the given
     * object represents a persistent resource, its own relative publication path will be empty. If the given object
     * represents a static resources, it will contain a relative path.
     *
     * @param ResourceMetaDataInterface $object PersistentResource or Storage Object
     * @return string The relative path and filename, for example "c828d0f88ce197be1aff7cc2e5e86b1244241ac6/MyPicture.jpg"
     */
    protected function getRelativePublicationPathAndFilename(ResourceMetaDataInterface $object): string
    {
        if ($object->getRelativePublicationPath() !== '') {
            $pathAndFilename = $object->getRelativePublicationPath() . $object->getFilename();
        } else {
            $pathAndFilename = $object->getSha1() . '/' . $object->getFilename();
        }
        return $pathAndFilename;
    }

    /**
     * Applies rawurlencode() to all path segments of the given $relativePathAndFilename
     */
    private function encodeRelativePathAndFilenameForUri(string $relativePathAndFilename): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $relativePathAndFilename)));
    }

    /**
     * Checks if the containers as storage and target are the same
     */
    private function isOneContainerSetup(CollectionInterface $collection): bool
    {
        $storage = $collection->getStorage();
        return (
            $storage instanceof AbsStorage &&
            $storage->getContainerName() === $this->containerName
        );
    }

    /**
     * Extract account name from the blob service client URI
     */
    private function getAccountNameFromServiceClient(): string
    {
        $host = $this->blobServiceClient->uri->getHost();
        // Extract account name from host like "accountname.blob.core.windows.net"
        return explode('.', $host)[0];
    }
}
