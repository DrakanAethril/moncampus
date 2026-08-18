# Console Proxmox — spécification d'implémentation

**État : conçu, non construit.** Aucune ligne de `src/` n'existe encore.
Conception établie les 17 et 18 août 2026, contre le dépôt à `0ed4d8b3`.

Maquettes (14 écrans en fidélité, aux tokens réels de `assets/styles/app.css`) :
<https://claude.ai/code/artifact/398aa081-64e1-4d56-8114-b766371a4d18>

Ce document est écrit pour être lu **sans autre contexte que `CLAUDE.md`**. Il ne répète pas les
conventions du dépôt (branches, i18n, code en anglais, `cm-*`, fil d'Ariane…) : elles sont dans
`CLAUDE.md` et s'appliquent telles quelles.

---

## 1. Ce que fait la fonctionnalité

Un espace d'administration qui parle à des hôtes **Proxmox VE** par leur API `/api2/json`, pour :

- déclarer des hôtes, chacun avec ses identifiants (secrets chiffrés, jamais réaffichés) ;
- lister les machines (QEMU et LXC), les démarrer, les arrêter proprement, forcer l'arrêt, redémarrer ;
- lister les images : modèles clonables et ISO des stockages ;
- créer une machine, avec **nom d'hôte et IP fixe injectés avant le premier démarrage** ;
- déclarer des **plages d'adresses nommées** et y attribuer automatiquement, en connaissant
  l'occupation **réelle** lue depuis Proxmox ;
- créer les **comptes utilisateurs voulus** dans les machines, via une clé SSH de plateforme ;
- exécuter des **commandes de post-installation** ;
- déployer un **lot** : une machine par étudiant, ou une par groupe avec comptes individuels ;
- journaliser chaque opération.

### Hors périmètre, volontairement

| Exclu | Raison |
|---|---|
| **Supprimer une machine** | L'application arrête, elle ne détruit jamais. La destruction se fait dans Proxmox. Voir §5, décision 3. |
| Console noVNC intégrée | Relais WebSocket authentifié en mode worker : le morceau le plus cher du projet. Voir §14. |
| Sauvegardes, snapshots, migration, HA, SDN | Domaines à part entière. |
| Modifier la config d'une machine existante (CPU/RAM/disque) | Ce serait réimplémenter l'interface Proxmox. |
| Toute écriture dans l'annuaire ou le DNS | Les machines ne rejoignent aucun domaine et n'ont pas d'entrée DNS. Voir §5, décision 6. |
| Un écran étudiant | Tout est `ROLE_ADMIN`. Voir §5, décision 12. |

---

## 2. Prérequis côté infrastructure

**À faire par un humain sur chaque hôte Proxmox avant que quoi que ce soit fonctionne.**

### 2.1 Deux comptes de service, jamais `root@pam`

```bash
# Compte d'OPÉRATION : ni créer, ni détruire, ni exécuter dans l'invité
pveum role add MonCampusOperate -privs "VM.Audit,VM.PowerMgmt,Datastore.Audit"
pveum user add svc-moncampus@pve
pveum acl modify /pool/moncampus -user svc-moncampus@pve -role MonCampusOperate

# Compte de PROVISIONNEMENT : distinct, seul l'assistant de création s'en sert
pveum role add MonCampusProvision -privs \
  "VM.Audit,VM.Allocate,VM.Clone,VM.Config.Disk,VM.Config.CPU,VM.Config.Memory,\
VM.Config.Network,VM.Config.Options,VM.Config.CDROM,Datastore.AllocateSpace,Datastore.Audit"
pveum user add svc-moncampus-provision@pve
pveum acl modify /pool/moncampus -user svc-moncampus-provision@pve -role MonCampusProvision

# Avec un jeton d'API, --privsep 1 est OBLIGATOIRE (sinon il hérite de tous les droits du compte)
pveum user token add svc-moncampus@pve moncampus --privsep 1
pveum acl modify /pool/moncampus -token 'svc-moncampus@pve!moncampus' -role MonCampusOperate
```

**Pourquoi deux comptes** : côté Proxmox il n'existe pas de privilège séparé pour détruire —
`VM.Allocate` autorise à la fois `POST /nodes/{n}/qemu` et `DELETE /nodes/{n}/qemu/{id}`. Un compte
capable de créer est capable de détruire. Séparer les deux fait que le compte utilisé au quotidien
ne peut rien détruire, **pour de vrai** et pas seulement par convention applicative.

Les ACL sont posées sur **`/pool/moncampus`**, jamais sur `/`.

Aucun des deux rôles n'a `VM.Console`, `VM.Monitor`, `VM.Backup`, `VM.Snapshot`, `VM.Migrate`
ni `Sys.*` : ce sont exactement les domaines hors périmètre.

### 2.2 Le certificat

Récupérer l'AC du cluster : `cat /etc/pve/pve-root-ca.pem` — c'est le mode TLS recommandé (`ca`).

### 2.3 Les modèles

**Il n'y a aucun modèle Windows dans le parc.** Les modèles sont Linux avec cloud-init
(`debian12-base`, `debian12-lamp`, `ubuntu2404-base`). Toute machine Windows s'installe depuis une
ISO, donc **sans injection possible** — voir §11.

Chaque modèle QEMU doit avoir un lecteur cloud-init : `ide2: <storage>:cloudinit`.

---

## 3. Variables d'environnement à ajouter

Dans `.env` (avec valeurs vides), documentées comme les autres blocs :

```
###> app/proxmox ###
# Clé de chiffrement des secrets Proxmox : 32 octets, en base64.
# Générer avec : php -r 'echo base64_encode(random_bytes(32)), "\n";'
# DÉDIÉE — surtout pas APP_SECRET, qui doit pouvoir tourner sans rendre les secrets illisibles.
PROXMOX_SECRET_KEY=
###< app/proxmox ###
```

Câbler par `bind` dans `config/services.yaml`, comme `$ldapPasswordAesKey` l'est déjà :

```yaml
bind:
    string $proxmoxSecretKey: '%env(PROXMOX_SECRET_KEY)%'
```

Et un client HTTP nommé, sur le modèle exact de `app.discord_alert_http_client` :

```yaml
app.proxmox_http_client:
    class: Symfony\Contracts\HttpClient\HttpClientInterface
    factory: ['Symfony\Component\HttpClient\HttpClient', 'create']
    arguments:
        $defaultOptions: { timeout: 6, max_duration: 20 }
```

**`timeout` et `max_duration` ne sont pas négociables** : en mode worker FrankenPHP, un hôte éteint
sans `max_duration` immobilise un worker, et assez d'hôtes injoignables bloquent toute l'application.

---

## 4. Ce qui existe déjà dans le dépôt et qu'il faut réutiliser

| Besoin | Ce qui existe |
|---|---|
| CRUD de configuration à onglets | `src/Controller/Settings/SettingsTabTrait.php` — `renderTab()`, `readDataTableParams()`, `findOrNotFound()`, `stampAuditFields()`, `assertValidDeactivateToken()` (CSRF en en-tête `X-CSRF-Token`) |
| Motif new/edit fusionné | `src/Controller/Settings/LessonTypeController.php` — deux `#[Route]` sur une action, `?int $id = null` |
| Entité de configuration | `AuditableTrait`, `creationDate`, `inactiveDate`, constructeur exigeant les champs obligatoires, colonnes `name:` en snake_case, `Assert\*` sur l'entité |
| Appel HTTP sortant | `src/Service/GotenbergClient.php` + `GotenbergUnavailableException` |
| Lecture typée au bord | `App\Service\JsonRequestPayload`, `DataTableParams`, `FormValue`, `QueryValue` |
| Journal | `App\Service\PlatformActivityRecorder::record(PlatformActivityType, ?User, ?Request, array $extraPayload)` |
| Voter | `src/Security/Voter/SignupListVoter.php` — `public const string`, `Vote $vote` en 4ᵉ paramètre (Symfony 8), `match` avec `default => false` |
| Commande | `src/Command/PurgePlatformActivityCommand.php` — `AsCommand` avec description **en français**, `SymfonyStyle`, `--dry-run` |
| Onglets + fil d'Ariane | `templates/_tabs.html.twig`, `templates/_breadcrumb.html.twig`, `templates/settings/configuration.html.twig` |
| Limiteur | `config/packages/rate_limiter.yaml` + `#[Target('...')] RateLimiterFactoryInterface` |
| Verrou | `symfony/lock` — **installé, configuré, jamais utilisé.** Premier consommateur ici. |

**Ce qui n'existe pas** : aucun mécanisme applicatif de chiffrement. Le seul précédent
(`LdapManagePassword`, `AES_ENCRYPT` côté MySQL) est **à ne pas reproduire** — voir §5, décision 1.

---

## 5. Les douze décisions figées

Toutes validées par l'utilisateur, plus ouvertes à discussion.

1. **Chiffrement : `sodium_crypto_secretbox` en PHP**, clé dédiée `PROXMOX_SECRET_KEY`.
   Ne **pas** reprendre `AES_ENCRYPT` de `LdapManagePassword` : le secret transiterait en clair dans
   la requête SQL (donc dans `general_log` et le journal des requêtes lentes) ;
   `AES_ENCRYPT(str, key)` sans IV est **AES-128-ECB** (défaut de `block_encryption_mode` en
   MySQL 8), donc déterministe ; et il n'authentifie pas.
2. **Deux modes d'identifiants** par hôte : mot de passe (ticket) ou jeton d'API. Le jeton est
   recommandé et proposé en premier.
3. **L'application ne supprime jamais de machine.** Pas d'`allowDelete`, pas d'attribut `DESTROY`.
   Garanti côté Proxmox par les deux comptes de service (§2.1).
4. **Deux jeux d'identifiants par hôte** : opération, et provisionnement facultatif.
5. **TLS en quatre modes explicites**, aucun « ne rien vérifier » par défaut.
6. **Aucun lien avec l'annuaire ni le DNS.** Ne pas relier à `LdapComputer` (qui existe pourtant
   avec un `dnsHostName`), ne rien écrire dans les files `ldap_manage_*`.
7. **Périmètre déclaré deux fois** : ACL Proxmox sur un pool, **et** garde applicative
   (`ProxmoxScopeGuard` : pool + plage de VMID + `allow*`).
8. **Adresses occupées découvertes depuis Proxmox**, pas seulement depuis le registre.
9. **Suivi par polling Stimulus** (2 s, plafond 5 min). Messenger n'a **aucun worker** dans ce
   dépôt — router vers `async` = ne jamais traiter.
10. **État des hôtes = dernier test connu, horodaté.** Jamais de sondage à l'affichage.
11. **Post-installation** : champ libre de commandes, exécuté par SSH après les comptes, journalisé.
12. **Aucun lien de menu** ; hub à `/infrastructure` + barre de navigation locale sur tous les
    écrans. Tout est `ROLE_ADMIN`, il n'y a pas d'écran étudiant.

### Les six arbitrages, tranchés dans le même sens

| Point | Décidé |
|---|---|
| Conteneurs LXC | Listés et pilotés au même titre que les VM QEMU |
| Vue par hôte | Chaque hôte a ses écrans ; pas de vue agrégée multi-hôtes |
| Rôle | `ROLE_ADMIN` sur les trois attributs du voter |
| Lien avec `Program` | Borné à deux clés étrangères : quel lot pour quelle classe, quels comptes sur quelle machine |
| Mode TLS `insecure` | Proposé, avec bandeau rouge permanent et mention au journal |
| Modèle Windows | Aucun aujourd'hui ; en fabriquer un est un travail d'infra hors de cette conception |

---

## 6. Modèle de données

### `ProxmoxHost`

| Champ | Type | Note |
|---|---|---|
| `label` | string(120) | Nom affiché |
| `hostname` | string(255) | FQDN ou IP |
| `port` | smallint | 8006 par défaut |
| `realm` | string(32) | `pam`, `pve`, `ldap`… concaténé à l'identifiant |
| `username` | string(120) | Sans le realm |
| `credentialKind` | enum | `password` \| `api_token` |
| `tokenName` | string(64) NULL | Le `!TOKENID`, en clair : ce n'est pas le secret |
| **`secretCipher`** | text | **Chiffré.** Aucun getter ne le déchiffre |
| `secretRotatedAt` | datetime_immutable | Alimente « secret défini le … » |
| `provisionUsername` | string(120) NULL | Second jeu — celui qui porte `VM.Allocate` |
| `provisionRealm` | string(32) NULL | |
| `provisionTokenName` | string(64) NULL | |
| **`provisionSecretCipher`** | text NULL | **Chiffré.** NULL = création indisponible |
| `tlsMode` | enum | `system` \| `ca` \| `pin` \| `insecure` |
| `tlsCaPem` | text NULL | AC du cluster, public, non chiffré |
| `tlsPinSha256` | string(64) NULL | Épingle **de clé publique** (SPKI), base64 |
| `managedPool` | string(64) NULL | Pool hors duquel rien n'est touché |
| `vmidMin` / `vmidMax` | int NULL | Seconde borne du périmètre |
| `allowStart` / `allowStop` / `allowCreate` | bool | Défaut : oui / oui / **non** |
| `maxGuests` / `maxCores` / `maxMemoryMib` / `maxDiskGib` | int NULL | Quotas de création |
| `lastCheckAt` / `lastCheckOk` / `lastCheckMessage` / `pveVersion` | mixte | Dernier test |
| `lastScanAt` | datetime_immutable NULL | Dernier balayage d'adresses |
| `position` | int | Ordre d'affichage |

Plus `AuditableTrait`, `creationDate`, `inactiveDate` (désactivation logique, jamais de `DELETE`).

`__debugInfo()` doit masquer `secretCipher` et `provisionSecretCipher`.

### `ProxmoxOperation`

| Champ | Type | Note |
|---|---|---|
| `host` | ManyToOne, `ON DELETE SET NULL` | |
| `action` | enum | `start` `shutdown` `stop` `reboot` `clone` `create` `provision` `postinstall`. **Pas de `delete`** |
| `node` / `vmid` / `guestName` / `guestType` | snapshot | Copiés au moment de l'acte |
| `status` | enum | `pending` → `running` → `succeeded` \| `failed` \| `unknown` |
| `upid` | string(255) NULL | Identifiant de tâche Proxmox |
| `message` | text NULL | `exitstatus` de Proxmox, ou l'erreur de transport |
| `output` | text NULL | Sortie d'un script de post-installation, tronquée à 64 Kio |
| `exitCode` | smallint NULL | |
| `requestedBy` / `requestedAt` / `settledAt` | User + dates | `ON DELETE SET NULL` sur l'acteur |

**Une ligne est écrite *avant* l'appel, à `pending`** — c'est ce qui fait qu'une opération partie
dans le vide laisse quand même trace de qui l'a demandée.

### `IpRange`

| Champ | Type | Note |
|---|---|---|
| `label` | string(120) | « Réseau SIO2 Étudiant » |
| `host` | ManyToOne ProxmoxHost | La plage n'est proposée que sur cet hôte |
| `cidr` | string(43) | `10.30.20.0/24` |
| `gateway` | string(45) | |
| `bridge` | string(32) | `vmbr0` |
| `vlan` | smallint NULL | NULL = pas de balise |
| `firstUsable` / `lastUsable` | string(45) | **Distincts du CIDR** : les .1 à .49 restent à l'infra |
| `note` | text NULL | |

Plus `AuditableTrait`, `creationDate`, `inactiveDate`.

### `IpAllocation`

| Champ | Type | Note |
|---|---|---|
| `range` | ManyToOne IpRange | |
| `ip` | string(45) | |
| `hostname` | string(64) NULL | |
| `macAddress` | string(17) NULL | `02:4D:43:xx:xx:xx` |
| `vmid` | int NULL | |
| `status` | enum | `reserved` \| `assigned` \| `confirmed` \| `released` |
| `origin` | enum | `declared` \| `discovered` \| `external` |
| `operation` | ManyToOne ProxmoxOperation NULL | |
| `note` | text NULL | Pour une adresse `external` |
| `reservedAt` / `confirmedAt` / `releasedAt` | dates | |

**Unicité de `(range, ip)` sur les lignes vivantes.** C'est la base qui doit la garantir, jamais une
vérification en PHP qui perd la course. MySQL 8 n'ayant pas d'index unique partiel, la technique
retenue est une colonne `live_key` valant l'IP tant que l'allocation vit et `NULL` une fois libérée
(deux `NULL` ne collisionnent pas dans un index unique), avec un index unique sur
`(range_id, live_key)`. **À confirmer par un test d'intégration** — voir §16.

### `GuestAccount`

| Champ | Type | Note |
|---|---|---|
| `host` / `node` / `vmid` | | La machine visée |
| `login` | string(32) | Normalisé Unix : minuscules, sans accent |
| `user` | ManyToOne User NULL | NULL pour un compte fixe (`prof`, `sae`) |
| `sudo` | bool | |
| `shell` | string(64) | `/bin/bash` par défaut |
| `origin` | enum | `member` \| `fixed` \| `manual` |
| `state` | enum | `to_create` \| `present` \| `to_remove` \| `kept` |
| `batch` | ManyToOne VmBatch NULL | |
| `syncedAt` | datetime_immutable NULL | |

**Aucun mot de passe n'est stocké.** Voir §9.3.

### `VmBatch` / `VmBatchItem`

`VmBatch` : `label`, `program` (ManyToOne), `shape` (enum `per_student` \| `per_group_individual` \|
`per_group_shared`), `templateVmid`, `host`, `ipRange`, `cores`, `memoryMib`, `diskGib`,
`linkedClone` (bool), `namePattern`, `postInstallScript` (text NULL), `expiresAt`, `remindedAt`,
`status`, plus `AuditableTrait`.

`VmBatchItem` : `batch`, `vmid` NULL, `guestName`, `ipAllocation`, `node`, `status`
(`planned` \| `creating` \| `created` \| `provisioned` \| `failed`), `message`, `operation`.

---

## 7. Enums (`src/Enum/`)

`ProxmoxCredentialKind`, `ProxmoxTlsMode`, `ProxmoxAction`, `ProxmoxOperationStatus`,
`IpAllocationStatus`, `IpAllocationOrigin`, `GuestAccountOrigin`, `GuestAccountState`,
`VmBatchShape`, `VmBatchItemStatus`.

Motif à suivre : `src/Enum/PlatformActivityType.php` — `enum X: string`, docblock de classe
expliquant la décision, méthode `messageKey()` renvoyant une clé de traduction, `match` exhaustif.

Ajouter à `PlatformActivityType` deux cas : `ProxmoxGuestCreated`, `ProxmoxPostInstallRun`.

---

## 8. Services (`src/Service/`)

### 8.1 `Crypto/`

- **`SecretBox`** — `seal(#[\SensitiveParameter] string $plain): string` /
  `open(string $sealed): string`. XChaCha20-Poly1305 via `sodium_crypto_secretbox`.
  Format `v1.<nonce_b64>.<cipher_b64>`, versionné pour permettre une rotation.
  **Lève à la construction** si la clé est absente ou n'est pas de 32 octets — pas au premier appel.
- `SecretBoxException`
- **`PlatformKeyProvider`** — la paire SSH de la plateforme, privée dans `SecretBox`.

### 8.2 `Proxmox/`

- **`ProxmoxClient`** — une instance par hôte. `ProxmoxClientFactory` la construit et choisit le jeu
  d'identifiants (`operate` ou `provision`).
- **`ProxmoxResponse`** — lecture typée de `{"data": …}`, dans l'esprit de `JsonRequestPayload`.
- DTO readonly : `ProxmoxGuest`, `ProxmoxNode`, `ProxmoxStorageItem`, `ProxmoxTask`.
- **`ProxmoxScopeGuard`** — le périmètre, **sur primitives, pas sur entités** → testable avant d'être
  écrit (convention TDD du dépôt).
- `ProxmoxOperationTracker` — écrit `pending`, résout depuis l'UPID.
- `ProxmoxUnavailableException` — sur le modèle de `GotenbergUnavailableException`.

### 8.3 `Network/`

- **`IpRangeCalculator`** — CIDR → bornes, prochaine libre. Pur. **Premier fichier écrit.**
- **`IpAllocator`** — réserve / attribue / confirme / libère, en transaction.
- **`GuestNetworkConfigurator`** — construit `ipconfig0` (QEMU) ou `net0` + `hostname` (LXC).
- **`GuestAddressReader`** — analyse `ipconfig0` / `net0` d'un invité → IP, MAC, pont, VLAN.
- **`AddressScanner`** — balaie un hôte **en parallèle** : lancer les N `request()` puis consommer
  via `$client->stream()`. Sans ça le balayage est inutilisable (voir §11).
- **`AddressReconciler`** — croise registre / Proxmox / réseau, rend les quatre écarts. Pur.
- `GuestReachabilityProbe` — connexion TCP, délai court, réessais pendant 2 min.

### 8.4 `Guest/`

- **`GuestSshSession`** — phpseclib, clé de plateforme sortie de `SecretBox`.
- **`GuestAccountSyncer`** — applique l'**état voulu** des comptes ; calcule l'écart, rejouable.
- **`PostInstallScript`** — substitution des jetons, garde-fous, troncature de sortie.
- **`PostInstallRunner`** — exécute par SSH, capture `stdout`+`stderr` et le code de retour.
- `PasswordGenerator` — prononçable et robuste : il sera recopié à la main.

### 8.5 `VmBatch/`

- **`VmBatchPlanner`** — résout la liste, les noms, les VMID, les IP. Pur → testé d'abord.
- **`VmBatchExecutor`** — déroule à concurrence plafonnée (2–3), reprend les manquantes.

---

## 9. Points d'implémentation qui ne s'improvisent pas

### 9.1 « Jamais visible en plateforme » — les cinq points

1. L'entité n'expose **aucun** accès au clair : `getSecretCipher()` rend le chiffré, il n'existe pas
   de `getSecret()`. Le seul déchiffrement du dépôt est dans `ProxmoxClientFactory`.
2. Le champ du formulaire est `mapped: false`, jamais prérempli, aide « laissez vide pour conserver ».
3. Aucune route ne le rend — ni l'endpoint DataTables, ni l'API mobile.
4. `__debugInfo()` sur l'entité masque le champ.
5. `#[\SensitiveParameter]` sur les paramètres qui le portent : PHP remplace la valeur par
   `Object(SensitiveParameterValue)` dans les traces, y compris celles que Monolog envoie sur Discord
   en production.

**Si la clé est absente ou mal dimensionnée**, l'écran des hôtes affiche un bandeau
« chiffrement indisponible » — pas une liste vide. Précédent : `app:antivirus:check`, dont le
CLAUDE.md dit que l'état à craindre est celui où un DSN vide désactive la protection en silence.

### 9.2 Cycle de vie d'une allocation

```
Étape 2 de l'assistant → reserved (transaction, première libre selon les TROIS sources)
Création acceptée      → assigned
La machine répond      → confirmed
Création échouée       → released tout de suite, sinon la plage se vide à chaque essai raté
Assistant abandonné    → une reserved de +30 min sans opération aboutie est libérée par le cron
Machine détruite       → l'app ne le fait pas et ne peut pas le savoir. Le BALAYAGE la voit
  depuis Proxmox         disparaître et signale l'adresse comme « orpheline » ; un admin libère.
```

**L'application ne supprimant pas les machines, elle n'est pas dans la boucle de destruction :
le balayage Proxmox est le seul mécanisme qui repère une adresse redevenue libre.** Sans balayage
régulier, une plage ne se vide jamais.

### 9.3 Les mots de passe des comptes invités

Générés, envoyés à la machine, **jamais stockés**. Affichés une seule fois à la fin du déploiement,
sur un écran fait pour être distribué ou exporté. Ce choix n'est tenable que parce que
« Réinitialiser le mot de passe » est un bouton — la clé de plateforme le rend trivial.
L'export est disponible **une seule fois**, avec un avertissement affiché **avant** le déploiement.

### 9.4 La post-installation

Ordre non négociable :

```
Clone → Configuration (nom, IP) → Démarrage → Joignabilité → Comptes → Post-installation
```

Un script qui installe un paquet pour un utilisateur qui n'existe pas encore échoue de façon
incompréhensible.

Jetons substitués : `{{hostname}}` `{{ip}}` `{{vmid}}` `{{users}}` `{{batch}}`.
Un jeton inconnu est **laissé tel quel**, pas remplacé par du vide.

Garde-fous : `stdin` fermé, `DEBIAN_FRONTEND=noninteractive`, délai maximal **5 minutes** par
machine, sortie tronquée à 64 Kio sur une frontière de ligne.

**Une déconnexion propre n'est pas un échec** : un script qui redémarre coupe la session avant que
le code de retour n'arrive. L'état devient `unknown`, la sonde de joignabilité reprend, l'écran
propose de revérifier.

Ce champ est de l'exécution arbitraire en root, mais **ne donne aucun pouvoir qu'un admin n'ait
déjà** (il a la clé de plateforme, l'accès Proxmox et tous les mots de passe qu'il vient de créer).
Il évite vingt-quatre connexions à la main. Le tracer quand même : `ProxmoxOperation` + un cas de
`PlatformActivityType`. Ne **jamais** l'exposer à un rôle plus bas.

### 9.5 Rotation de la clé de plateforme

`app:proxmox:rotate-platform-key` : poser la nouvelle clé, **vérifier**, **puis** retirer l'ancienne.
Jamais l'inverse, sous peine de se fermer la porte au nez. Les machines injoignables sont listées et
gardent l'ancienne jusqu'à leur retour.

---

## 10. Appels Proxmox utilisés

| Usage | Appel |
|---|---|
| Test de connexion | `GET /version` |
| Liste des machines | `GET /cluster/resources?type=vm` — **un seul appel**, tous nœuds, QEMU et LXC |
| Nœuds | `GET /nodes` |
| Stockages | `GET /nodes/{n}/storage` |
| ISO | `GET /nodes/{n}/storage/{s}/content?content=iso` |
| Modèles | les lignes de `/cluster/resources` filtrées sur `template = 1` — zéro appel de plus |
| Marche / arrêt | `POST /nodes/{n}/qemu/{id}/status/{start\|shutdown\|stop\|reboot}` |
| Config d'un invité | `GET /nodes/{n}/qemu/{id}/config` → `ipconfig0`, `net0` |
| Config LXC | `GET /nodes/{n}/lxc/{id}/config` → `net0` |
| Injection QEMU | `PUT /nodes/{n}/qemu/{id}/config` — `name`, `ipconfig0=ip=…/24,gw=…`, `ciuser`, `sshkeys` |
| Création LXC | `POST /nodes/{n}/lxc` — `hostname`, `net0=name=eth0,bridge=…,ip=…/24,gw=…` |
| Clonage | `POST /nodes/{n}/qemu/{id}/clone` — `newid`, `name`, `full`, `storage`, `pool` |
| Création | `POST /nodes/{n}/qemu` — `vmid`, `cores`, `memory`, `scsi0`, `ide2`, `net0`, `pool` |
| Prochain VMID | `GET /cluster/nextid` |
| Suivi | `GET /nodes/{n}/tasks/{upid}/status` → `status`, puis `exitstatus` (`"OK"` ou l'erreur) |
| Pool | `GET /pools/{poolid}` — au test de connexion, pour vérifier que le pool existe |

**Ni `DELETE`, ni `agent/*`.**

Authentification :

```
# Mot de passe
POST /api2/json/access/ticket {username: "svc-moncampus@pve", password: "…"}
  → data.ticket              (cookie PVEAuthCookie)
  → data.CSRFPreventionToken (en-tête obligatoire sur tout non-GET)
# Valide 2 h. Cache 100 min dans un pool dédié, clé = id d'hôte + empreinte du secret
# (changer le secret l'invalide tout seul, sans code de purge).

# Jeton d'API — aucune session, aucun CSRF, aucun cache
Authorization: PVEAPIToken=svc-moncampus@pve!moncampus=<uuid>
```

**Vérifier la forme des charges utiles sur un hôte réel avant d'écrire les `@phpstan-type`**, et ne
déclarer que les clés qu'on lit, toutes optionnelles — convention déjà en place pour S3, SQS,
Gotenberg. Le CLAUDE.md le rappelle à propos de l'import EDT : la forme d'une charge utile externe
ne s'intuite pas.

---

## 11. Pièges vérifiés dans le dépôt ou dans la documentation

| Piège | Détail |
|---|---|
| **`peer_fingerprint`** | `CurlHttpClient` n'accepte **que** `pin-sha256` (hachage de la **clé publique** SPKI) — `vendor/symfony/http-client/CurlHttpClient.php:276`. `NativeHttpClient` fait l'inverse et refuse `pin-sha256` (`:108`). `ext-curl` est chargé dans l'image, donc c'est le client curl. Or l'empreinte SHA-256 que Proxmox affiche est celle du **certificat**. Nommer le champ « épingle de clé publique », jamais « empreinte », et ne pas le faire saisir à la main. Préférer le mode `ca`. |
| **Pas de champ hostname sur une VM QEMU** | Proxmox dérive le `local-hostname` de cloud-init du **nom de la VM**. Un seul champ à l'écran, mais validé aux règles d'un nom d'hôte (`[a-z0-9-]`, 63 car., pas de tiret aux extrémités), pas à celles d'un nom de VM. Sur LXC, `hostname` existe comme paramètre distinct. |
| **`sshkeys` doit être URL-encodé** | Une clé posée telle quelle échoue de façon opaque. |
| **`cipassword` transite en clair** dans l'appel API | Raison de plus de préférer la clé. |
| **`/cluster/resources` ne porte pas l'IP** | Un appel `/config` par invité. Viable seulement en parallèle : `request()` rend la main tout de suite, seule la lecture bloque → lancer les N puis consommer via `$client->stream()`. |
| **Rattacher une machine à une plage demande deux critères** | L'IP dans le CIDR **et** l'interface sur le bon pont avec le bon VLAN. Sinon deux VLAN en `10.30.x` se mélangent. |
| **Clone lié** | `full=0` : quasi instantané, n'occupe que le delta. Mais il **reste sur le nœud et le stockage du modèle** (pas de répartition), et supprimer le modèle dans Proxmox casse tous ses clones. Formuler en conséquences à l'écran (« les 24 resteront sur pve2 »), pas en vocabulaire. |
| **`cicustom` est impraticable** | Déclarer N utilisateurs par cloud-init demanderait un snippet sur un stockage Proxmox, or `/nodes/{n}/storage/{s}/upload` n'accepte pas le contenu `snippets`. D'où le post-provisionnement SSH. |
| **cloud-init ne s'applique qu'au premier boot** | Il ne sait ni ajouter un arrivant, ni retirer un partant, ni propager une clé régénérée. |
| **Aucun modèle Windows** | Toute machine Windows s'installe depuis une ISO, donc sans injection. Le chemin « je réserve l'adresse mais je ne configure pas » n'est pas marginal : c'est la moitié du parc. |
| **`$request->query->getInt('x')` répond 400 sur `""`** | Lire les filtres avec `App\Service\QueryValue`. |
| **`ux-turbo` publie sur Mercure à chaque flush**, CLI compris | `MERCURE_URL` doit être présente dans l'environnement des crons qui écrivent en base. |
| **Un POST géré par Turbo doit rediriger** | Pour « montre-moi un résultat », utiliser `method="GET"`. |
| **CSRF header vs body, et formulaires imbriqués** | Deux classes de bug récurrentes du dépôt : vérifier les deux sur toute action posée dans un formulaire. |
| **Ordre dans `security.yaml`** | `^/infrastructure` → `ROLE_ADMIN` **avant** le fourre-tout `^/` → `ROLE_USER`. Une règle placée après ne s'applique jamais. |

---

## 12. Routes et écrans

Toutes sous `/infrastructure`, toutes `ROLE_ADMIN`, **aucun lien de menu**.

| Route | Écran |
|---|---|
| `/infrastructure` | **Hub** : état des hôtes, compteurs, entrée vers les six domaines |
| `/infrastructure/hosts` | Liste des hôtes (cartes, pastilles d'état) |
| `/infrastructure/hosts/new`, `/{id}/edit` | Formulaire : identification, accès opération, accès provisionnement, TLS, périmètre |
| `/infrastructure/hosts/{id}/guests` | Machines : tableau, filtres, actions de puissance |
| `/infrastructure/hosts/{id}/guests/{node}/{vmid}/accounts` | Comptes d'une machine (écart voulu / constaté) |
| `/infrastructure/hosts/{id}/images` | Modèles et ISO |
| `/infrastructure/hosts/{id}/guests/new` | Assistant de création (3 étapes + post-installation) |
| `/infrastructure/ip-ranges` | Liste des plages, avec les conflits en tête |
| `/infrastructure/ip-ranges/new`, `/{id}/edit` | Formulaire de plage |
| `/infrastructure/ip-ranges/{id}` | Registre : écarts d'abord, allocations ensuite |
| `/infrastructure/batches`, `/new`, `/{id}` | Lots |
| `/infrastructure/operations` | Journal |
| `/infrastructure/operations/{id}/status` | Endpoint de suivi (polling Stimulus) |

**Barre de navigation locale** (`templates/infrastructure/_localnav.html.twig`) sur **tous** les
écrans : Vue d'ensemble · Hôtes · Machines · Images · Adresses · Lots · Journal, plus un « Aller à ».
Règle : **toute fonctionnalité doit être atteignable en partant de `/infrastructure` et en
cliquant.** Aucun écran ne s'atteint uniquement en connaissant son URL.

Fil d'Ariane : conforme à la convention du dépôt, part d'`Accueil` même si Accueil ne renvoie pas ici.

---

## 13. Tests

| Test | Ce qu'il pin |
|---|---|
| `SecretBoxTest` | Aller-retour ; deux scellages du même texte diffèrent (nonce) ; un chiffré altéré d'un octet lève ; clé absente ou mal dimensionnée lève **à la construction** |
| `IpRangeCalculatorTest` | CIDR → bornes, prochaine libre, `/31` et `/32`, plage pleine. **Premier fichier écrit du projet** |
| `IpAllocatorTest` | Deux réservations concurrentes ne rendent jamais la même adresse ; une création échouée libère |
| `ProxmoxScopeGuardTest` | Pool, bornes de VMID, `allow*`, quotas. Sur primitives → écrit **avant** le service |
| `GuestAddressReaderTest` | Vrais `ipconfig0` / `net0` : avec et sans VLAN, IPv6 en second, `dhcp`, champ absent |
| `AddressReconcilerTest` | Les quatre écarts. Plus le cas qui compte : **une réservation en cours ne doit jamais être vue comme orpheline** |
| `GuestNetworkConfiguratorTest` | `ipconfig0` (QEMU) et `net0`+`hostname` (LXC) depuis les mêmes entrées ; un nom refusé par les règles d'hôte ne passe pas |
| `PostInstallScriptTest` | Substitution des cinq jetons, `{{users}}` vide, troncature à 64 Kio sur une frontière de ligne, jeton inconnu laissé tel quel |
| `GuestAccountSyncerTest` | Calcul de l'écart : à créer, à retirer, inchangé, « conservé quand même ». SSH derrière une interface qu'un double remplace |
| `ProxmoxResponseTest` | Charges utiles réelles capturées, champs absents, nombres rendus en chaîne |
| `ProxmoxClientTest` | Sur `MockHttpClient` : l'en-tête CSRF part sur un POST et pas sur un GET ; le jeton se formate bien ; une 401 devient `ProxmoxUnavailableException` |
| `ProxmoxHostVoterTest` | Un par attribut (`VIEW`, `OPERATE`, `PROVISION`) |
| `RoleAccessSmokeTest` | **Étendre la table existante** : étudiant, enseignant, tuteur **et personnel** → 403 sur toutes les routes `/infrastructure` ; seul l'admin → 200. Aucun lien de menu ne menant ici, **ce test est la seule chose qui remarquerait un élargissement accidentel**. |

---

## 14. Ordre d'implémentation

### Lot 1 — Le socle

`SecretBox` + son test, `ProxmoxHost` et ses deux jeux d'identifiants, `ProxmoxClient`,
`ProxmoxClientFactory`, `ProxmoxResponse`, les DTO, le voter, **le hub et la barre locale**,
l'écran des hôtes, le formulaire, le test de connexion, `app:proxmox:check`, la migration.

Résultat : déclarer des hôtes et prouver qu'on leur parle. Aucune action sur aucune machine.
**Tout le risque de sécurité est ici** : à livrer et regarder seul.

### Lot 2 — Voir et piloter

Liste des machines, les quatre actions de puissance, `ProxmoxScopeGuard`, `ProxmoxOperation`,
le suivi Stimulus, le journal, le verrou `symfony/lock`.

### Lot 3 — Créer et adresser

Écran des images, `IpRange` / `IpAllocation` / `IpRangeCalculator` / `IpAllocator`,
les écrans de plages, `AddressScanner` / `AddressReconciler` + `app:proxmox:scan-addresses`,
l'assistant en 3 étapes, l'injection cloud-init / LXC, `GuestReachabilityProbe`, les quotas,
le limiteur.

### Lot 4 — Comptes, post-installation, lots

`PlatformKeyProvider`, `GuestSshSession` (phpseclib — **la seule dépendance à ajouter**, MIT),
`GuestAccountSyncer`, l'écran des comptes, `PostInstallScript` / `PostInstallRunner`,
puis `VmBatch` et son assistant à trois formes, `app:proxmox:expire-batches`,
`app:proxmox:rotate-platform-key`.

**Dans le lot 4, commencer par la clé de plateforme et l'écran des comptes sur une machine
existante** : ça vaut d'être éprouvé seul avant d'y ajouter le déploiement en masse.

À l'intérieur du lot 3, `IpRangeCalculator` et `IpAllocator` s'écrivent en premier : ce sont du
calcul pur, testables avant qu'aucun écran n'existe.

---

## 15. Prompt de reprise

À redonner tel quel dans une session neuve :

> Implémente la console Proxmox de MonCampus en suivant `specs/proxmox-console.md`, qui contient
> toute la conception (modèle de données, services, routes, écrans, appels d'API, pièges vérifiés,
> tests, et le découpage en quatre lots). Les maquettes des 14 écrans sont à
> <https://claude.ai/code/artifact/398aa081-64e1-4d56-8114-b766371a4d18> — récupère-les avec
> WebFetch au moment de construire l'interface.
>
> Enchaîne **les quatre lots dans l'ordre, sans t'arrêter entre eux et sans me demander de
> confirmation**. Ne me sollicite que si tu es réellement bloqué : un secret ou un accès que tu ne
> peux pas obtenir, une décision que la spec ne tranche pas et où deux lectures mènent à des codes
> différents, ou un échec que tu ne sais pas diagnostiquer après avoir essayé. Dans tous les autres
> cas, choisis l'option la plus cohérente avec la spec et le dépôt, note l'hypothèse, et continue.
>
> À la fin de chaque lot : `composer cs-fix`, `composer phpstan` (la baseline est vide, toute erreur
> est à toi), `bin/console lint:twig` et `lint:container`, la migration rejouée depuis une base vide
> puis `doctrine:schema:validate`, et `bin/phpunit`. Corrige jusqu'au vert avant d'attaquer le lot
> suivant. Puis commit et merge sur `staging` comme le veut `CLAUDE.md` — sans demander.
>
> Ne déploie pas en production et ne touche pas à `main`.
>
> Deux choses à ne pas faire, même si elles semblent utiles : ajouter une action de suppression de
> machine (l'application ne détruit jamais, c'est une décision figée), et créer un lien vers
> `/infrastructure` dans le menu de l'application (l'absence de lien est voulue).
>
> Vérifie au navigateur avec le skill `browser-verify` à la fin de chaque lot qui produit un écran.
> Si aucun hôte Proxmox réel n'est joignable depuis le conteneur, dis-le et continue avec
> `MockHttpClient` pour les tests — ne bloque pas là-dessus.

---

## 16. Ce qui reste à vérifier sur un hôte réel

Ces points ne se tranchent pas depuis le dépôt et doivent être confirmés au premier branchement :

1. La forme exacte des charges utiles de chaque appel du §10, avant d'écrire les `@phpstan-type`.
2. La technique d'unicité partielle sur `(range, ip)` en MySQL 8 (§6, `IpAllocation`) : vérifier que
   la colonne `live_key` à `NULL` se comporte comme attendu sous charge concurrente.
3. Que le conteneur PHP atteint les réseaux de VM — condition de `GuestReachabilityProbe`, de
   `GuestSshSession` et donc de tout le lot 4. **Si ce n'est pas le cas, le lot 4 tombe** et il ne
   reste que le compte partagé posé par cloud-init.
4. Que les stockages visés supportent le clone lié (LVM-thin, ZFS, qcow2).
