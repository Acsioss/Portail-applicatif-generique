CREATE TABLE IF NOT EXISTS plg_groupes_ad_groups (
    id INTEGER PRIMARY KEY,
    ad_group_dn TEXT UNIQUE NOT NULL,
    org_unit_id INTEGER REFERENCES org_units(id) ON DELETE SET NULL,
    mission TEXT,
    localisation TEXT
);
