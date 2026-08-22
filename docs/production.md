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

## Collecting Courrier école inbound mail (cron)

Students' school mailboxes (`@etu.beaupeyrat.org`) are captured by SES, dropped as raw `.eml`
files into an S3 bucket, and announced on an SQS queue. Nothing is pushed at this application:
`app:mail:consume-inbound` pulls from that queue, so an unreachable server simply means messages
wait (14-day queue retention) rather than being lost.

Run it **from cron, not as a long-running service**. The flow is a few dozen mails a day, not a
few a second, so a minute of latency is invisible - and in exchange there is no resident process
to supervise, no memory leak to bound and no restart policy to tune. An idle run costs about
three seconds and exits.

On the production host, as the user that owns the deploy directory:

```cron
* * * * * cd /srv/moncampus && docker compose -f compose.yaml -f compose.prod.yaml exec -T php bin/console app:mail:consume-inbound >> /var/log/moncampus-mail.log 2>&1
```

Notes:

- **`exec`, not `run`** - `run` would spin up a throwaway container every minute, which costs far
  more than the process it hosts. `exec` reuses the running `php` container.
- **No `flock` needed in the crontab.** The command locks itself (`LockableTrait`, `LOCK_DSN=flock`),
  so a run that overlaps a slow predecessor exits immediately with "Une autre exécution est déjà en
  cours." Because every run `exec`s into the same `php` container, they share the same lock file.
  Locking inside the command rather than in the crontab also protects manual runs.
- **Failures are meant to stay in the queue.** A message is deleted only after its database write
  succeeds; five failed attempts move it to the dead-letter queue, where a CloudWatch alarm on
  queue depth reports it. Do not "fix" a failing run by purging the queue.
- Requires `AWS_MAIL_*` and `MAIL_STUDENT_DOMAIN` in `.env.prod.local` (see
  `.env.prod.local.example`). Without them the command exits cleanly with a warning, so installing
  the cron entry before the credentials is harmless.

## Retention: platform log and console transcripts (cron)

`app:purge-platform-activity` deletes two families of rows that nothing else ever removes:

- **`PlatformActivity`, beyond 12 months.** One row per login. Untidy if it grows for ever, and
  nothing worse.
- **`ConsoleSession`, beyond 90 days** - and with it the transcript each one carries, which is up to
  256 KiB of what was on somebody's screen during a session opened on an account with passwordless
  `sudo`.

**Volume is not the argument.** A transcript measures a couple of kibibytes in practice, and a year
of them would be a handful of megabytes. The argument is that the journal at
`/infrastructure/console-sessions` prints « Conservation 90 jours » at the top of the screen: if this
command never runs, that line is a promise nothing keeps, and an interface that misstates what it
does is worse than one that keeps less.

Once a day is plenty, off-peak. On the production host, as the user that owns the deploy directory:

```cron
15 3 * * * cd /srv/moncampus && docker compose -f compose.yaml -f compose.prod.yaml exec -T php bin/console app:purge-platform-activity >> /var/log/moncampus-purge.log 2>&1
```

Notes:

- **Count before deleting the first time.** `--dry-run` reports what each threshold would remove
  without touching anything, which on a host where this has never run is worth reading once: the
  bulk of it will be `PlatformActivity` rows nobody has purged since the table was created.
- **Both retentions are options**, `--months` and `--console-days`, so a shorter or longer window is
  a crontab edit rather than a deploy. The defaults are the documented ones.
- **No `flock` needed, and no `LockableTrait` either** - unlike the three `app:mail:*` commands, this
  one does not lock itself. At one run a day two of them cannot meet; that is the only reason it is
  safe, so a schedule tighter than the command's own runtime would need the lock added first.
- Deleting is all it does: nothing is written, nothing is announced, and a run on an empty database
  exits in a few milliseconds. Installing the entry before there is anything to purge is harmless.

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
