<?php

declare(strict_types=1);

namespace App\Service;

use Aws\S3\S3Client;

/**
 * Builds the Aws\S3\S3Client service (see config/services.yaml) - a factory rather than a plain
 * YAML argument array because the endpoint/path-style options must be entirely omitted (not just
 * left empty) for real AWS, which is what every environment now talks to. They stay supported so
 * an S3-compatible stand-in can be pointed at through AWS_S3_ENDPOINT alone, without a code
 * change.
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

        // Only set when AWS_S3_ENDPOINT names an S3-compatible server other than AWS: real AWS S3
        // must never get a custom endpoint, nor path-style addressing - which such a server in turn
        // needs, having no wildcard DNS of its own.
        if ('' !== $endpoint) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        return new S3Client($config);
    }
}
