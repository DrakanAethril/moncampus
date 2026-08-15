<?php

declare(strict_types=1);

namespace App\Service;

use Aws\Sqs\SqsClient;

/**
 * Builds the SQS client of the Courrier école (see config/services.yaml).
 *
 * Same pattern as App\Service\S3ClientFactory, and for the same reason: the `endpoint` option must be
 * *absent* and not empty for real AWS. It only exists here to leave the door open to a local emulator
 * (ElasticMQ); in development, the worker talks to the real `dev` queues of the eu-west-3 region,
 * since they exist and no real student writes to them.
 *
 * Careful: like the S3 client and the LDAP adapter, this client validates its region as soon as it is
 * constructed. AWS_MAIL_REGION must therefore be non-empty, including in the environments where
 * nothing consumes a queue.
 */
class SqsClientFactory
{
    public static function create(string $region, string $accessKeyId, string $secretAccessKey, string $endpoint = ''): SqsClient
    {
        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ];

        if ('' !== $endpoint) {
            $config['endpoint'] = $endpoint;
        }

        return new SqsClient($config);
    }
}
