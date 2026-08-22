# Machine invitée de développement

Il n'y a pas de machine virtuelle dans la pile de dev, et la console des machines a besoin d'une
chose très précise pour être vérifiée : **une porte SSH qui accepte la clé de plateforme, et un
shell derrière**. C'est tout ce que sert ce conteneur — même esprit que `proxmox-mock` pour l'API
Proxmox, ou Mailpit pour SMTP.

Ce n'est **pas** un émulateur de machine virtuelle : il n'a ni cloud-init, ni systemd, ni réseau à
lui. Ce qu'il a est exactement ce que la console traverse : `sshd`, un compte `moncampus` qui a
`sudo` sans mot de passe, et **pas de tmux** — pour que le chemin de réparation
(`App\Service\Console\GuestPty::ensure()` : tmux absent → installé → session ouverte) soit exercé
pour de vrai à la première ouverture.

## Le déposer la clé de plateforme

La clé publique de plateforme vit en base et diffère d'une machine de développement à l'autre. Elle
se dépose dans un fichier gitignoré que le conteneur lit au démarrage :

```console
docker compose exec -T php bin/console dbal:run-sql \
    "SELECT public_key FROM platform_ssh_key WHERE retired_at IS NULL" --force-fetch \
  | grep 'ssh-ed25519' > docker/guest-mock/authorized_keys.local
docker compose restart guest-mock
```

## L'atteindre

Depuis le conteneur `php`, la machine répond sur `guest-mock:22`. Pour qu'une console s'y ouvre, il
faut que MonCampus connaisse une machine **à cette adresse** : déclarez un lot, ou insérez une
allocation d'adresse pointant sur l'IP du conteneur (`docker compose exec guest-mock hostname -I`).

## Lui donner une machine à qui parler

MonCampus n'ouvre une console que sur une machine qu'il connaît : il faut donc un lot, une
allocation d'adresse pointant sur l'IP du conteneur, et un compte invité au nom de la personne qui
ouvrira la console. En dev, cela s'écrit directement en base (`docker compose exec guest-mock
hostname -I` donne l'adresse) — un lot déployé pour de vrai fait la même chose par le chemin normal.

Une fois la ligne `guest_account` en place, la console s'ouvre sur `/console/{id de ce compte}`, à
condition que la personne enseigne dans la formation du lot : c'est la règle de
`App\Security\Voter\GuestConsoleVoter`, et elle n'a pas de dérogation.

## Ce qu'il ne fait pas

Aucun démarrage ni arrêt : c'est Proxmox qui allume les machines, et `proxmox-mock` qui joue ce
rôle-là. Une console ouverte sur cette machine-ci est toujours « en marche ».
