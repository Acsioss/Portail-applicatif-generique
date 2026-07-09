# Portail applicatif générique — Spécifications d'architecture

**Stack cible** : PHP 5.5–7.5 procédural/POO simple, Tabler.io (Bootstrap 5), SQLite (config portail) + MySQL/MariaDB (données métier lourdes type GLPI), LDAP/Active Directory comme source d'identité.

---

## 1. Analyse macro — que met-on dans le noyau générique ?

Les 4 applicatifs prévus ne sont pas 4 silos : ils consomment tous **le même référentiel organisationnel** et, pour deux d'entre eux, **le même moteur d'habilitation**. Les dupliquer dans chaque plugin créerait 4 sources de vérité divergentes (4 façons de lire "qui est responsable de quel service", 4 caches AD différents). La règle de partition retenue :

> **Va dans le noyau** tout ce qui est *référentiel transverse* ou *service technique* consommé par au moins deux plugins.
> **Reste dans le plugin** tout ce qui est *logique métier spécifique* à un domaine fonctionnel.

| Brique | Noyau générique | Plugin | Justification |
|---|---|---|---|
| Référentiel Directions / Services / Utilisateurs (sync AD) | ✅ | — | Consommé par les 4 plugins prévus |
| Référentiel Sites (identité, adresse, code) | ✅ | — | Consommé par Annuaire, Sites Géo, Groupes AD, Référents Support |
| Plans géo-référencés, extrusion 3D, import 2D | — | ✅ (Gestion des Sites) | Spécifique et lourd, n'intéresse qu'un plugin |
| Fiches "Référents" (qui référent de quoi, par service) | — | ✅ (Annuaire) | Logique métier propre à l'annuaire |
| Moteur RBAC (rôles, permissions, mapping groupe AD → rôle) | ✅ | — | Sert à l'habilitation du portail lui-même ET est administré via le plugin Groupes AD |
| UI d'administration des groupes AD (Missions, Confidentialité) | — | ✅ (Gestion des Groupes AD) | Interface métier au-dessus du moteur RBAC du noyau |
| Connecteur GLPI (auth OAuth2, wrapper requêtes) | ✅ | — | Réutilisé par AlertMail, l'import d'assets, et par Référents Support (liens Formcreator) |
| Matrice de contacts par domaine (DGS/Prévention/RH/Info/Bâtiments) | — | ✅ (Référents Support) | Logique métier propre |
| Moteur d'envoi mail consolidé (groupage par manager) | ✅ | — | Déjà standardisé dans vos patterns AlertMail, réutilisable partout |
| Journal d'audit / logs d'activité | ✅ | — | Transverse par nature |
| Notifications intra-portail | ✅ | — | Transverse |

**Conséquence concrète** : le plugin "Gestion des Groupes AD" n'implémente pas son propre système de permissions — il **pilote** celui du noyau. C'est l'interface qui permet à l'admin de dire "le groupe AD `GG-Informatique-Support` = rôle `plugin.referents_support.editor`".

---

## 2. Arborescence du portail

```
/portail
├── index.php                  # Page d'accueil (cartes plugins)
├── login.php / logout.php
├── config/
│   ├── config.php              # Connexions LDAP, chemins, secrets
│   └── plugins.registry.json   # Cache généré : liste des plugins installés
├── core/
│   ├── db.php                  # Connexion SQLite (config) + factory PDO plugins (MySQL)
│   ├── auth.php                # Session, requireLogin(), requireRole()
│   ├── ldap.php                # Bind, sync, résolution groupes/managers
│   ├── rbac.php                # can($user, $permission), assignRole(), mapAdGroup()
│   ├── plugins.php             # Découverte, activation, hooks
│   ├── org.php                 # Accès au référentiel Direction/Service/Site
│   ├── glpi_connector.php      # Wrapper API GLPI réutilisable
│   ├── mailer.php              # sendMail(), sendConsolidatedMails()
│   ├── csrf.php / helpers.php
│   └── audit.php                # logAction()
├── shared/
│   ├── css/portail.css          # Surcouche Tabler (thème, cartes plugins)
│   ├── js/portail.js            # Menu, notifications, AJAX générique
│   └── vendor/                  # jsTree, Select2, List.js (mutualisés)
├── db/
│   └── portail.sqlite           # Config, RBAC, référentiel, audit
├── plugins/
│   ├── annuaire-ad/
│   │   ├── manifest.json
│   │   ├── assets/{icon.svg, logo.png, illustration.jpg}
│   │   ├── public/index.php
│   │   ├── includes/
│   │   └── migrations/001_init.sql
│   ├── sites-geo/
│   ├── groupes-ad/
│   └── referents-support/
└── uploads/
```

---

## 3. Le noyau générique — spécification détaillée

### 3.1 Authentification

- Bind LDAP prioritaire (`authenticateLdap()` — pattern déjà standardisé) avec fallback compte technique local stocké dans SQLite (`password_hash`) pour comptes de service.
- Session PHP native (`session_regenerate_id(true)` à la connexion), pas de JWT.
- À la connexion : synchronisation légère du user (nom, mail, DN, appartenance aux groupes AD pertinents) dans la table `users` + calcul des rôles effectifs via `rbac.php`.
- SSO/Kerberos (NTLM) : à prévoir en V2 comme option de `auth.php`, sans changer le contrat des plugins (ils appellent toujours `requireLogin()`).

### 3.2 Habilitation (RBAC)

Modèle à deux niveaux :

1. **Rôles applicatifs** déclarés par le noyau ou par les plugins (`plugin.{code}.{role}`, ex. `plugin.referents_support.editor`).
2. **Attribution** soit directe à un utilisateur, soit — cas majoritaire attendu ici — via **mapping groupe AD → rôle**, administré par le plugin "Gestion des Groupes AD".

```sql
-- portail.sqlite
CREATE TABLE roles (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,       -- 'core.admin', 'plugin.annuaire_ad.reader'
    label TEXT NOT NULL,
    plugin_code TEXT,                -- NULL si rôle noyau
    is_system INTEGER DEFAULT 0
);

CREATE TABLE permissions (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,       -- 'annuaire_ad.export', 'sites_geo.edit_plan'
    label TEXT NOT NULL,
    plugin_code TEXT
);

CREATE TABLE role_permissions (
    role_id INTEGER REFERENCES roles(id),
    permission_id INTEGER REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id INTEGER REFERENCES users(id),
    role_id INTEGER REFERENCES roles(id),
    source TEXT DEFAULT 'direct',    -- 'direct' | 'ad_group'
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE ad_group_role_mapping (
    id INTEGER PRIMARY KEY,
    ad_group_dn TEXT NOT NULL,
    role_id INTEGER REFERENCES roles(id),
    UNIQUE(ad_group_dn, role_id)
);
```

API noyau exposée aux plugins :

```php
can($user_id, 'annuaire_ad.export');        // bool
requireRole('plugin.sites_geo.admin');       // die 403 sinon
getUserRoles($user_id);
syncUserRolesFromAdGroups($user_id, $ldap_groups); // appelé au login
```

### 3.3 Référentiel organisationnel partagé

Table centrale alimentée par synchronisation AD (cron + à la demande), lue par tous les plugins — jamais dupliquée.

```sql
CREATE TABLE org_units (
    id INTEGER PRIMARY KEY,
    type TEXT NOT NULL,              -- 'direction' | 'service'
    parent_id INTEGER REFERENCES org_units(id),
    code TEXT,
    name TEXT NOT NULL,
    responsable_user_id INTEGER REFERENCES users(id),
    site_id INTEGER REFERENCES sites(id)
);

CREATE TABLE sites (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE,
    name TEXT NOT NULL,
    address TEXT,
    lat REAL,
    lng REAL,
    parent_site_id INTEGER REFERENCES sites(id)   -- ex : bâtiment rattaché à un site
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    login TEXT UNIQUE NOT NULL,
    ldap_dn TEXT,
    display_name TEXT,
    email TEXT,
    org_unit_id INTEGER REFERENCES org_units(id),
    source TEXT DEFAULT 'ldap',      -- 'ldap' | 'local'
    active INTEGER DEFAULT 1,
    last_login TEXT
);
```

Le plugin **Gestion des Sites géographiques** ne recrée pas `sites` : il ajoute une table `sites_geo_extended (site_id, floor_plan_svg, building_extrusion_json, osm_bounds, ...)` en clé étrangère vers `sites.id`.

Le plugin **Annuaire AD** ne recrée pas `org_units`/`users` : il ajoute une table `annuaire_referents (org_unit_id, user_id, domaine)` pour la notion de "référent" par service.

### 3.4 Connecteur GLPI mutualisé

Wrapper unique (`core/glpi_connector.php`) reprenant vos fonctions `glpiGetSessionToken()` / `glpiRequest()`, avec cache du token en session. Réutilisé par le plugin Référents Support pour créer des tickets/formulaires Formcreator sans que chaque plugin ne réimplémente l'auth OAuth2.

### 3.5 Page d'accueil (cartes)

Générée dynamiquement depuis `plugins.registry.json` (produit par `core/plugins.php` en scannant `/plugins/*/manifest.json`), filtrée par habilitation (`can($user, "plugin.{code}.access")`). Chaque carte : illustration, icône, nom, description courte, bouton "Ouvrir" → `entry_point`. Liens dépôt/éditeur/documentation affichés uniquement si `can($user, 'core.admin')`.

### 3.6 Journal d'audit et notifications

```sql
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    plugin_code TEXT,
    action TEXT,
    target TEXT,
    ip TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notifications (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    title TEXT,
    message TEXT,
    link TEXT,
    is_read INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

`logAction($plugin_code, $action, $target)` est appelable par tout plugin — traçabilité transverse sans effort d'implémentation côté plugin.

---

## 4. Contrat d'intégration d'un plugin

Pour qu'un futur plugin s'intègre "à chaud" sans retouche du noyau, il doit respecter ce contrat.

### 4.1 `manifest.json` (obligatoire, à la racine du plugin)

```json
{
  "id": "annuaire-ad",
  "name": "Annuaire utilisateur AD",
  "description": "Organisation, Directions, Services, Responsables, Sites, Référents, Membres",
  "version": "1.0.0",
  "min_core_version": "1.0.0",
  "category": "annuaire",
  "icon": "assets/icon.svg",
  "logo": "assets/logo.png",
  "illustration": "assets/illustration.jpg",
  "repo_url": "https://git.interne/portail/annuaire-ad",
  "editor_url": "https://interne/wiki/annuaire-ad",
  "doc_url": "https://interne/docs/annuaire-ad",
  "entry_point": "public/index.php",
  "permissions": [
    { "code": "annuaire_ad.access",  "label": "Accès à l'annuaire" },
    { "code": "annuaire_ad.export",  "label": "Export CSV annuaire" },
    { "code": "annuaire_ad.admin",   "label": "Administration référents" }
  ],
  "default_role_permissions": {
    "plugin.annuaire_ad.reader": ["annuaire_ad.access"],
    "plugin.annuaire_ad.admin":  ["annuaire_ad.access", "annuaire_ad.export", "annuaire_ad.admin"]
  },
  "menu_entries": [
    { "label": "Annuaire", "route": "public/index.php", "permission": "annuaire_ad.access" }
  ],
  "dashboard_widgets": ["includes/widget_effectifs.php"],
  "hooks": {
    "onActivate": "includes/hooks.php::onActivate",
    "onUserSync": "includes/hooks.php::onUserSync",
    "onCron": "includes/hooks.php::onCron"
  },
  "db": {
    "mode": "sqlite_shared",
    "migrations_dir": "migrations"
  },
  "status": "active"
}
```

### 4.2 Cycle de vie

1. **Découverte** : `core/plugins.php` scanne `/plugins/*/manifest.json`, valide le schéma et `min_core_version`.
2. **Activation** (admin, écran Paramétrage) : exécution des scripts SQL de `migrations/`, insertion des permissions/rôles par défaut, appel `onActivate()`.
3. **Fonctionnement** : le plugin apparaît sur la page d'accueil et dans le menu pour les utilisateurs habilités.
4. **Désactivation** : masquage (menu + accueil), pas de suppression de données — `entry_point` refuse l'accès si `status != active`.

### 4.3 Isolation des données

Deux modes déclarés dans `manifest.db.mode` :

- `sqlite_shared` : tables préfixées `plg_{code}_` dans `portail.sqlite`, pour les plugins légers (config, petites listes).
- `mysql_own` : connexion dédiée (paramètres dans `config/config.php`, jamais en dur dans le plugin) via `core/db.php::pluginPdo($code)` — pour les données volumineuses (ex. plans géo-référencés, historiques).

Dans tous les cas : **jamais** de table dupliquant `users`, `org_units` ou `sites` — clé étrangère logique vers l'ID du noyau.

### 4.4 Accès au référentiel et à l'auth (API imposée)

```php
require_once __DIR__ . '/../../../core/auth.php';
requireLogin();
requireRole('plugin.annuaire_ad.access');   // ou can(...) pour un contrôle fin

$user = currentUser();
$services = getOrgUnits(['type' => 'service']);   // core/org.php
$site = getSite($site_id);                        // core/org.php
```

Le plugin n'a **jamais** à ouvrir sa propre connexion LDAP pour lire l'organigramme : il consomme le cache synchronisé du noyau. Il peut en revanche appeler `ldapSearch()` du noyau pour des besoins ponctuels non couverts par le cache.

### 4.5 Assets partagés

```php
<?php include CORE_PATH . '/shared/header.php'; ?>   <!-- Tabler + logo + profil + menu -->
```

`shared/header.php` charge Tabler.io (CDN version fixe), `shared/css/portail.css`, `shared/js/portail.js`. Le plugin ajoute ses propres assets via le manifest :

```json
"assets": {
  "css": ["assets/annuaire.css"],
  "js": ["assets/annuaire.js"]
}
```

chargés **après** les assets du noyau (permet la surcharge locale sans conflit).

### 4.6 Hooks disponibles

| Hook | Déclenchement | Usage typique |
|---|---|---|
| `onActivate()` | Activation admin | Créer tables, rôles par défaut |
| `onDeactivate()` | Désactivation admin | Nettoyage optionnel |
| `onUserSync($user, $ldap_groups)` | À chaque login | Recalcul de données propres au plugin dépendant du profil AD |
| `onMenuBuild($user)` | Construction du menu | Ajout d'entrées conditionnelles avancées (au-delà de `menu_entries` statique) |
| `onDashboardCards($user)` | Page d'accueil | Widget personnalisé sur la carte (ex. compteur d'effectifs) |
| `onCron()` | Tâche planifiée noyau | Sync périodique, purge, notifications |

### 4.7 Conventions de code imposées

- PHP 5.5–7.5 strict (pas de `match()`, pas de nullsafe `?->`, pas de types union) — cohérent avec votre existant.
- Sécurité obligatoire : `htmlspecialchars()` (fonction `h()` du noyau), PDO paramétré, `csrfToken()`/`verifyCsrf()` du noyau sur tout formulaire POST.
- Sortie AJAX au format JSON standard `{ success, data|error }`.
- Toute action sensible doit appeler `logAction()`.

---

## 5. Application des principes aux 4 plugins prévus

| Plugin | S'appuie sur le noyau pour | Apporte en propre |
|---|---|---|
| **Annuaire utilisateur AD** | `org_units`, `sites`, `users`, RBAC | Table `referents(org_unit_id, user_id, domaine)`, vues/recherches, export CSV |
| **Gestion des Sites géo** | `sites` (identité/adresse) | `sites_geo_extended` (plans 2D, extrusion 3D, rendu OSM géoréférencé) |
| **Gestion des Groupes AD** | Moteur RBAC (`roles`, `ad_group_role_mapping`) | UI d'admin du mapping, attributs Mission/Confidentialité, historique des changements de groupe |
| **Référents Support** | `org_units`, `sites`, `users`, connecteur GLPI, mailer consolidé | Matrice de contacts par domaine (DGS/Prévention/RH/Info/Bâtiments), génération de liens Formcreator |

Cette répartition garantit qu'une modification de l'organigramme (ex. fusion de deux services) se propage automatiquement aux 4 plugins sans script de resynchronisation par application.

---

## 6. Système d'attributs personnalisés (extensibilité EAV)

### 6.1 Principe : modèle hybride

Deux besoins différents coexistent :

- Les champs **structurants**, connus au moment du développement (ex. `sites.address`, `org_units.name`) → restent des colonnes SQL classiques, typées, indexables.
- Les champs **ajoutés a posteriori par l'admin sans toucher au code** (ex. "Numéro de téléphone du référent Prévention", "Superficie du site", "Niveau de criticité") → passent par un moteur d'attributs génériques (EAV — Entity/Attribute/Value), CRUDable depuis l'écran de paramétrage.

Tout objet du portail (noyau ou plugin) peut recevoir des attributs personnalisés, à condition d'être déclaré comme **type d'entité** — étape obligatoire à l'activation du plugin.

```sql
CREATE TABLE entity_types (
    code TEXT PRIMARY KEY,          -- 'core.org_unit', 'core.site', 'core.user',
                                     -- 'plugin.sites_geo.building', 'plugin.referents_support.contact'
    label TEXT NOT NULL,
    plugin_code TEXT,               -- NULL si type noyau
    org_scope_resolver TEXT         -- callable "plugin_code:function" retournant l'org_unit_id
                                     -- propriétaire de l'entité (nécessaire pour la confidentialité "service")
);
```

### 6.2 Modèle de données des attributs

```sql
CREATE TABLE attribute_definitions (
    id INTEGER PRIMARY KEY,
    entity_type TEXT NOT NULL REFERENCES entity_types(code),
    code TEXT NOT NULL,                 -- identifiant technique (unique par entity_type)
    label TEXT NOT NULL,
    data_type TEXT NOT NULL,            -- text|textarea|number|date|boolean|select|multiselect|user_ref|org_unit_ref|site_ref
    options_json TEXT,                  -- pour select/multiselect : [{"value":"...","label":"..."}]
    is_required INTEGER DEFAULT 0,
    is_multiple INTEGER DEFAULT 0,
    default_confidentiality TEXT DEFAULT 'service',   -- 'public' | 'service' | 'private'
    sort_order INTEGER DEFAULT 0,
    plugin_code TEXT,                   -- NULL si créé par un admin en runtime, sinon plugin d'origine
    created_by INTEGER REFERENCES users(id),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entity_type, code)
);

CREATE TABLE attribute_values (
    id INTEGER PRIMARY KEY,
    definition_id INTEGER NOT NULL REFERENCES attribute_definitions(id),
    entity_id INTEGER NOT NULL,         -- id de l'objet dans sa table propre
    value_text TEXT,
    value_number REAL,
    value_date TEXT,
    value_boolean INTEGER,
    confidentiality_override TEXT,      -- NULL = hérite de default_confidentiality
    updated_by INTEGER REFERENCES users(id),
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_attrval_def_entity ON attribute_values(definition_id, entity_id);
```

Le typage en colonnes séparées (`value_text/number/date/boolean`) plutôt qu'un `value` unique permet le tri et le filtrage côté SQL (utile pour des recherches/exports dans l'Annuaire par exemple), au prix d'une valeur = une ligne (`is_multiple` gère les valeurs répétées).

### 6.3 API noyau (`core/attributes.php`)

```php
// Déclaration (appelée par un plugin à l'activation, ou par l'admin en CRUD runtime)
registerEntityType($code, $label, $plugin_code = null, $org_scope_resolver = null);
createAttributeDefinition($entity_type, $data);   // CRUD admin
updateAttributeDefinition($id, $data);
deleteAttributeDefinition($id);                   // bloqué si des valeurs existent, sauf confirmation explicite

// Lecture/écriture, filtrées par confidentialité pour l'utilisateur courant
getEntityAttributes($entity_type, $entity_id, $user);
setEntityAttribute($entity_type, $entity_id, $code, $value, $confidentiality = null);
```

### 6.4 Écran d'administration (CRUD attributs)

Dans Paramétrage → "Attributs personnalisés" : sélection d'un type d'entité (liste alimentée par `entity_types`), puis tableau List.js des attributs existants avec ajout/édition via modal Tabler :

- Libellé, code technique (auto-slug), type de donnée (Select2), options si `select`/`multiselect` (liste dynamique), confidentialité par défaut (public/service/privé), obligatoire (oui/non), multivalué (oui/non).
- Les définitions créées par un plugin (`plugin_code` renseigné) restent modifiables niveau libellé/confidentialité mais pas supprimables depuis l'UI (protégées par le plugin).

### 6.5 Exemples d'application aux plugins prévus

| Entité | Attributs EAV typiques |
|---|---|
| `core.site` | Superficie, type de bâtiment, niveau de criticité |
| `core.org_unit` | Effectif cible, code budgétaire |
| `plugin.groupes_ad.group` | Mission, niveau de confidentialité par défaut du groupe |
| `plugin.referents_support.contact` | Domaine (DGS/Prévention/RH/Info/Bâtiments), disponibilité, ligne directe |

---

## 7. Gestion documentaire (documents et images liés)

### 7.1 Modèle de données

```sql
CREATE TABLE documents (
    id INTEGER PRIMARY KEY,
    entity_type TEXT NOT NULL REFERENCES entity_types(code),
    entity_id INTEGER NOT NULL,
    plugin_code TEXT,
    category TEXT,                      -- 'photo','plan','justificatif','illustration','autre'
    filename_original TEXT NOT NULL,
    filename_stored TEXT NOT NULL,      -- chemin relatif sous /uploads/{entity_type}/{entity_id}/
    mime_type TEXT,
    size_bytes INTEGER,
    confidentiality TEXT DEFAULT 'service',
    uploaded_by INTEGER REFERENCES users(id),
    uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,
    checksum TEXT
);
CREATE INDEX idx_doc_entity ON documents(entity_type, entity_id);
```

Volontairement pas de table de versioning en V1 (un upload remplace ou s'ajoute selon `category`) — extension possible via `document_versions(document_id, ...)` si le besoin apparaît (ex. historique des plans de site).

### 7.2 API noyau (`core/documents.php`) et composant réutilisable

```php
uploadDocument($entity_type, $entity_id, $_FILES['file'], $category, $confidentiality, $allowed_mimes = null);
// réutilise handleUpload() déjà standardisé (vérif taille + MIME réel via finfo), y ajoute l'écriture en base

getEntityDocuments($entity_type, $entity_id, $user);   // filtré par canView()
deleteDocument($id, $user);                             // vérifie droit avant suppression physique + DB
```

Composant d'affichage mutualisé, appelable depuis n'importe quel plugin :

```php
<?php renderDocumentManager($entity_type, $entity_id, $current_user); ?>
```

Rend une carte Tabler avec zone Dropzone (upload, si l'utilisateur a le droit d'édition sur l'entité) et liste des documents existants groupés par catégorie, badge de confidentialité coloré, lien de téléchargement conditionné à `canView()`.

### 7.3 Stockage et sécurité

- Racine `/uploads/{entity_type}/{entity_id}/{uuid}_{nom_original}`, hors `webroot` si possible, servi par un script `download.php?id=` qui revérifie `canView()` à chaque téléchargement (pas de lien direct statique pour les documents `service`/`privé`).
- Reprise stricte du pattern `handleUpload()` existant : vérification MIME réelle (`finfo`), taille max configurable par catégorie, nom de fichier généré (`uniqid()`), jamais le nom original tel quel sur le disque.
- Les documents `public` (ex. illustrations de plugin, logos) peuvent être servis statiquement.

### 7.4 Application aux plugins prévus

| Plugin | Documents typiques |
|---|---|
| Annuaire AD | Photo de profil des référents (si non fournie par l'AD) |
| Gestion des Sites géo | Plans 2D importés (SVG/DWG converti), captures d'extrusion 3D |
| Gestion des Groupes AD | Justificatifs de demande d'accès à un groupe confidentiel |
| Référents Support | Procédures/documents internes par domaine (ex. fiche procédure Bâtiments) |

---

## 8. Modèle de confidentialité transverse

### 8.1 Niveaux retenus

| Niveau | Portée |
|---|---|
| `public` | Visible par tout utilisateur authentifié du portail |
| `service` | Visible par les membres du même `org_unit` que l'entité (ou de son périmètre hiérarchique — direction englobante), au sens de `org_scope_resolver` |
| `private` | Visible uniquement par le créateur/propriétaire, les rôles `core.admin`, et les octrois explicites |

S'applique de façon identique aux **attributs** (`attribute_values.confidentiality_override` ou héritage de `attribute_definitions.default_confidentiality`) et aux **documents** (`documents.confidentiality`).

### 8.2 Résolution de la portée "service"

Chaque type d'entité (`entity_types.org_scope_resolver`) doit fournir un moyen de retrouver l'`org_unit_id` propriétaire :

- Pour `core.org_unit` : lui-même.
- Pour `core.site` : via les `org_units` qui lui sont rattachés, ou un `org_unit_id` porté directement par le site si un site peut appartenir à un seul service.
- Pour une entité de plugin (ex. `plugin.referents_support.contact`) : le plugin fournit une fonction résolveur enregistrée à l'activation, ex. `referents_support_resolve_org_unit($entity_id)`.

```php
function canView($confidentiality, $entity_type, $entity_id, $user) {
    if ($confidentiality === 'public') return true;
    if ($confidentiality === 'private') {
        return isOwner($entity_type, $entity_id, $user)
            || can($user['id'], 'core.admin')
            || hasExplicitGrant($entity_type, $entity_id, $user);
    }
    // 'service'
    $owner_org_unit = resolveOrgUnit($entity_type, $entity_id);
    return userBelongsToOrgUnit($user, $owner_org_unit); // égalité ou ascendance hiérarchique
}
```

### 8.3 Octrois explicites (extension du modèle privé)

Pour les cas "privé mais partagé à quelqu'un de précis" (ex. justificatif transmis au support informatique) :

```sql
CREATE TABLE confidentiality_grants (
    id INTEGER PRIMARY KEY,
    target_type TEXT NOT NULL,      -- 'document' | 'attribute_value'
    target_id INTEGER NOT NULL,
    grantee_type TEXT NOT NULL,     -- 'user' | 'role' | 'org_unit'
    grantee_id INTEGER NOT NULL,
    granted_by INTEGER REFERENCES users(id),
    granted_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

### 8.4 Points d'application

- Listes/rendus : toute fonction de lecture (`getEntityAttributes`, `getEntityDocuments`, mais aussi les futures listes de l'Annuaire ou de Référents Support) doit filtrer via `canView()` avant affichage — jamais un filtrage côté front seul.
- Export CSV (pattern déjà standardisé `exportCsv()`) : doit exclure les valeurs `private` non autorisées et marquer les colonnes `service` filtrées selon l'utilisateur exportateur.
- Recherche transverse (si un moteur de recherche global est ajouté au noyau plus tard) : doit indexer la confidentialité comme critère de filtrage, pas seulement l'entité.

---

## 9. Mise à jour du contrat plugin (manifest)

Ajout de deux blocs optionnels au `manifest.json` pour qu'un plugin déclare ses propres types d'entités et leurs résolveurs de portée :

```json
"entity_types": [
  {
    "code": "plugin.referents_support.contact",
    "label": "Contact référent support",
    "org_scope_resolver": "includes/hooks.php::resolveContactOrgUnit"
  }
],
"document_categories": [
  { "code": "procedure", "label": "Procédure interne", "default_confidentiality": "service" }
]
```

À l'activation (`onActivate()`), le noyau appelle automatiquement `registerEntityType()` pour chaque entrée déclarée — le plugin n'écrit jamais directement dans `entity_types`.

---

## 10. Points à trancher avant développement

- **Fréquence de synchronisation AD** du référentiel noyau (cron nocturne + rafraîchissement à la connexion, sur le modèle `extensionAttribute4` déjà utilisé pour vos dates de sync).
- **Granularité du RBAC** : rôle par plugin suffit-il, ou faut-il un scope par `org_unit`/`site` (ex. responsable habilité seulement sur son service) ? Impacte le schéma `user_roles` — et rejoint directement la résolution de la confidentialité "service" (§8.2), qui suppose déjà cette notion de portée.
- **Mode BDD par défaut** des futurs plugins légers (`sqlite_shared`) vs lourds (`mysql_own`) — à documenter dans un gabarit de plugin de départ (`plugins/_template/`).
- **Ascendance hiérarchique du niveau "service"** : un membre d'une Direction voit-il automatiquement le "service" de tous les Services qu'elle contient, ou la confidentialité "service" s'arrête-t-elle strictement au service exact ? Impacte `userBelongsToOrgUnit()`.
- **Suppression d'un attribut personnalisé ayant déjà des valeurs saisies** : archivage (soft-delete de la définition, valeurs conservées mais non éditables) ou suppression en cascade — à trancher avant d'exposer le CRUD aux admins.
