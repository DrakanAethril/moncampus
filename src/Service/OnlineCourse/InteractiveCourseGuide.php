<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

/**
 * What an interactive course written by Claude must be - the `cours_interactif` entry of the
 * connector's format_guide (design/validated/cours-en-ligne.md, §12).
 *
 * **French prompt text, not a comment**: like the heredocs of App\Service\QuizPromptCatalog, it is
 * sent to the model as it is written. Every rule in it is one the platform holds anyway - the page
 * runs in a sandboxed frame on another origin, is opened full page, and is read on a phone - and
 * the guide says so up front, so that the first page Claude writes is one that works there.
 */
final class InteractiveCourseGuide
{
    public const string TEXT = <<<'TXT'
        # Cours interactif — format attendu

        Un cours interactif est **une page HTML entière et autonome**, passée à `course_material_add` (ou `course_material_replace`) dans l'argument `html`, avec `kind: "interactive"`. Elle devient le fichier `index.html` du support.

        ## Où la page s'exécute
        - Dans un cadre isolé, servi depuis un autre domaine que la plateforme : la page n'a accès ni à la session, ni aux cookies, ni à la page qui l'entoure. Ne tente pas de les lire.
        - Le cadre n'autorise pas la navigation de la page principale : pas de `window.top.location`, pas de liens qui remplacent la page (utilise `target="_blank"` pour un lien externe).
        - Le stockage local (`localStorage`) fonctionne : il sert à retenir où le lecteur en est.
        - Le cours est ouvert dans la page du cours, en pleine page et en plein écran : il doit occuper toute la largeur et toute la hauteur disponibles, sans largeur fixe.
        - Il est aussi lu sur téléphone : mise en page fluide, rien qui déborde horizontalement à 360 px de large.

        ## Règles de rédaction
        1. Un seul fichier : CSS dans une balise `<style>`, JavaScript dans une balise `<script>`. Pas de fichier externe, pas de bibliothèque chargée depuis un CDN, pas de police web : le cours doit fonctionner tel quel, aussi dans dix ans.
        2. `<!doctype html>`, `<html lang="fr">`, `<meta charset="utf-8">`, `<meta name="viewport" content="width=device-width, initial-scale=1">` et un `<title>`.
        3. Le contenu est en français, structuré en étapes courtes ; chaque étape fait agir le lecteur (une question, un choix, une manipulation, un exemple à modifier) plutôt que de lui faire lire un long texte.
        4. Les réponses aux exercices sont vérifiées dans la page, avec une explication, sans rien envoyer nulle part : aucune requête réseau.
        5. Lisible au clavier : boutons de vrais `<button>`, focus visible, contrastes suffisants.
        6. 2 Mo au plus. Pas d'image en base64 lourde : préfère un schéma en SVG écrit dans la page.

        ## Pour un cours de plusieurs fichiers
        Un cours de plusieurs fichiers (HTML, CSS, JS, images, polices) est une archive `.zip` contenant un `index.html`, à la racine ou dans un unique dossier. Les polices y sont **incluses** et chargées par un chemin relatif (`@font-face { src: url("fonts/…woff2") }`) : c'est ce qui respecte la règle « pas de police web ». Types de fichiers acceptés dans l'archive : html, css, js, json, svg, images, polices, sons, vidéos, vtt, wasm, txt, pdf.
        - Si l'enseignant a déjà son archive, il la dépose lui-même dans sa bibliothèque de fichiers.
        - Si tu construis l'archive toi-même, envoie-la avec `file_upload_url` (nom, taille exacte, empreinte SHA-256), puis `curl -X PUT --data-binary @cours.zip "<uploadUrl>"`.
        Dans les deux cas, passe ensuite le `fileId` à `course_material_add` avec `kind: "interactive"`.
        TXT;
}
