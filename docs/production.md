# Deploying in Production

Symfony Docker provides Docker images and a Docker Compose definition optimized
for production usage.
In this tutorial, we will learn how to deploy our Symfony application
on a single server using Docker Compose.

## Preparing a Server

To deploy your application in production, you need a server.
In this tutorial, we will use a virtual machine provided by DigitalOcean,
but any Linux server can work.

If you already have a Linux server with Docker Compose installed,
you can skip straight to [the next section](#configuring-a-domain-name).

Otherwise, use [this affiliate link](https://m.do.co/c/5d8aabe3ab80)
to get $100 of free credit, create an account, then click on "Create a Droplet".
Then, click on the "Marketplace" tab under the "Choose an image" section
and search for the app named "Docker".
This will provision an Ubuntu server with the latest versions of Docker and
Docker Compose already installed!

For test purposes, the cheapest plans will be enough,
even though you might want at least 2GB of RAM to execute Docker Compose
for the first time.
For real production usage,
you'll probably want to pick a plan in the "general purpose" section
to fit your needs.

![Deploying a Symfony app on DigitalOcean with Docker Compose](digitalocean-droplet.png)

You can keep the defaults for other settings, or tweak them according to your needs.
Don't forget to add your SSH key or create a password
then press the "Finalize and create" button.

Then, wait a few seconds while your Droplet is provisioning.
When your Droplet is ready, use SSH to connect:

```console
ssh root@<droplet-ip>
```

## Configuring a Domain Name

In most cases, you'll want to associate a domain name with your site.
If you don't own a domain name yet, you'll have to buy one through a registrar.

Then create a DNS record of type `A` for your domain name pointing
to the IP address of your server:

```dns
your-domain-name.example.com.  IN  A     207.154.233.113
```

Example with the DigitalOcean Domains service ("Networking" > "Domains"):

![Configuring DNS on DigitalOcean](digitalocean-dns.png)

> [!NOTE]
>
> Let's Encrypt, the service used by default by Symfony Docker to automatically
> generate a TLS certificate doesn't support using bare IP addresses.
> Using a domain name is mandatory to use Let's Encrypt.

## Deploying

Copy your project on the server using `git clone`, `scp`, or any other tool
that may fit your need.
If you use GitHub, you may want to use [a deploy key](https://docs.github.com/en/free-pro-team@latest/developers/overview/managing-deploy-keys#deploy-keys).
Deploy keys are also [supported by GitLab](https://docs.gitlab.com/user/project/deploy_keys/).

> [!IMPORTANT]
>
> The mobile app binaries under `public/downloads/*.apk`/`*.ipa` (see that directory's own
> README) are stored via [Git LFS](https://git-lfs.com), not as regular blobs. The server needs
> `git-lfs` installed (`apt-get install git-lfs`, then `git lfs install` once) **before** cloning,
> or `docker compose build` will copy a small LFS pointer text file into the image instead of the
> real APK - the Ressources download pages would then serve broken files. If the repo was already
> cloned without `git-lfs` present, run `git lfs pull` in it once `git-lfs` is installed.

Example with Git:

```console
git clone git@github.com:<username>/<project-name>.git
```

Go into the directory containing your project (`<project-name>`),
and start the app in production mode:

```console
# Build fresh production image
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache

# Create .env.prod.local (gitignored, never committed) next to compose.yaml and fill in every
# value - see .env.prod.local.example for the full list (APP_SECRET, database, Mercure, LDAP,
# S3/CloudFront, SES).
cp .env.prod.local.example .env.prod.local
# then edit .env.prod.local with real values, including a cryptographically secure APP_SECRET

# Start container
SERVER_NAME=your-domain-name.example.com \
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

Be sure to replace `your-domain-name.example.com` with your actual domain name. Everything else -
`APP_SECRET`, the database connection, Mercure JWT keys, LDAP bind credentials, and S3/CloudFront
configuration for file uploads (avatars and future features) - is read from `.env.prod.local` via
`compose.prod.yaml`'s `env_file:` (Docker Compose's own `${}` substitution can't read that file
directly - see `compose.yaml`'s comments, and don't try passing `APP_SECRET=...` inline on this
command instead: `compose.prod.yaml` no longer looks for it there at all, only in
`.env.prod.local`). None of these vars have a default anywhere in the compose files, and
`docker compose up` refuses to start at all if `.env.prod.local` doesn't exist - deliberately, so
a missing secret fails the deployment loudly instead of silently falling back to an insecure
value. To change any of these later, edit `.env.prod.local` (or `SERVER_NAME` inline) and re-run
the command - no rebuild needed.

Your server is up and running, and a HTTPS certificate has been automatically
generated for you.
Go to `https://your-domain-name.example.com` and enjoy!

## Turning on the deployment banner

While a deploy runs, everyone using the platform gets a strip at the top of the window saying it is
about to restart (`App\Controller\DeploymentNoticeController`). It goes up at the same instant the
Discord channel says the deploy started and comes down when the channel announces the outcome, so
the two describe the same window.

It needs one shared secret in two places, and they must hold the **same string**:

```console
# On the server, generate one and put it in .env.prod.local:
php -r 'echo bin2hex(random_bytes(32)), "\n";'
# DEPLOYMENT_NOTICE_TOKEN=<the value>
```

Then create a repository secret named `BEAUP_DEPLOYMENT_NOTICE_TOKEN` with that same value, in the
`production` environment next to the VPN and SSH credentials. Nothing else to do - the two
`deployment_notice.py` steps in `.github/workflows/deploy.yaml` do the rest.

**Blank is safe and merely silent.** With no token set the write route answers 404, the workflow's
two steps log a line and exit 0, and nobody ever sees a banner - a deploy is never held up by it.
That is also what happens on the very first deploy after this feature ships, since the production
still running at that moment does not know the route yet.

**The banner cannot get stuck.** It is lowered on success, on failure and on a cancelled run, and
past that it expires on its own after thirty minutes
(`App\Service\DeploymentNoticeBoard::DEFAULT_WINDOW_MINUTES`) whatever the workflow did or did not
manage to announce. To check the state by hand, or to take one down:

```console
curl -s https://your-domain-name.example.com/deployment/notice
curl -s -X POST https://your-domain-name.example.com/deployment/notice \
  -H "Authorization: Bearer $DEPLOYMENT_NOTICE_TOKEN" -d 'phase=success'
```

## Generating the mobile-app JWT signing keypair

The mobile apps (MonCampus, e-CO) authenticate via `POST /api/login`
(`App\Security\ApiLdapAuthenticator`), which issues a JWT on top of the same LDAP bind check web
login uses - web session login never touches this. That keypair is gitignored
(`config/jwt/*.pem`, per-environment) and `compose.prod.yaml` bind-mounts it read-only from
`config/jwt/` next to `compose.yaml` on the host, the same way it does `ldap-ca.pem` - so it
survives the `php` container being rebuilt from scratch on every deploy. This is a **one-time**
step per server (only redo it if the keypair is ever lost/rotated):

```console
mkdir -p config/jwt

# JWT_PASSPHRASE must already be set in .env.prod.local before generating - lexik/jwt-
# authentication-bundle uses it to encrypt the private key at generation time, so setting it
# afterwards produces a keypair the app can't actually decrypt.
echo "JWT_PASSPHRASE=$(openssl rand -base64 32)" >> .env.prod.local

docker compose -f compose.yaml -f compose.prod.yaml up --wait
docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console lexik:jwt:generate-keypair
```

If you skip this, mobile login fails for every user (a 500 from the missing keypair, masked by
the app as a generic "wrong credentials" error) while web login keeps working fine, since it never
needs a JWT at all.

## Turning on antivirus scanning of uploads

Every upload on the platform is scanned by ClamAV before a byte reaches S3
(`App\Service\AntivirusScanner`). `compose.prod.yaml` starts the `clamav` service for it - the base
`compose.yaml` deliberately does not, since that file is also CI's boot path and ClamAV downloads a
few hundred megabytes of signatures on first start and holds roughly 1.5 GB of RAM.

**The `php` container reads `ANTIVIRUS_DSN` from `.env.prod.local` only.** Left blank there,
scanning is not broken - it is *off*, which is a state nothing in the application announces: files
upload normally, nothing is logged, no alert fires. A server that never got the variable looks
exactly like a protected one. This is a **one-time** step per server:

```console
# 1. Bring the stack up and wait for clamav to be healthy - the first start has a signature
#    database to fetch, which is why its healthcheck allows a 300s start period. Do not skip
#    ahead: scanning fails closed, so a DSN pointing at a clamd that is still downloading
#    refuses *every* upload in the meantime.
docker compose -f compose.yaml -f compose.prod.yaml up --wait
docker compose -f compose.yaml -f compose.prod.yaml ps clamav
docker compose -f compose.yaml -f compose.prod.yaml logs --tail=50 clamav

# 2. Then point the app at it, and recreate php so it re-reads the env file.
echo "ANTIVIRUS_DSN=clamav://clamav:3310" >> .env.prod.local
docker compose -f compose.yaml -f compose.prod.yaml up -d --wait php

# 3. Prove it, rather than assume it. The command scans a clean file and a known-hostile one
#    (the EICAR test string, the standard harmless stand-in for a virus) and exits non-zero
#    unless uploads are genuinely being refused.
docker compose -f compose.yaml -f compose.prod.yaml exec php bin/console app:antivirus:check
```

The signature database lives in the `clamav_db` named volume so a redeploy does not re-download it
- which would otherwise leave every upload refused for the minutes freshclam takes.

Watch the host's memory the first time: ClamAV's ~1.5 GB sits alongside the `php` container, whose
worker count is sized in `compose.prod.yaml` against the host's total RAM. If the two do not fit,
lower `FRANKENPHP_WORKER_CONFIG: num` there, or give clamd `ConcurrentDatabaseReload no` so it
stops doubling its database in memory during a reload.

## Connecting to an LDAP server over LDAPS

Production is expected to point `LDAP_HOST` at a real corporate LDAP/AD server rather than the
dev-only `openldap` container, and that server may require an encrypted connection - a Samba 4 AD
DC does, over LDAPS on port 636 by default, using a certificate that's self-signed unless someone
has since replaced it with one from a real CA.

1. On the Samba server, locate its self-signed CA certificate - by default (a fresh
   `samba-tool domain provision`) this is `/var/lib/samba/private/tls/ca.pem`; check `smb.conf`'s
   `tls cafile` directive if your install customized the path.
2. Commit that file at the repo root as `ldap-ca.pem` (it's a public CA certificate, not a
   secret - safe to commit, unlike `.env.prod.local`). `compose.prod.yaml` mounts it read-only into
   the `php` container; `docker compose up` refuses to start if it's missing.
3. In `.env.prod.local`, set:
   ```
   LDAP_PORT=636
   LDAP_ENCRYPTION=ssl
   LDAP_TLS_CA_CERT_PATH=/etc/moncampus/ldap-ca.pem
   ```
   (`LDAP_TLS_CA_CERT_PATH` must match the in-container path from `compose.prod.yaml`'s volume
   mount, not the host path of `ldap-ca.pem` itself.)

This verifies the server's certificate against that specific CA file (`App\Service\LdapAdapterFactory`)
rather than either trusting any certificate or requiring a public CA chain - appropriate for a
self-signed internal cert. If the Samba server is ever reissued with a certificate from a real CA,
replace `ldap-ca.pem` with that CA's certificate instead.

Leaving `LDAP_TLS_CA_CERT_PATH` blank keeps plain unencrypted LDAP (`LDAP_ENCRYPTION=none`,
`LDAP_PORT=389` or whatever the server's plain port is) - only appropriate if the LDAP server and
this app are on a network you already trust, since credentials would cross it unencrypted.

> [!CAUTION]
>
> Docker can have a cache layer, make sure you have the right build
> for each deployment or rebuild your project with `--no-cache` option
> to avoid cache issues.

## Sending email through AWS SES

Production sends real mail through AWS SES (`config/packages/mailer.yaml`'s `when@prod` block
builds the DSN from `AWS_SES_*` in `.env.prod.local`, percent-encoding the credentials first - a
raw AWS secret key routinely contains "/" or "+", either of which breaks a hand-built DSN string);
dev sends nothing real at all - every email goes to the `mailer` compose service (Mailpit),
viewable at `http://localhost:<mapped 8025 port>` (`docker compose port mailer 8025`) instead of a
real inbox.

1. In AWS SES, verify `beaupeyrat.org` as a sender identity (domain or DKIM verification - not
   just the single `noreply@beaupeyrat.org` address) in whichever region you intend to use. SES
   is available in most regions, `eu-west-3` (Paris) included - an earlier version of this note
   claimed otherwise, which was true of SES *inbound* years ago and is no longer true of either
   direction. Any SES-supported region works as long as the domain is verified there; identities
   and sandbox status are both per-region, so verifying in one region grants nothing in another.
2. Create a dedicated IAM user for SES, separate from the one behind `AWS_ACCESS_KEY_ID`/
   `AWS_SECRET_ACCESS_KEY` (S3) - scoped to just the `ses:SendEmail` and `ses:SendRawEmail`
   permissions, e.g.:
   ```json
   {
       "Version": "2012-10-17",
       "Statement": [
           {
               "Sid": "AllowSendFromBeaupeyratOrg",
               "Effect": "Allow",
               "Action": ["ses:SendEmail", "ses:SendRawEmail"],
               "Resource": "arn:aws:ses:eu-west-1:<ACCOUNT_ID>:identity/beaupeyrat.org"
           }
       ]
   }
   ```
   (swap in your account ID and `AWS_SES_REGION`; `"Resource": "*"` also works if you'd rather not
   look up the exact identity ARN). Fill in its access key/secret as `AWS_SES_ACCESS_KEY_ID`/
   `AWS_SES_SECRET_ACCESS_KEY` in `.env.prod.local`.
3. Set `AWS_SES_REGION` to the region you verified the domain in. All three values are plain,
   unencoded strings - paste them exactly as AWS shows them, no manual encoding needed.
4. A new AWS account's SES starts in the **sandbox**: it can only send to addresses/domains
   you've also individually verified as recipients. Request production access in the SES console
   before sending to real, unverified recipients (e.g. real staff/student addresses).

## Scheduled tasks (the `worker` service)

Everything the platform does on its own, on a clock, is declared in **one place**:
`App\Scheduler\PlatformSchedule`. The `worker` service of `compose.prod.yaml` consumes that schedule
(`messenger:consume scheduler_default`) and runs each task - the same console commands a crontab on
this host used to `docker compose exec` into `php`. **There is no crontab any more**, and a deploy
starts the worker by itself: `up --wait` brings it up with the rest.

| Task | When (Paris time) | Section |
|---|---|---|
| `app:mail:consume-inbound`, `app:mail:consume-events` | every minute | Courrier pro, below |
| `app:vm-batch:advance` | every minute | CLAUDE.md, « Infrastructure et machines virtuelles » |
| `app:ldap:apply-account-requests` | every minute | Closing the loop on account operations |
| `app:eco:read-terrain` | every minute | e-CO and the IGN's Géoplateforme, below |
| `app:rncp:fetch` | every minute | Portfolio: France compétences' open data, below |
| `app:proxmox:check` | every 5 minutes | the command's own docblock |
| `app:proxmox:scan-addresses` | every 5 minutes, offset by 2 | the command's own docblock |
| `app:mail:reconcile` | 02:30 | Courrier pro, below |
| `app:uploads:purge` | 03:00 | the command's own docblock (design/validated/object-deletion.md) |
| `app:purge-platform-activity` | 03:15 | Retention |
| `app:counters:recompute` | 03:45 | Recomputing the stored counters |
| `app:game:close-month` | 04:30 | Closing the campus game's months |
| `app:proxmox:expire-batches` | 07:00 | the command's own docblock (reminds, never destroys) |
| `app:rncp:check` | Mondays 05:30 | Portfolio: France compétences' open data, below |

What changed, and what to know:

- **A failure is heard.** A task that exits non-zero is logged at *error* level with the end of its
  output, so it reaches Discord like any other production error
  (`App\Scheduler\ScheduledCommandLogger`). A task that succeeds leaves one notice line:
  `docker compose -f compose.yaml -f compose.prod.yaml logs worker` is what replaces the
  `/var/log/moncampus-*.log` files (rotated by Docker at 5 x 10 MB).
- **One task at a time.** The worker runs the tasks one after the other, so two of them never
  overlap - and a slow nightly task delays the every-minute ones by its own duration, after which
  each of those runs once, not once per minute it missed.
- **The worker restarts itself every hour** (`--time-limit=3600`, brought back by `restart:
  unless-stopped`) and whenever it passes 192 MB. The schedule is *stateful*: a task whose minute
  fell during the restart runs when the worker comes back. A redeploy starts a fresh container with
  no such memory - a nightly task is only skipped if the deploy happens at its very minute.
- **Locks are shared with `php`.** Both containers mount the `app_locks` volume and read the same
  `LOCK_DSN` (`flock:///app/var/lock`), so a command run by hand still refuses to start on top of the
  scheduled one (« Une autre exécution est déjà en cours. »), and the VMID lock of a machine
  creation is the same for the batch screen and for `app:vm-batch:advance`.
- **Running a task by hand** is unchanged: `docker compose -f compose.yaml -f compose.prod.yaml exec
  -T php bin/console <command>` - `--dry-run` where the command has one.
- **`MERCURE_URL` must be set in the worker's environment** (ux-turbo pings Mercure on every flush,
  CLI included), which is why its `environment:` repeats `php`'s. Keep the two in step.

**On the first deploy of the worker, empty the host crontab** of every
`docker compose … exec -T php bin/console app:…` line (`crontab -e` as the user that owns the
deploy directory). Until then each task runs twice. The shared locks keep the two runs from
overlapping, and every task tolerates a second pass, but it is noise, not a design.

## Collecting Courrier pro inbound mail (scheduled)

Students' school mailboxes (`@etu.beaupeyrat.org`) are captured by SES, dropped as raw `.eml`
files into an S3 bucket, and announced on an SQS queue. Nothing is pushed at this application:
`app:mail:consume-inbound` pulls from that queue, so an unreachable server simply means messages
wait (14-day queue retention) rather than being lost.

It runs **every minute, from the schedule** (see « Scheduled tasks » above), and so does
`app:mail:consume-events` for SES's delivery events. The flow is a few dozen mails a day, not a
few a second, so a minute of latency is invisible. Each pass drains the queue and returns; the
command is not a resident consumer of its own, and SQS rather than Messenger carries the retry
semantics (see the command's docblock).

Notes:

- **The command locks itself** (`App\Command\SharedLockableTrait`, on the shared `LOCK_DSN`), so a
  manual run that lands on a scheduled one exits immediately with "Une autre exécution est déjà en
  cours."
- **Failures are meant to stay in the queue.** A message is deleted only after its database write
  succeeds; five failed attempts move it to the dead-letter queue, where a CloudWatch alarm on
  queue depth reports it. Do not "fix" a failing run by purging the queue.
- Requires `AWS_MAIL_*` and `MAIL_STUDENT_DOMAIN` in `.env.prod.local` (see
  `.env.prod.local.example`). Without them the command exits cleanly with a warning, so the schedule
  running it before the credentials exist is harmless.
- **`app:mail:reconcile` is the safety net behind this one**, nightly at 02:30: it lists what SES dropped
  under `incoming/` and replays whatever never reached the database, S3 being the source of truth.
  A pass that had to replay anything **rings the support Discord channel** (it logs at *error*
  level, which is this platform's only alerting threshold) - the messages are recovered either way,
  but the alert is what says the normal path above dropped them. `--since` bounds the scan, seven
  days by default; widen it after an incident, the run costs nothing on objects already stored.

## e-CO and the IGN's Géoplateforme (scheduled)

The e-CO maps (web and mobile) draw the IGN's tiles - Plan IGN, aerial photographs, contour lines,
LiDAR HD relief - straight from `data.geopf.fr`, in the visitor's browser or phone. And
`app:eco:read-terrain` asks the Géoplateforme, from the server, what the statistics read:

- **a parcours' terrain analysis** - legs (climb, steepest slope, share of wood, shortest walk by
  the paths), the safety sheet (nearest road for a vehicle, nearest water per flag), public
  forests, the place name. Asked by « Analyser le terrain » on the parcours screen, by the last flag
  of a parcours being located from the mobile app, or by a race run on a parcours never analysed;
- **the GPS fixes of each closed race** - terrain altitude, on a path or not, in a wood or not -
  oldest race first, which also works through every race closed before this existed.

What to know:

- **No key, no secret, nothing in `.env`.** The services are open (Licence Ouverte Etalab 2.0) and
  the only obligation is naming the IGN wherever what it gave is shown - the maps and the terrain
  card do.
- **The server must reach `https://data.geopf.fr` out to the internet.** Blocked, every reading
  simply stays pending: the command logs a *warning* (not an error: an outage of a public service is
  not ours to page about) and tries again next minute. The screens show the GPS-only figures in
  the meantime, labelled as such.
- **Rate limits are per IP and per service** (5 requests/s for altimetry, 10 for routing, 30 for
  WFS). The client paces itself under them; a 429 would cost five seconds of that service only.
- **A pass is slow on purpose**: a BD TOPO page takes several seconds (ten, for the roads of a square
  kilometre, measured 2026-09-28), so an analysis takes some thirty seconds. It delays the other
  every-minute tasks by that much, once per analysis or race, never continuously.
- The only call made *during a request* is the reading of the ground under a flag just located from
  the mobile app, capped at four seconds - past that the flag is saved unread, and the analysis
  reads it later.
- Running it by hand: `bin/console app:eco:read-terrain` (it locks itself like the others).

## Retention: platform log, console transcripts, jobboard offers and OAuth secrets (scheduled)

`app:purge-platform-activity` deletes the families of rows that nothing else ever removes:

- **`PlatformActivity`, beyond 12 months.** One row per login. Untidy if it grows for ever, and
  nothing worse.
- **`ConsoleSession`, beyond 90 days** - and with it the transcript each one carries, which is up to
  256 KiB of what was on somebody's screen during a session opened on an account with passwordless
  `sudo`.
- **`QuizAttemptEvent`, beyond 12 months** - the mode contrôle's supervision journal, whose duration
  is announced to the student on the entry contract of a supervised évaluation.
- **`JobboardOffer`, beyond 12 months** - an advert published more than a year ago is a job that was
  filled long ago. Not to be confused with *closing* an offer: an offer that left its site keeps its
  row, because how long it stayed online is an information. The date read is the publication date,
  and an offer that never carried one is judged on the day it was first seen.
- **The Claude connector's expired OAuth codes and tokens, and its never-consented clients, 30
  days on.** An expired secret opens nothing; it is kept a month so that a replayed refresh token
  is still recognised - and answered by revoking its connection. Clients are registered by anybody
  on the internet, by specification; the ones somebody actually connected stay, with their grants.

**Volume is not the argument.** A transcript measures a couple of kibibytes in practice, and a year
of them would be a handful of megabytes. The argument is that the journal at
`/infrastructure/console-sessions` prints « Conservation 90 jours » at the top of the screen: if this
command never runs, that line is a promise nothing keeps, and an interface that misstates what it
does is worse than one that keeps less.

Once a day is plenty, off-peak: **03:15, from the schedule** (see « Scheduled tasks » above). It
had never been wired to the crontab; the schedule is what finally runs it.

Notes:

- **Count before the first deploy that schedules it.** `--dry-run` reports what each threshold
  would remove without touching anything, and on a host where this has never run it is worth
  reading once: the bulk of it will be `PlatformActivity` rows nobody has purged since the table was
  created.
  `docker compose -f compose.yaml -f compose.prod.yaml exec -T php bin/console app:purge-platform-activity --dry-run`
- **Every retention is an option**, `--months`, `--console-days` and `--jobboard-months`. The
  schedule runs the defaults, which are the documented ones (`--months` covers both the platform
  log and the supervision journal); a different window is a change to
  `App\Scheduler\PlatformSchedule`, and therefore a deploy.
- **It does not lock itself**, unlike the `app:mail:*` commands. The worker runs one task at a time,
  so two scheduled runs cannot meet; a manual run started during the 03:15 one could, and would
  only delete the same rows twice.
- Deleting is all it does: nothing is written, nothing is announced, and a run on an empty database
  exits in a few milliseconds.

## Portfolio: France compétences' open data (scheduled)

The portfolio's référentiels (design/validated/portfolio.md §8) are read from France compétences'
daily export on data.gouv.fr (Licence Ouverte 2.0, no key). Two tasks, both from the schedule:

- `app:rncp:fetch`, **every minute**, serves the « Récupérer chez France compétences » requests of
  Paramètres > Pédagogique > Référentiels. With nothing requested it does nothing and downloads
  nothing. When there is a request it asks the data.gouv.fr API for the day's
  `export-fiches-rncp-v4-1-*.zip` (about 74 Mo), downloads it once into `var/rncp/` (older copies
  removed) and streams through it. The server must reach `www.data.gouv.fr` and
  `static.data.gouv.fr` over HTTPS.
- `app:rncp:check`, **Mondays at 05:30**, reads each référentiel's fiche again and records whether
  it is still active, its end of registration and any fiche that now replaces it; the Référentiels
  screen shows a change as a banner. A change is logged at *warning* level, not error.

Neither exits non-zero on a network failure: the request shows « En échec » with the reason and
« Relancer », and the watch tries again the next week. On a server that cannot reach data.gouv.fr,
download the export elsewhere and run `bin/console app:rncp:fetch --file=/path/to/export.zip`.

The official E5 template (annexe VI-1) is **not** in the code: the administration uploads the .xlsx
of each session in Référentiels > BTS SIO > Modèles officiels, and nothing exports an .xlsx for a
session without a template in service.

## Opening the Claude connector

The connector (`/mcp`, see CLAUDE.md) lets a teacher act from their own claude.ai account. Three
things decide whether it works, none of which a deploy does by itself:

- **Anthropic's servers must reach the host.** claude.ai calls the connector from its own
  infrastructure, never from the teacher's browser, and so do its OAuth calls. The public site
  already answers from the internet; if a firewall or a reverse proxy filters by source, allow
  `160.79.104.0/21` on `/mcp`, `/oauth/register`, `/oauth/token` and `/.well-known/oauth-*`
  (`/oauth/authorize` is opened by the teacher's own browser, like any screen).
- **`TRUSTED_PROXIES` must make the request say `https`.** The discovery documents announce
  absolute addresses built from the request, and claude.ai compares them character for character
  with the URL the teacher typed. `curl https://<host>/.well-known/oauth-protected-resource/mcp`
  must answer a `resource` of exactly `https://<host>/mcp`.
- **The feature is off for every role.** Gestion > Fonctionnalités, line « Connecteur Claude »: an
  administrator has it without ticking anything, which is how to try it first; then tick
  `ROLE_TEACHER` (and staff, if wanted). Each tool also needs the feature it acts on - the quiz
  library, the séquences, the file library, the carnet de notes.

The PDF reading needs `pdftotext`, which the image carries since poppler-utils was added to the
`Dockerfile`: the first deploy after it rebuilds the image as usual, nothing to install by hand.

To test end to end from the development machine, Claude Code speaks to a local server directly:
`claude mcp add --transport http moncampus https://localhost/mcp`. claude.ai itself cannot reach a
laptop - it needs the production host, or a temporary tunnel.

## Closing the loop on account operations (scheduled)

`app:ldap:apply-account-requests` reads the directory back for every `ldap_manage_account` request
the consumer script on the domain controller has finished with, and draws the consequence on this
side - today, a confirmed rename rewriting `User::$username`.

**It exists so that a closed browser tab is not what decides.** The user's fiche polls the same work
every two seconds while it is open, and that is what makes the screen right immediately; but an
administrator who requests a rename and shuts the laptop must not be the reason the new login never
reaches the application. It is the same lesson as `app:vm-batch:advance`: the browser's own loop is
never what carries the work.

Every minute, from the schedule (see « Scheduled tasks » above), matching the rate the queue is
drained at on the domain controller.

Notes:

- **It locks itself**, same as the `app:mail:*` commands (`SharedLockableTrait`, on the lock volume
  `php` and `worker` share), so a manual run cannot land on top of the scheduled one.
- **It invents nothing.** A directory that cannot be reached leaves the row exactly as it was, with
  a note saying so, and the next minute tries again. `applied_at` is what makes a second pass a
  no-op, which is what lets it cross the fiche's own polling safely.
- **`LDAP_ACCOUNT_STATUS_ATTRIBUTE` decides whether a deactivation can be verified at all**
  (`userAccountControl` on a Samba 4 AD DC). Left blank, deactivations settle at « réussi, non
  vérifié » with the reason spelled out, for ever - which is the honest answer on a directory that
  has no such notion, and the wrong one in production. A rename is verified either way, by looking
  the two uids up.
- **The three scripts and the consumer live on the domain controller**, not here - see the
  `Beaupeyrat-scripts` repository (`samba/ldap/`). A queue that fills while nothing drains it shows
  up as rows stuck at « En attente »; that pile-up is the symptom to recognise.
- Requires nothing else. A run with an empty queue exits in a few milliseconds, so the schedule
  running it before the consumer exists is harmless.

## Closing the campus game's months (scheduled)

`app:game:close-month` closes every **calendar month** that has ended and that a formation has asked
to be ranked, in every formation where the game is running, and pays the month's podium
(design/validated/gamification.md §8).

A month rather than an evaluation period since 2026-08-28: points are credited on the day they are
earned and counted in the month that day falls in, which is a window every calendar already has and
nobody has to set up. A formation ranks only the months it ticked on its own game settings screen -
an unticked month is still played, it is simply never closed and never pays a podium.

**It exists because a closure is not something a browser tab can be trusted with.** It freezes a
whole class's month into `GameMonthScore` - written once and never recomputed - credits 20, 10 and 5
points to the first three, refreshes each student's running total and the level it gives, and grants
the level frames that total has opened. It has to happen whether or not anybody opened a screen that
morning, and it must happen exactly once.

Once a day is the right rate: what it reacts to is the calendar, and the calendar moves once a day.
It runs at 04:30, from the schedule (see « Scheduled tasks » above).

Notes:

- **`MERCURE_URL` must be set in the worker's environment.** `symfony/ux-turbo` pings Mercure on
  *every* flush, CLI included, and this command writes a great deal. The failure shows up at flush
  time rather than at startup, which is what makes it worth stating here rather than discovering it
  on a closure night.
- **It locks itself** (`SharedLockableTrait`), like the `app:mail:*` commands.
- **Idempotent.** A month already frozen is skipped whole - the snapshot is the guard - and every
  write inside a closure is either bounded by it or refused by the ledger's own duplicate check.
  Running it twice a day is harmless; missing a day only delays a closure, and a month left unclosed
  is picked up on the next run - it walks twelve months back.
- **`--dry-run` lists what would be closed and writes nothing**, which is the way to read a host's
  state by hand. `--program` narrows it to one formation.
- **It also attributes the pseudonyms nobody chose within seven days**, in the same pass: it is the
  same question, asked of the same calendar.
- **A run where no formation has switched its game on exits immediately, saying so.** Scheduling it
  before the game is switched on is harmless. The question it asks is « does any formation
  play », deliberately **not** « does any role see the game » (which is what it asked until
  2026-08-28): the role matrix holds no `ROLE_ADMIN` row by construction, so a **silent pilot** - the
  game on for one class, `game` unticked for every managed role, read by the administration alone on
  the Observation screen - answered « éteint pour tous les rôles » and was never closed. And an
  unclosed month is not only an unranked one: collection runs either when a student opens their own
  screen or inside a closure, so the pilot's ledger stayed empty, which is the one thing a pilot must
  not do.

> **Still open:** `app:purge-platform-activity` (two sections up) is scheduled now, but it does not
> touch `GameEntry`. That is one row per credited gesture per student - the same kind of table as
> `PlatformActivity`, and the same retention question, not yet decided.

## Recomputing the stored counters (scheduled)

`app:counters:recompute` checks every **stored counter** of the platform against its source and
corrects the ones that drifted. A stored counter is a value kept on a row rather than summed at
display. Since 2026-09-26:

| `--counter=` | Stored on | Source |
|---|---|---|
| `equipment_stock` | each Gestion > Matériel type: available, in use, on order | the equipment journal |
| `survey_responses` | each survey campaign: targeted, responded | `survey_target` |
| `signup_registrations` | each sign-up list: registrations | `signup_list_registration` |
| `file_library_usage` | each account: what its file library weighs | the live files of `file_library_node` |

**The counters are right without it.** Each one moves in real time, in the same transaction as what
changes it; this pass is the safety net, not the mechanism. That is also why a correction is never
quiet: each drift is logged at *error* level and so reaches Discord - a counter that drifted means a
code path moved a source without moving its counter, and that is a bug to fix, not a figure to patch
every night.

It runs every night at 03:45, from the schedule (see « Scheduled tasks » above).

Notes:

- **`--dry-run` compares and writes nothing**, which is how a suspicion is checked by hand.
  `--counter=equipment_stock` narrows it to one counter; the command lists the names when given an
  unknown one.
- **Harmless to run at any time.** Each counter locks the rows it checks before reading their source,
  so a gesture recorded during the pass is neither lost nor counted twice.
- **The Matériel screens have their own buttons** (« Recalculer les compteurs », « Recalculer ce
  compteur »), which check the equipment counters only. The platform-wide pass is this command.

## Disabling HTTPS

Alternatively, if you don't want to expose an HTTPS server but only an HTTP one,
run the following command:

```console
SERVER_NAME=:80 \
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

(assuming `.env.prod.local` was already created as described above)

## Deploying on Multiple Nodes

If you want to deploy your app on a cluster of machines, you can use [Docker Swarm](https://docs.docker.com/engine/swarm/stack-deploy/),
which is compatible with the provided Compose files.
To deploy on Kubernetes, take a look
at [the Helm chart provided with API Platform](https://api-platform.com/docs/deployment/kubernetes/),
which can be easily adapted for use with Symfony Docker.

## Passing local environment variables to containers

By default, `.env.local` and `.env.*.local` files are excluded from production images (see
`.dockerignore`). `compose.prod.yaml` already points the `php` service's [`env_file` attribute](https://docs.docker.com/compose/how-tos/environment-variables/set-environment-variables/#use-the-env_file-attribute)
at `.env.prod.local` - see the "Deploying" section above for how to create and fill in that file.
