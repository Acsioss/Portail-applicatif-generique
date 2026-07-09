-- =====================================================================
-- Portail applicatif générique — schéma noyau (SQLite)
-- Décisions d'architecture appliquées :
--   - RBAC global par plugin (pas de scope org_unit/site dans user_roles)
--   - Confidentialité "service" héritée (une Direction voit ses Services)
--   - Suppression d'un attribut personnalisé => cascade sur ses valeurs
-- =====================================================================

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------
-- Identité / référentiel organisationnel
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS org_units (
    id INTEGER PRIMARY KEY,
    type TEXT NOT NULL CHECK (type IN ('direction','service')),
    parent_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
    code TEXT,
    name TEXT NOT NULL,
    responsable_user_id INTEGER,
    site_id INTEGER
);
CREATE INDEX IF NOT EXISTS idx_org_units_parent ON org_units(parent_id);

CREATE TABLE IF NOT EXISTS sites (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE,
    name TEXT NOT NULL,
    address TEXT,
    lat REAL,
    lng REAL,
    parent_site_id INTEGER REFERENCES sites(id) ON DELETE SET NULL,
    org_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    login TEXT UNIQUE NOT NULL,
    ldap_dn TEXT,
    display_name TEXT,
    email TEXT,
    org_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
    source TEXT NOT NULL DEFAULT 'ldap' CHECK (source IN ('ldap','local')),
    password_hash TEXT,               -- uniquement pour source='local' (comptes techniques)
    active INTEGER NOT NULL DEFAULT 1,
    last_login TEXT
);
CREATE INDEX IF NOT EXISTS idx_users_org_unit ON users(org_unit_id);

-- ---------------------------------------------------------------------
-- RBAC — rôle global par plugin (décision : pas de scope org_unit/site)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,        -- 'core.admin', 'plugin.annuaire_ad.reader'
    label TEXT NOT NULL,
    plugin_code TEXT,                 -- NULL = rôle noyau
    is_system INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS permissions (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,        -- 'annuaire_ad.export'
    label TEXT NOT NULL,
    plugin_code TEXT
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    source TEXT NOT NULL DEFAULT 'direct' CHECK (source IN ('direct','ad_group')),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS ad_group_role_mapping (
    id INTEGER PRIMARY KEY,
    ad_group_dn TEXT NOT NULL,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE (ad_group_dn, role_id)
);

-- ---------------------------------------------------------------------
-- Plugins
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS plugins (
    id INTEGER PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    version TEXT,
    status TEXT NOT NULL DEFAULT 'inactive' CHECK (status IN ('active','inactive')),
    manifest_json TEXT NOT NULL,
    installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
    activated_at TEXT
);

-- ---------------------------------------------------------------------
-- Attributs personnalisés (EAV) — cascade à la suppression (décision)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS entity_types (
    code TEXT PRIMARY KEY,            -- 'core.org_unit', 'plugin.sites_geo.building', ...
    label TEXT NOT NULL,
    plugin_code TEXT,                 -- NULL = type noyau
    org_scope_resolver TEXT           -- 'plugin_code:function' résolvant l'org_unit_id propriétaire
);

CREATE TABLE IF NOT EXISTS attribute_definitions (
    id INTEGER PRIMARY KEY,
    entity_type TEXT NOT NULL REFERENCES entity_types(code) ON DELETE CASCADE,
    code TEXT NOT NULL,
    label TEXT NOT NULL,
    data_type TEXT NOT NULL CHECK (data_type IN
        ('text','textarea','number','date','boolean','select','multiselect','user_ref','org_unit_ref','site_ref')),
    options_json TEXT,
    is_required INTEGER NOT NULL DEFAULT 0,
    is_multiple INTEGER NOT NULL DEFAULT 0,
    default_confidentiality TEXT NOT NULL DEFAULT 'service'
        CHECK (default_confidentiality IN ('public','service','private')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    plugin_code TEXT,                 -- NULL = créé en runtime par un admin
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (entity_type, code)
);

CREATE TABLE IF NOT EXISTS attribute_values (
    id INTEGER PRIMARY KEY,
    definition_id INTEGER NOT NULL REFERENCES attribute_definitions(id) ON DELETE CASCADE,
    entity_id INTEGER NOT NULL,
    value_text TEXT,
    value_number REAL,
    value_date TEXT,
    value_boolean INTEGER,
    confidentiality_override TEXT CHECK (confidentiality_override IN ('public','service','private')),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_attrval_def_entity ON attribute_values(definition_id, entity_id);

-- ---------------------------------------------------------------------
-- Documents / pièces jointes
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS documents (
    id INTEGER PRIMARY KEY,
    entity_type TEXT NOT NULL REFERENCES entity_types(code) ON DELETE CASCADE,
    entity_id INTEGER NOT NULL,
    plugin_code TEXT,
    category TEXT,
    filename_original TEXT NOT NULL,
    filename_stored TEXT NOT NULL,
    mime_type TEXT,
    size_bytes INTEGER,
    confidentiality TEXT NOT NULL DEFAULT 'service' CHECK (confidentiality IN ('public','service','private')),
    uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP,
    checksum TEXT
);
CREATE INDEX IF NOT EXISTS idx_doc_entity ON documents(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS confidentiality_grants (
    id INTEGER PRIMARY KEY,
    target_type TEXT NOT NULL CHECK (target_type IN ('document','attribute_value')),
    target_id INTEGER NOT NULL,
    grantee_type TEXT NOT NULL CHECK (grantee_type IN ('user','role','org_unit')),
    grantee_id INTEGER NOT NULL,
    granted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    granted_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- Transverse : audit, notifications, préférences
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    plugin_code TEXT,
    action TEXT NOT NULL,
    target TEXT,
    ip TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    message TEXT,
    link TEXT,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    pref_key TEXT NOT NULL,
    pref_value TEXT,
    PRIMARY KEY (user_id, pref_key)
);

-- ---------------------------------------------------------------------
-- Données d'amorçage : types d'entités noyau + rôle admin
-- ---------------------------------------------------------------------

INSERT OR IGNORE INTO entity_types (code, label, plugin_code, org_scope_resolver) VALUES
    ('core.org_unit', 'Direction / Service', NULL, 'core:orgUnitSelfResolver'),
    ('core.site',     'Site',                 NULL, 'core:siteOrgUnitResolver'),
    ('core.user',     'Utilisateur',          NULL, 'core:userOrgUnitResolver');

INSERT OR IGNORE INTO roles (code, label, plugin_code, is_system) VALUES
    ('core.admin', 'Administrateur du portail', NULL, 1);
