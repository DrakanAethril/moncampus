# Proxmox de développement

Il n'y a pas de Proxmox VE dans la pile de dev, et il ne peut pas y en avoir : Proxmox VE est un
hyperviseur bare-metal, pas une image. Ce dont la console a besoin pour être développée n'est pas
un hyperviseur, c'est **un point d'entrée qui parle `/api2/json`** — c'est ce que sert le service
`proxmox-mock` de `compose.override.yaml`.

Il tient dans un fichier Python sans aucune dépendance, sert du TLS avec un certificat auto-signé
régénéré à chaque démarrage, et porte un petit inventaire qui se comporte : les machines démarrent
et s'arrêtent pour de vrai, une action rend un UPID qui reste `running` deux secondes avant de
répondre `OK`, et de mauvais identifiants donnent un 401. **Ce n'est pas un émulateur Proxmox** :
tout ce qui n'est pas implémenté répond 501 en le disant, plutôt qu'un mensonge plausible.

## Déclarer l'hôte dans MonCampus

`/infrastructure/hosts/new`, puis :

| Champ | Valeur |
|---|---|
| Nom affiché | `Proxmox de développement` |
| Adresse | `proxmox-mock` |
| Port | `8006` |
| Type d'identifiant | Jeton d'API |
| Identifiant | `svc-moncampus` |
| Realm | `pve` |
| Nom du jeton | `moncampus` |
| Secret du jeton | `1e5b4d2a-0c37-4f81-9a6e-7d3b2c8f5a10` |
| Pool Proxmox | `moncampus` |
| VMID min / max | `200` / `299` |

Le second jeu d'identifiants, pour l'assistant de création :

| Champ | Valeur |
|---|---|
| Identifiant de provisionnement | `svc-moncampus-provision` |
| Realm | `pve` |
| Nom du jeton | `moncampus` |
| Secret | `9f2c7a41-6b8d-4e05-83af-1c6d9e4b7205` |

En mode « Identifiant et mot de passe » plutôt que jeton, les mots de passe sont
`moncampus-dev` et `moncampus-dev-provision` — c'est le chemin qui exerce le ticket, le cache de
ticket et l'en-tête `CSRFPreventionToken`, qu'un jeton ne touche jamais.

## Le certificat

Les quatre modes TLS sont exerçables :

- **Aucune vérification** — marche tout de suite, et affiche le bandeau rouge permanent.
- **AC du cluster** — le mode recommandé. L'AC est imprimée au démarrage :
  `docker compose logs proxmox-mock | sed -n '/BEGIN CERTIFICATE/,/END CERTIFICATE/p'`, à coller
  dans le champ PEM.
- **Épingle de clé** — le bouton « Lire le certificat présenté » la remplit. Attention : le
  certificat étant **régénéré à chaque démarrage du conteneur**, l'épingle change à chaque
  `docker compose up`. C'est une propriété du bac à sable, pas un bug — et une bonne illustration
  de pourquoi le mode AC est préférable.
- **Autorité publique** — échoue, évidemment, et c'est le seul moyen de voir à quoi ressemble cet
  échec.

## Le VMID 401

`pfsense-lab` (VMID 401) est volontairement hors du pool `moncampus` et hors de la plage 200–299 :
c'est la machine sur laquelle la garde applicative doit refuser d'agir tout en la laissant visible.
Elle porte aussi la même adresse IP que le conteneur `ct-web-01`, pour que l'écran des conflits
d'adresses ait un conflit à montrer.

## Ce qu'il ne fait pas

Aucun `DELETE` n'est implémenté, et le mock **journalise et refuse** celui qu'on lui enverrait. Si
une ligne `REFUSED DELETE` apparaît un jour dans ses logs, c'est qu'une action de suppression a été
ajoutée à l'application — ce qui ne doit pas arriver.

Il n'y a pas non plus de SSH : le lot 4 (comptes invités, post-installation) se vérifie contre une
vraie machine, pas contre ce bac à sable.
