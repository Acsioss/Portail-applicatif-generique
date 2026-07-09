CREATE TABLE IF NOT EXISTS plg_referents_support_contacts (
    id INTEGER PRIMARY KEY,
    org_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
    domaine TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ligne_directe TEXT
);
