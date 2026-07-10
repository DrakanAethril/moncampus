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
   isn't available in every region (notably not `eu-west-3`, unlike this app's S3 bucket) - `eu-west-1`
   (Ireland) is `.env.prod.local.example`'s default, but any SES-supported region works as long as
   the domain is verified there.
2. Create a dedicated IAM user for SES, separate from the one behind `AWS_ACCESS_KEY_ID`/
   `AWS_SECRET_ACCESS_KEY` (S3) - scoped to just the `ses:SendEmail` and `ses:SendRawEmail`
   permissions. Fill in its access key/secret as `AWS_SES_ACCESS_KEY_ID`/
   `AWS_SES_SECRET_ACCESS_KEY` in `.env.prod.local`.
3. Set `AWS_SES_REGION` to the region you verified the domain in. All three values are plain,
   unencoded strings - paste them exactly as AWS shows them, no manual encoding needed.
4. A new AWS account's SES starts in the **sandbox**: it can only send to addresses/domains
   you've also individually verified as recipients. Request production access in the SES console
   before sending to real, unverified recipients (e.g. real staff/student addresses).

Once deployed, visit `/system/test-mail` (logged in as an admin, not linked from any menu - see
`App\Controller\SystemTestMailController`) to send a sample email to `tech@beaupeyrat.com` and
confirm the SES configuration actually works end-to-end.

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
