<?php

declare(strict_types=1);

namespace App\Service;

use Aws\Sqs\SqsClient;

/**
 * Construit le client SQS du Courrier école (voir config/services.yaml).
 *
 * Même motif que App\Service\S3ClientFactory, et pour la même raison : l'option `endpoint` doit
 * être *absente* et non vide pour du vrai AWS. Elle n'existe ici que pour laisser la porte ouverte
 * à un émulateur local (ElasticMQ) ; en développement, le worker parle aux vraies files `dev` de
 * la région eu-west-3, puisqu'elles existent et qu'aucun élève réel n'écrit dessus.
 *
 * Attention : comme le client S3 et l'adaptateur LDAP, ce client valide sa région dès la
 * construction. AWS_MAIL_REGION doit donc être non vide, y compris dans les environnements où
 * rien ne consomme de file.
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
