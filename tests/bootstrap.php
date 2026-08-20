<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * A throwaway JWT keypair for the API tests.
 *
 * tests/Functional/SurveyApiTest.php drives /api/* through a real Bearer token, because that
 * firewall is stateless: a session login does not apply to it, and a test that "logged in" without
 * one would be proving something about a door the app does not use.
 *
 * The keys cannot be the developer's own: config/jwt/*.pem is gitignored (a signing key is not a
 * thing to commit) and its passphrase lives in .env.local, which Symfony deliberately does not load
 * in the test environment. So the test environment makes its own pair, passphrase-less, once - which
 * is also what makes CI need no secret and no extra step.
 */
$testKeyDirectory = dirname(__DIR__).'/var/jwt-test';
$testPrivateKey = $testKeyDirectory.'/private.pem';
$testPublicKey = $testKeyDirectory.'/public.pem';

if (!is_file($testPrivateKey) || !is_file($testPublicKey)) {
    if (!is_dir($testKeyDirectory)) {
        mkdir($testKeyDirectory, 0o777, true);
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);

    if (false !== $key) {
        openssl_pkey_export_to_file($key, $testPrivateKey);
        /** @var array{key: string} $details */
        $details = openssl_pkey_get_details($key);
        file_put_contents($testPublicKey, $details['key']);
    }
}

$_ENV['JWT_SECRET_KEY'] = $_SERVER['JWT_SECRET_KEY'] = $testPrivateKey;
$_ENV['JWT_PUBLIC_KEY'] = $_SERVER['JWT_PUBLIC_KEY'] = $testPublicKey;
$_ENV['JWT_PASSPHRASE'] = $_SERVER['JWT_PASSPHRASE'] = '';
