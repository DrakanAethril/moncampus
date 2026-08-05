---
name: beaup-sqs-check
description: Relève manuellement les files SQS du Courrier école en dev (mails entrants et statuts d'envoi SES), puis rend compte de ce qui a été capté. Utiliser quand on veut voir arriver un mail de test envoyé à une adresse @devetu.beaupeyrat.org, ou vérifier qu'une file n'est pas bloquée.
---

# Relever les files SQS du Courrier école (dev)

En développement **rien ne tourne en tâche de fond** : depuis le passage en exécution
périodique, la consommation des files est un cron en production et un geste manuel en local.
Ce skill est ce geste.

## Avant de commencer

Le conteneur doit tourner (`docker compose ps php`) et `.env.dev.local` doit porter les
`AWS_MAIL_*` (voir `.env.dev.local.example`). Sans elles la commande sort proprement sur un
avertissement — ce n'est pas une panne, juste un environnement non configuré.

## 1. Mails entrants

```bash
docker compose exec -T php php -d memory_limit=256M bin/console app:mail:consume-inbound -v
```

La commande vide la file puis sort — elle ne reste pas en écoute. Compter ~3 s.
Le `-v` affiche la clé S3 de chaque message traité, ce qui est exactement ce qu'on veut en test.

Sortie attendue quand quelque chose arrive :

```
  ✓ incoming/abc123...
 [OK] 1 message(s) traité(s), 0 en échec (laissé(s) en file).
```

Silence complet = file vide, et c'est un résultat, pas un échec.

Si la commande répond `Une autre exécution est déjà en cours.`, c'est le verrou
(`LockableTrait`) : une exécution précédente n'a pas fini. Attendre et relancer.

## 2. Statuts d'envoi (file « events »)

```bash
docker compose exec -T php php -d memory_limit=256M bin/console app:mail:consume-events -v
```

Même forme que l'entrante — elle vide la file puis sort, et le silence veut dire file vide.
Chaque événement SES fait avancer le statut d'un envoi (`Delivery` → délivré, `Bounce` → échec) ;
un bounce **permanent** ou une plainte inscrivent en plus l'adresse sur la liste de suppression
locale, après quoi la rédaction refuse d'écrire vers elle. Les événements `Open` sont acquittés
puis jetés : la partie 2 du handoff interdit la détection d'ouverture, quoi que SES publie.

**En dev cette file reste normalement vide, et ce n'est pas un symptôme** : `MAILER_DSN` pointe
sur Mailpit (`smtp://mailer:1025`), donc les envois locaux ne passent jamais par SES, qui n'a
aucun événement à publier. Pour la voir travailler il faut un envoi réellement parti par SES
(staging ou production) avec un Configuration Set attaché — `AWS_SES_CONFIGURATION_SET`, encore
vide à ce jour, est ce qui déclenche la publication des événements.

Un événement portant sur un envoi que la base ne connaît pas encore **n'est pas supprimé** de la
file : la file peut devancer notre propre écriture, et l'acquitter figerait ce mail sur « envoyé »
pour toujours. Il repassera au relevé suivant. C'est ce que compte la ligne « laissé(s) en file ».

## 3. Voir ce qui a été capté

Écran de débogage, **non versionné**, disponible seulement en dev :

    http://localhost/dev/mails

Liste et détail : élève rattaché, corps texte et HTML, en-têtes, verdicts SES, clés S3.

En ligne de commande :

```bash
docker compose exec -T php bin/console dbal:run-sql \
  "SELECT id, created_at, from_address, subject, recipient_local_part, student_id FROM email_message ORDER BY id DESC LIMIT 10" \
  --force-fetch
```

Un `student_id` vide signifie que le mail est bien capté mais qu'**aucun alias ne correspond** à
l'adresse visée : il attend un rattachement manuel. C'est un cas normal, pas une perte — à
distinguer soigneusement d'un mail qui n'est jamais arrivé. Ces mails-là ont désormais leur écran,
réservé aux administrateurs, où on les rattache à un élève ou on les supprime (écran 5a) :

    http://localhost/admin/courrier-ecole/non-rattaches

Un mail rattaché à un élève apparaît directement dans sa boîte Courrier école (`/school-mail`),
avec ses pièces jointes, désormais extraites à la réception.

## Quand rien n'arrive

Remonter la chaîne dans cet ordre, elle a trois maillons :

1. **S3** — un objet est-il apparu sous `incoming/` dans `beaupeyrat-mail-dev` ? Non → le
   problème est en amont (MX, jeu de règles de réception actif, condition de destinataire).
2. **SQS** — la console indique-t-elle des messages dans `mail-inbound-dev` ? Non alors que S3 a
   reçu → la notification d'événement du bucket est en cause.
3. **La commande** — relancer avec `-v`. Une exception s'affiche alors en clair.

Le cas n°2 — S3 a reçu, la file n'a rien vu — se rattrape sans rien perdre, puisque S3 fait foi :

```bash
docker compose exec -T php php -d memory_limit=256M bin/console app:mail:reconcile --since="-7 days" --dry-run
```

La commande liste ce qui est sous `incoming/` et absent de la base ; sans `--dry-run` elle le
rejoue. C'est le filet de dernier recours (§7 du handoff infra), pas le chemin normal : si elle a
du travail, c'est que la chaîne de notification a perdu quelque chose, et ça mérite d'être
regardé plutôt que rejoué tous les jours en silence.

## Quand un message échoue

**Ne pas purger la file.** Un message n'est supprimé qu'après écriture réussie en base ; en cas
d'échec il redevient visible au bout du visibility timeout (5 min) et SQS le bascule en DLQ à la
cinquième tentative. La DLQ est le filet, pas un déchet : c'est là qu'on retrouve le message pour
comprendre, et une alarme CloudWatch la surveille en production.

Les erreurs sont journalisées côté application. Les messages de journal sont en anglais et
préfixés `School mail:` (ils étaient en français jusqu'au 5 août 2026) :

```bash
docker compose exec -T php sh -c "grep 'School mail' var/log/dev.log | tail -20"
```

Un `EnvNotFoundException` déclenché **pendant** le traitement plutôt qu'au démarrage vient
généralement de l'écouteur Doctrine de `symfony/ux-turbo`, qui tire le hub Mercure au premier
flush — voir le mémo correspondant.
