# Portail applicatif générique

Coquille applicative PHP mutualisant authentification, habilitation, référentiel
organisationnel, attributs personnalisés, gestion documentaire et confidentialité
pour un ensemble de plugins métier.

**Stack** : PHP 5.5–7.5, Tabler.io (Bootstrap 5), SQLite (config/référentiel/RBAC
noyau) + MySQL/MariaDB optionnel par plugin, LDAP/Active Directory.

Le détail des choix d'architecture est documenté dans
[`docs/spec-portail-generique.md`](docs/spec-portail-generique.md).

## Décisions d'architecture actées

| Sujet | Décision retenue |
|---|---|
| Granularité RBAC | **Rôle global par plugin** — pas de scope org_unit/site dans `user_roles`. La granularité fine est portée par la confidentialité, pas par le RBAC. |
| Confidentialité "service" | **Héritée** — un membre d'une Direction voit le contenu "service" de tous les Services rattachés à cette Direction (`core/confidentiality.php::userBelongsToOrgUnitScope()`). |
| Suppression d'un attribut personnalisé | **Cascade** — supprimer une `attribute_definitions` supprime ses `attribute_values` (`ON DELETE CASCADE`). |

## Démarrage

```bash
cp config/config.example.php config/config.php
# renseigner ldap / glpi / mail / plugin_db dans config.php

php scripts/install.php     # crée db/portail.sqlite à partir de db/schema.sql
```

Déposer les bibliothèques tierces (Tabler.io, jsTree, Select2, etc.) dans
`shared/vendor/` — voir `shared/vendor/README.md` (environnement air-gapped,
aucun CDN en production).

## Arborescence

```
core/          Services du noyau (auth, rbac, org, attributs, documents,
               confidentialité, plugins, ldap, glpi, mailer, audit)
shared/        Header/footer communs, CSS/JS mutualisés, vendor/ (non versionné)
db/schema.sql  Schéma SQLite du noyau
plugins/
  _template/   Gabarit de départ pour un nouveau plugin (manifest.json, hooks, migrations)
  annuaire-ad/       Annuaire utilisateur AD (Directions, Services, Sites, Référents)
  sites-geo/         Gestion des Sites géographiques (plans 2D / 3D)
  groupes-ad/        Gestion des Groupes AD — pilote le RBAC du noyau
  referents-support/ Référents Support (DGS, Prévention, RH, Informatique, Bâtiments)
uploads/       Documents uploadés (non versionné), servis via download.php
scripts/install.php  Initialisation de la base SQLite
```

Les 4 plugins ci-dessus sont livrés en **stubs fonctionnels** (manifest complet,
page d'entrée minimale, migration SQL de base, résolveur de confidentialité) —
la logique métier détaillée de chacun reste à développer, mais l'intégration
au noyau (auth, RBAC, référentiel, attributs, documents, confidentialité) est
opérationnelle dès l'activation.

## Ajouter un nouveau plugin

1. Copier `plugins/_template/` vers `plugins/mon-plugin/`.
2. Renseigner `manifest.json` (permissions, rôles par défaut, éventuels
   `entity_types` si le plugin a besoin d'attributs/documents personnalisés).
3. Écrire les migrations SQL dans `migrations/` (mode `sqlite_shared` par
   défaut) ou déclarer une connexion dédiée dans `config.php` (`mysql_own`).
4. Activer le plugin depuis l'écran d'administration (`activatePlugin($code)`),
   qui exécute les migrations, enregistre permissions/rôles/types d'entités,
   puis appelle `onActivate()`.

Le plugin ne doit **jamais** dupliquer `users`, `org_units` ou `sites` : il
consomme le référentiel du noyau via `core/org.php`.

## Sécurité

- PHP 5.5 strict : pas de `??`, pas de `match()`, pas de types union.
- Toute sortie utilisateur passe par `h()` (échappement HTML).
- Tout formulaire POST est protégé par `csrfToken()` / `verifyCsrf()`.
- Tout upload passe par `uploadDocument()` (vérification MIME réelle via
  `finfo`, nom de fichier généré, jamais le nom original sur le disque).
- Les documents non publics sont servis exclusivement via `download.php`
  (revérification de `canView()` à chaque téléchargement).
