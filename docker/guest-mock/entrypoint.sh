#!/bin/sh
# Une machine invitée de développement : sshd, sudo, un compte `moncampus` qui accepte la clé de
# plateforme, et *pas* de tmux.
#
# L'absence de tmux est délibérée : c'est ce qui fait passer, pour de vrai, le chemin de réparation
# de la console (App\Service\Console\GuestPty::ensure) — tmux manquant, installé, puis session
# ouverte. Une fois installé, il reste jusqu'au prochain démarrage du conteneur.
set -e

if [ ! -f /var/lib/guest-mock/installed ]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y --no-install-recommends openssh-server sudo procps ca-certificates >/dev/null
    mkdir -p /var/lib/guest-mock && touch /var/lib/guest-mock/installed
fi

id moncampus >/dev/null 2>&1 || useradd -m -s /bin/bash moncampus
printf 'moncampus ALL=(ALL) NOPASSWD:ALL\n' > /etc/sudoers.d/010-moncampus
chmod 440 /etc/sudoers.d/010-moncampus

# La clé publique de plateforme, déposée par le développeur :
#   docker compose exec -T php bin/console dbal:run-sql "SELECT public_key FROM platform_ssh_key" \
#     | ... > docker/guest-mock/authorized_keys.local
mkdir -p /home/moncampus/.ssh
if [ -f /keys/authorized_keys.local ]; then
    cp /keys/authorized_keys.local /home/moncampus/.ssh/authorized_keys
fi
touch /home/moncampus/.ssh/authorized_keys
chown -R moncampus:moncampus /home/moncampus/.ssh
chmod 700 /home/moncampus/.ssh
chmod 600 /home/moncampus/.ssh/authorized_keys

mkdir -p /run/sshd
ssh-keygen -A >/dev/null 2>&1
exec /usr/sbin/sshd -D -e
