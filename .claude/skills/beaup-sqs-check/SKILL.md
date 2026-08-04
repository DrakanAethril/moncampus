---
name: beaup-sqs-check
description: Relève manuellement les files SQS du Courrier école en dev (mails entrants, et statuts d'envoi quand ils existeront), puis rend compte de ce qui a été capté. Utiliser quand on veut voir arriver un mail de test envoyé à une adresse @devetu.beaupeyrat.org, ou vérifier qu'une file n'est pas bloquée.
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
docker compose exec -T php bin/console list app 2>/dev/null | grep -q 'app:mail:consume-events' \
  && docker compose exec -T php php -d memory_limit=256M bin/console app:mail:consume-events -v \
  || echo "app:mail:consume-events n'existe pas encore — file events non implémentée."
```

**Cette commande n'est pas encore écrite** (la file `events` était hors périmètre de la première
tranche : elle alimentera les statuts délivré / échec et la liste de suppression). Le test
ci-dessus la lance dès qu'elle existera, sans avoir à modifier ce skill. Tant qu'elle n'existe
pas, les événements SES **s'accumulent sans se perdre** dans `mail-events-dev` — rétention 14
jours — donc rien n'est urgent, mais rien n'est traité non plus.

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
distinguer soigneusement d'un mail qui n'est jamais arrivé.

## Quand rien n'arrive

Remonter la chaîne dans cet ordre, elle a trois maillons :

1. **S3** — un objet est-il apparu sous `incoming/` dans `beaupeyrat-mail-dev` ? Non → le
   problème est en amont (MX, jeu de règles de réception actif, condition de destinataire).
2. **SQS** — la console indique-t-elle des messages dans `mail-inbound-dev` ? Non alors que S3 a
   reçu → la notification d'événement du bucket est en cause.
3. **La commande** — relancer avec `-v`. Une exception s'affiche alors en clair.

## Quand un message échoue

**Ne pas purger la file.** Un message n'est supprimé qu'après écriture réussie en base ; en cas
d'échec il redevient visible au bout du visibility timeout (5 min) et SQS le bascule en DLQ à la
cinquième tentative. La DLQ est le filet, pas un déchet : c'est là qu'on retrouve le message pour
comprendre, et une alarme CloudWatch la surveille en production.

Les erreurs sont journalisées côté application :

```bash
docker compose exec -T php sh -c "grep 'Courrier école' var/log/dev.log | tail -20"
```

Un `EnvNotFoundException` déclenché **pendant** le traitement plutôt qu'au démarrage vient
généralement de l'écouteur Doctrine de `symfony/ux-turbo`, qui tire le hub Mercure au premier
flush — voir le mémo correspondant.
