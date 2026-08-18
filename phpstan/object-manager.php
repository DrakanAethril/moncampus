<?php

/*
 * Boots the kernel so phpstan-doctrine can read the real ORM metadata
 * (entity field types, association targets, repository return types).
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$loader = require dirname(__DIR__).'/vendor/autoload.php';

// A no-op in the ordinary checkout, and the difference between a working run and a wrong one from
// a git worktree: Composer's generated autoloader resolves App\ from the *real* path of vendor/,
// so when vendor is a symlink back to the main checkout, every entity PHPStan is analysing here
// would be read from over there. Prepending this checkout's own src/ makes the metadata match the
// files under analysis - without it, a new entity is simply unknown to the Doctrine extension and
// its findings are silently wrong rather than absent.
$loader->addPsr4('App\\', dirname(__DIR__).'/src/', true);

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
