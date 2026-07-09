CREATE TABLE IF NOT EXISTS plg_annuaire_ad_referents (
    id INTEGER PRIMARY KEY,
    org_unit_id INTEGER NOT NULL REFERENCES org_units(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    domaine TEXT
);
