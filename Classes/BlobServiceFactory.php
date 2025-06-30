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

use Neos\Flow\Annotations as Flow;
use AzureOss\Storage\Blob\BlobServiceClient;

/**
 * Factory for the Azure BlobServiceClient class
 *
 * @Flow\Scope("singleton")
 */
class BlobServiceFactory
{
    private const CONNECTIONSTRING_TEMPLATE = 'DefaultEndpointsProtocol=https;AccountName=%1$s;AccountKey=%2$s;EndpointSuffix=core.windows.net';

    /**
     * @Flow\InjectConfiguration
     * @var array
     */
    protected $configuration;

    /**
     * Creates a new BlobServiceClient instance and authenticates against the Azure API
     *
     * @throws Exception
     */
    public function create(string $credentialsProfileName = 'default'): BlobServiceClient
    {
        if (!isset($this->configuration['profiles'][$credentialsProfileName])) {
            throw new Exception(sprintf('The specified Azure Blob Storage credentials profile "%s" does not exist, please check your settings.', $credentialsProfileName), 1621592468);
        }

        if (!empty($this->configuration['profiles'][$credentialsProfileName]['credentials']['connectionString'])) {
            $connectionString = $this->configuration['profiles'][$credentialsProfileName]['credentials']['connectionString'];
        } else {
            $connectionString = sprintf(
                self::CONNECTIONSTRING_TEMPLATE,
                $this->configuration['profiles'][$credentialsProfileName]['credentials']['accountName'],
                $this->configuration['profiles'][$credentialsProfileName]['credentials']['accountKey']
            );
        }
        return BlobServiceClient::fromConnectionString($connectionString);
    }
}
