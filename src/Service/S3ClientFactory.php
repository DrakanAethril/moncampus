<?php

namespace App\Service;

use Aws\S3\S3Client;

/**
 * Builds the Aws\S3\S3Client service (see config/services.yaml) - a factory rather than a plain
 * YAML argument array because the endpoint/path-style options must be entirely omitted (not just
 * left empty) for real AWS in production, while dev points them at the local MinIO container.
 */
class S3ClientFactory
{
    public static function create(string $region, string $accessKeyId, string $secretAccessKey, string $endpoint = ''): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ];

        // Only set for local dev (MinIO): real AWS S3 must never get a custom endpoint or
        // path-style addressing, both of which MinIO requires since it has no wildcard DNS.
        if ('' !== $endpoint) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        return new S3Client($config);
    }
}
