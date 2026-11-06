/* SQL pas à pas — the bonus chapter, « Sécurité », open after the final test: transactions, rights, SQL
   injection. Rights cannot run in the game's engine (it has no accounts): that level is read and chosen. */
(function (C) {
  'use strict';
  var TX = { re: 'START\\s+TRANSACTION|\\bBEGIN\\b', label: 'Une transaction est ouverte', missing: 'Ouvre la transaction avec <code>START TRANSACTION</code> : sans elle, chaque requête est validée seule, tout de suite.' };
  var COMMIT = { re: '\\bCOMMIT\\b', label: 'La transaction est validée', missing: 'Termine par <code>COMMIT</code> : c\'est lui qui enregistre les changements.' };

  C.level({
    id: 'N28', chapter: 8, num: 28, title: 'Tout ou rien', summary: 'START TRANSACTION · COMMIT · ROLLBACK', notions: ['transaction'],
    lessons: {
      tx: {
        title: 'Les transactions',
        html: `<p>Certaines modifications vont ensemble : enregistrer le retour d'un prêt <b>et</b> remettre le spécimen dans sa salle. Si la seconde échoue, la base reste à moitié modifiée.</p>
<p>Une <b>transaction</b> regroupe plusieurs requêtes en un tout :</p>
<div class="rule"><span class="kw">START TRANSACTION</span>;<br><span class="kw">UPDATE</span> pret <span class="kw">SET</span> retour = '2026-11-02' <span class="kw">WHERE</span> id = 8;<br><span class="kw">UPDATE</span> specimen <span class="kw">SET</span> salle = 'R2' <span class="kw">WHERE</span> id = 5;<br><span class="kw">COMMIT</span>;</div>
<p><code>COMMIT</code> valide tout d'un coup ; <code>ROLLBACK</code> annule tout. Une transaction jamais validée est annulée à la déconnexion : sans <code>COMMIT</code>, rien n'est enregistré.</p>`,
        example: "START TRANSACTION; UPDATE specimen SET valeur = 0 WHERE id = 1; ROLLBACK; SELECT nom, valeur FROM specimen WHERE id = 1;",
        tryit: 'Remplace ROLLBACK par COMMIT : que vaut le spécimen 1 ?'
      }
    },
    steps: {
      predire: { before: 'tx', kind: 'value', ask: 'Le spécimen 1 vaut 12 000 euros. Que vaut-il après ces trois requêtes ?', query: "START TRANSACTION;\nUPDATE specimen SET valeur = 0 WHERE id = 1;\nROLLBACK;", value: 'SELECT valeur FROM specimen WHERE id = 1', hints: ['ROLLBACK annule tout ce que la transaction a fait.'] },
      comprendre: {},
      completer: { kind: 'complete', ask: 'Dans une transaction, range le Kiwi (29) en R1 et le Panda géant (27) en R2, puis valide.', template: "START TRANSACTION;\nUPDATE specimen SET salle = 'R1' WHERE id = 29;\nUPDATE specimen SET salle = 'R2' WHERE id = 27;\n[[]];", fill: ['COMMIT'], ref: "START TRANSACTION; UPDATE specimen SET salle = 'R1' WHERE id = 29; UPDATE specimen SET salle = 'R2' WHERE id = 27; COMMIT", check: 'modify', tables: ['specimen'], hiddenless: true, commit: true, requires: [TX, COMMIT], hints: ['Le mot qui valide une transaction.', 'COMMIT'] },
      reparer: { kind: 'repair', ask: 'Ces requêtes doivent enregistrer le retour du prêt 8 et ranger le spécimen 5 en R2. Rien n\'est enregistré : répare-les.', starter: "START TRANSACTION;\nUPDATE pret SET retour = '2026-11-02' WHERE id = 8;\nUPDATE specimen SET salle = 'R2' WHERE id = 5;", ref: "START TRANSACTION; UPDATE pret SET retour = '2026-11-02' WHERE id = 8; UPDATE specimen SET salle = 'R2' WHERE id = 5; COMMIT", check: 'modify', tables: ['pret', 'specimen'], hiddenless: true, commit: true, requires: [TX, COMMIT], hints: ['Une transaction jamais validée est annulée.', '… ; COMMIT;'] },
      ecrire1: { kind: 'write', ask: 'Dans une transaction validée, augmente de 5 % la valeur des oiseaux et diminue de 5 % celle des reptiles.', ref: "START TRANSACTION; UPDATE specimen SET valeur = valeur * 1.05 WHERE classe = 'Oiseaux'; UPDATE specimen SET valeur = valeur * 0.95 WHERE classe = 'Reptiles'; COMMIT", check: 'modify', tables: ['specimen'], commit: true, requires: [TX, COMMIT], head: 0, hints: ['START TRANSACTION, deux UPDATE, COMMIT.', "START TRANSACTION; UPDATE specimen SET valeur = valeur * 1.05 WHERE classe = 'Oiseaux'; UPDATE … ; COMMIT;"] },
      ecrire2: { kind: 'write', ask: 'Dans une transaction validée, ajoute l\'institution 7 (Muséum de Grenoble, à Grenoble, en France) et le prêt 10 : le spécimen 14 prêté à cette institution, parti le 2026-11-02, pas encore revenu.', ref: "START TRANSACTION; INSERT INTO institution (id, nom, ville, pays) VALUES (7, 'Muséum de Grenoble', 'Grenoble', 'France'); INSERT INTO pret (id, specimen_id, institution_id, depart, retour) VALUES (10, 14, 7, '2026-11-02', NULL); COMMIT", check: 'modify', tables: ['institution', 'pret'], hiddenless: true, commit: true, requires: [TX, COMMIT], head: 0, hints: ['L\'institution d\'abord : le prêt y fait référence.', 'START TRANSACTION; INSERT INTO institution (…) VALUES (…); INSERT INTO pret (…) VALUES (…); COMMIT;'] },
      ecrire3: { kind: 'write', ask: 'Ouvre une transaction, supprime tous les prêts terminés, puis annule-la : la table pret ne doit pas changer.', ref: 'START TRANSACTION; DELETE FROM pret WHERE retour IS NOT NULL; ROLLBACK', check: 'modify', tables: ['pret'], requires: [TX, { re: '\\bDELETE\\b', label: 'Les prêts terminés sont supprimés', missing: 'Supprime d\'abord les prêts terminés, dans la transaction.' }, { re: '\\bROLLBACK\\b', label: 'La transaction est annulée', missing: 'Termine par <code>ROLLBACK</code>, qui annule tout.' }], head: 0, hints: ['START TRANSACTION, un DELETE, ROLLBACK.', 'START TRANSACTION; DELETE FROM pret WHERE retour IS NOT NULL; ________;'] }
    }
  });

  C.level({
    id: 'N29', chapter: 8, num: 29, title: 'Qui a le droit', summary: 'comptes · GRANT · REVOKE · moindre privilège', notions: ['droits'],
    lessons: {
      grant: {
        title: 'Des comptes et des droits',
        html: `<p>Un serveur MySQL a plusieurs <b>comptes</b>, chacun avec ses <b>droits</b>. Un site web ne se connecte jamais avec le compte de l'administrateur : il a son propre compte, qui ne peut que ce dont il a besoin.</p>
<div class="rule"><span class="kw">CREATE USER</span> 'site'@'localhost' <span class="kw">IDENTIFIED BY</span> '…';<br><span class="kw">GRANT SELECT ON</span> collection.specimen <span class="kw">TO</span> 'site'@'localhost';<br><span class="kw">REVOKE SELECT ON</span> collection.specimen <span class="kw">FROM</span> 'site'@'localhost';</div>
<p><code>GRANT</code> donne un droit (<code>SELECT</code>, <code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>…), sur une table ou une base (<code>collection.*</code>), <code>REVOKE</code> le retire. C'est le principe du <b>moindre privilège</b> : si le compte du site est piraté, le pirate n'a que ses droits.</p>
<p>Le moteur du jeu n'a pas de comptes : ce niveau se lit et se choisit.</p>`
      }
    },
    steps: {
      comprendre: { before: 'grant' },
      site: {},
      revoke: {},
      stagiaire: {}
    }
  });

  C.level({
    id: 'N30', chapter: 8, num: 30, title: 'L\'injection SQL', summary: 'lire une requête piégée · requêtes préparées', notions: ['injection'],
    lessons: {
      injection: {
        title: 'Quand la saisie devient du SQL',
        html: `<p>Un site cherche un spécimen par son nom. Le programmeur a écrit la requête en <b>collant</b> le texte saisi :</p>
<div class="rule">"SELECT * FROM specimen WHERE nom = '" + saisie + "'"</div>
<p>Si quelqu'un saisit <code>x' OR '1'='1</code>, la requête envoyée à MySQL devient :</p>
<div class="rule"><span class="kw">SELECT</span> * <span class="kw">FROM</span> specimen <span class="kw">WHERE</span> nom = 'x' <span class="kw">OR</span> '1'='1';</div>
<p>L'apostrophe de la saisie a fermé le texte, et la suite est lue comme du SQL. C'est une <b>injection SQL</b>.</p>
<p>La parade : les <b>requêtes préparées</b>. La requête est envoyée avec un trou (<code>WHERE nom = ?</code>), et la saisie à part : MySQL la traite toujours comme une valeur, jamais comme du SQL.</p>`,
        example: "SELECT * FROM specimen WHERE nom = 'x' OR '1'='1';",
        tryit: 'Saisis plutôt Kiwi à la place de x\' OR \'1\'=\'1 : combien de lignes ?'
      }
    },
    steps: {
      predire: { before: 'injection', kind: 'value', ask: 'Quelqu\'un saisit x\' OR \'1\'=\'1 dans le champ de recherche. Combien de lignes renvoie la requête obtenue ? La table compte 30 spécimens.', query: "SELECT * FROM specimen WHERE nom = 'x' OR '1'='1';", value: "SELECT COUNT(*) FROM specimen WHERE nom = 'x' OR '1'='1'", hints: ["'1'='1' est vrai pour chaque ligne."] },
      comprendre: {},
      parade: {},
      danger: {}
    }
  });
})(window.SPPContent);
