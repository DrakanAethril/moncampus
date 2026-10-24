# Tests

```console
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --filter RoleAccessSmokeTest
```

Three kinds of test live here:

- **`tests/Service/`, `tests/Entity/`, `tests/Util/`** — plain unit tests over pure logic, with
  mocked repositories. No database, no kernel.
- **`tests/Security/Voter/`** — one test per Voter. They go through the public `vote()`, so
  `supports()` is exercised too: a Voter answering on the wrong attribute or subject type widens
  access just as effectively as a wrong verdict.
- **`tests/Functional/`** — real HTTP requests through `WebTestCase`. `RoleAccessSmokeTest` asks for
  each main screen as a student, a teacher, an admin and an external tutor, and pins the answer
  (200 renders / 403 refused / 302 hands over). A 403 that becomes a 200 is a security regression;
  a 200 that becomes a 403 or a 500 is a broken screen.

## The test database (one-off setup)

Functional tests need the `<database>_test` schema that `config/packages/doctrine.yaml` points the
test environment at (`dbname_suffix`). It is separate from the development database and starts
empty — each test creates the rows it needs inside a transaction that is rolled back afterwards, so
tests neither depend on nor damage development data.

Create it once:

```console
# grant the app user rights on the test schema (the dev MySQL container owns the root password)
docker compose exec database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  CREATE DATABASE IF NOT EXISTS beaupeyrat_mgmt_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON beaupeyrat_mgmt_test.* TO \"$MYSQL_USER\"@\"%\"; FLUSH PRIVILEGES;"'

docker compose exec -e APP_ENV=test php bin/console doctrine:schema:create
```

After a schema change, refresh it with
`docker compose exec -e APP_ENV=test php bin/console doctrine:schema:update --force`.

## Running the suite in parallel

ParaTest runs the suite in several processes - about 2 min 20 on the development machine, where
`bin/phpunit` takes over 5 minutes. Each process opens its own database, `<database>_test<N>`:
ParaTest sets `TEST_TOKEN` to the process number, and `dbname_suffix` appends it.

Once, let the app user create those databases (the grant covers every `_test…` name):

```console
docker compose exec database sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  GRANT ALL PRIVILEGES ON \`beaupeyrat\\_mgmt\\_test%\`.* TO \"$MYSQL_USER\"@\"%\"; FLUSH PRIVILEGES;"'
```

Then, the first time and after every schema change, copy `_test` into one database per process, and
warm the test cache so the processes do not all compile the container at once:

```console
docker compose exec -e APP_ENV=test php bin/console doctrine:schema:update --force
docker compose exec -e APP_ENV=test php bin/console app:test:parallel-databases --processes=4
docker compose exec -e APP_ENV=test php bin/console cache:warmup
docker compose exec -e APP_ENV=test php vendor/bin/paratest --processes=4
```

Four processes are as fast as eight: tests are handed out by file, and `RoleAccessSmokeTest` alone
takes a minute and a half. A helper class shared by several test files needs a file of its own
(`tests/Service/Guest/RecordingShell.php`): ParaTest does not load the file that happens to declare
it first.

## Writing a functional test

Extend `App\Tests\Functional\FunctionalTestCase`. It gives you `$this->client`, a `createUser()` and
a `createProgram()` building the smallest `Section > Track > Cohort` + `SchoolYear` + `Program` a
role screen needs to render rather than redirect.

Two things it does that are easy to get wrong on your own:

- **`$this->client->disableReboot()`** — without it the browser reboots the kernel before every
  request, handing each one a fresh database connection that cannot see the fixtures the test wrote
  inside its transaction. Screens then answer 302 "nothing to show" and the test proves nothing.
- **One transaction per test, rolled back in `tearDown()`** — this is what keeps the schema empty
  between tests, and it only works because the test and the requests share that one connection.
