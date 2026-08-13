<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The worked example of "moncampus-sequence/1" offered on step 3 of the séquence import assistant.
 *
 * A teacher who has never seen the format has one question at that step - "what is it supposed to look
 * like?" - and the honest answer is a real document, not a shape. This one is the Ansible kit of
 * design/comparaison/ansible/, transposed by hand, and it is what the acceptance test
 * (tests/Service/SequenceImportAnsibleKitTest.php) runs on.
 *
 * **Abridged on purpose**: two of the kit's four séances, three phases each, its long fields cut. The
 * whole kit is ~30 KB of JSON, which is a document nobody reads and which would make this class the
 * biggest file in src/. What is kept is every *part* of the format - the séquence's nine fields, a
 * séance with its déroulé and its evaluation nature, durations carrying their unit, and a `rapport`
 * with one entry in each of its three lists - so the example demonstrates the format rather than
 * exhausting one document. It imports as it stands.
 *
 * It lives here rather than in tests/Fixtures/ because `tests/` is excluded from the production image:
 * a screen that read the fixture would work in dev and 404 in production. Same reason as
 * App\Service\MixedExampleCatalog, whose examples this mirrors.
 */
final class SequenceExampleCatalog
{
    public static function ansibleKit(): string
    {
        return self::ANSIBLE;
    }

    private const string ANSIBLE = <<<'JSON'
        {
          "format": "moncampus-sequence/1",
          "sequence": {
            "titre": "Automatisation de l'administration avec Ansible",
            "niveau": "BTS SIO 2",
            "option": "SISR",
            "blocs": [
              "Bloc 1",
              "Bloc 2",
              "Bloc 3"
            ],
            "objectifs": "À l'issue de la séquence, l'étudiant est capable de **déployer et maintenir une infrastructure de services Linux multi-machines à l'aide d'Ansible**, en produisant un code d'automatisation idempotent, versionné et documenté.",
            "capacitesAttendues": "| Code | Objectif opérationnel | Séance | Niveau visé |\n|---|---|---|---|\n| O1 | Expliquer le principe agentless et le rôle du nœud de contrôle | S1 | Comprendre |\n| O2 | Préparer un environnement Ansible (installation, clés SSH, ansible.cfg) | S1 | Appliquer |\n| O3 | Rédiger et exploiter un inventaire statique (INI et YAML), groupes et variables | S1 | Appliquer |\n| O4 | Exécuter des commandes ad hoc avec les modules courants | S1 | Appliquer |\n| O5 | Écrire un playbook idempotent (tasks,…",
            "preRequis": "- Administration Debian/Ubuntu en ligne de commande (apt, systemd, droits, utilisateurs).\n- SSH : connexion, configuration cliente, notion de clé publique/privée.\n- Édition de fichiers en console (nano/vim) et notions de scripting Bash.\n- Compréhension d'un fichier structuré (YAML découvert ici, mais JSON/XML déjà vus).\n- Utilisation d'un hyperviseur (VirtualBox, VMware ou Proxmox) pour instancier des VM.",
            "transversalites": "Prépare l'épreuve E5 par la production de documentation technique et de procédures reproductibles, l'épreuve E6 (les playbooks produits constituent une réalisation professionnelle valorisable au portfolio) et le stage de 2ᵉ année, Ansible étant très répandu en entreprise et en ESN.",
            "situationProblematique": "L'entreprise fictive *SISR-Tech* doit déployer un intranet (serveur web + base de données) sur des machines Debian neuves, de manière reproductible pour ses 12 agences. Chaque séance ajoute une brique au même projet.",
            "supportsGeneraux": "Nœud de contrôle : VM Debian 12 « ansible-master » (ou poste Linux/WSL2 de l'étudiant).\nNœuds gérés : VM Debian 12 « srv-web » et « srv-bdd » (1 Go RAM, 1 vCPU suffisent).\nDéploiement du lab : Vagrant (fourni), conteneurs Docker (alternative fournie) ou clonage manuel de VM.\nRéseau : réseau privé hôte 192.168.56.0/24 — master .10, web .11, bdd .12.\nVersions de référence : Debian 12 « bookworm », Ansible ≥ 2.15.\n\nRessources : documentation officielle https://docs.ansible.com, Ansible Galaxy…"
          },
          "seances": [
            {
              "titre": "Prendre la main sur un parc sans agent",
              "duree": "4 h",
              "evaluationNature": "formative",
              "objectifs": "À la fin de la séance, l'étudiant est capable de :\n1. expliquer en quoi Ansible est *agentless* et ce que cela implique côté sécurité et réseau ;\n2. installer Ansible sur un nœud de contrôle et vérifier sa version ;\n3. mettre en place une authentification SSH par clé vers deux nœuds gérés ;\n4. écrire un inventaire statique avec groupes, sous-groupes et variables ;\n5. exécuter des commandes ad hoc…",
              "apresDescription": "Lire la documentation des modules apt, service, copy, user ; rédiger la fiche des 10 commandes ad hoc ; initialiser le dépôt Git du projet.",
              "phases": [
                {
                  "nom": "Accueil et problématisation",
                  "duree": "20 min",
                  "contenu": "Situation déclenchante projetée : « Vous devez installer et configurer le même agent de supervision sur 12 serveurs, ce soir, sans erreur, et prouver demain que c'est fait. »\nRecueil oral des solutions envisagées par les étudiants (script Bash + boucle SSH, clonage…).\nMise en…",
                  "enseignant": "Anime le recueil, fait verbaliser les limites, annonce le fil rouge.",
                  "etudiant": "Propose des solutions, confronte ses idées à celles du groupe."
                },
                {
                  "nom": "Apport théorique et démonstration",
                  "duree": "40 min",
                  "contenu": "Diapositives 1 à 12 : gestion de configuration, agentless vs agent (Puppet/Salt), architecture (nœud de contrôle, inventaire, modules, SSH, Python côté cible), vocabulaire (inventory, play, task, module, idempotence).",
                  "enseignant": "Démonstration commentée de 15 min : `ansible all -m ping`, puis `ansible web -m apt -a \"name=htop state=present\" -b`, relance de la même commande et verbalisation de la différence changed → ok.",
                  "etudiant": "Observe, note, verbalise la différence constatée.",
                  "moyensSupports": "diapo-seance-1.html, vidéoprojecteur, lab en fonctionnement.",
                  "difficultes": "L'idempotence doit être VUE avant d'être définie : ne pas énoncer la définition en premier."
                },
                {
                  "nom": "Atelier guidé",
                  "duree": "1 h 15",
                  "contenu": "TP1 parties A à C du support étudiant :\n- A. Démarrage du lab et vérification (vagrant up ou script de contrôle).\n- B. Installation d'Ansible, génération et diffusion des clés SSH.\n- C. Premier inventaire inventory.ini + ansible.cfg, test `ansible all -m ping`.",
                  "enseignant": "Circule, débloque les démarrages de lab, valide le jalon 1.",
                  "etudiant": "Réalise le TP, fait valider « tous mes hôtes répondent SUCCESS ».",
                  "difficultes": "Le démarrage du lab est le point bloquant le plus fréquent (VT-x désactivé, disque plein, réseau hôte non créé)."
                }
              ]
            },
            {
              "titre": "Playbooks : tâches, variables, templates, handlers",
              "duree": "4 h",
              "evaluationNature": "formative",
              "objectifs": "Écrire un playbook idempotent (tasks, become, handlers), utiliser variables, facts, boucles et conditions, générer un fichier de configuration avec un template Jinja2.",
              "apresDescription": "Terminer le playbook web.yml et son template ; relancer deux fois pour vérifier l'idempotence.",
              "phases": [
                {
                  "nom": "Réactivation",
                  "duree": "15 min",
                  "contenu": "Quiz de réactivation sur l'inventaire et les commandes ad hoc de la S1.",
                  "enseignant": "Anime, régule."
                },
                {
                  "nom": "Apport et démonstration",
                  "duree": "40 min",
                  "contenu": "Structure d'un playbook : hosts, become, tasks, handlers, notify. Variables, facts, boucles, conditions. Templates Jinja2.",
                  "enseignant": "Transmet, démontre."
                },
                {
                  "nom": "Atelier guidé",
                  "duree": "1 h 15",
                  "contenu": "Écriture du playbook web.yml : installation d'Apache et de PHP, gestion du service, copie de fichiers.",
                  "enseignant": "Accompagne, différencie."
                }
              ]
            }
          ],
          "rapport": {
            "deduit": [
              "seances[0].evaluationNature — déduit de la section « Évaluation formative » de la fiche ; la séance porte aussi un diagnostic en ouverture, que le format ne peut pas exprimer.",
              "seances[3].evaluationNature — « sommative » déduit du QCM noté et de la grille de projet."
            ],
            "nonPlace": [
              {
                "titre": "Séance 1 — Livrable et jalon à valider",
                "contenu": "Livrable : inventaire fonctionnel + fiche de 10 commandes ad hoc commentées.\nJalon 1 : « tous mes hôtes répondent SUCCESS » (ansible all -m ping)."
              }
            ],
            "vide": [
              "seances[*].cahierDeTexteDescription — le support ne porte aucune trace destinée aux étudiants.",
              "seances[1..3].avantDescription — les fiches 2 et 3 ne décrivent pas ce qui précède la séance."
            ]
          }
        }
        JSON;
}
