<?php
/**
 * core/attributes.php — moteur d'attributs personnalisés (EAV), extensible en
 * runtime par l'administrateur, sans modification de code.
 *
 * Décision retenue : suppression d'une définition d'attribut => CASCADE sur
 * ses valeurs (portée par la contrainte FK ON DELETE CASCADE du schéma).
 */

require_once __DIR__ . '/confidentiality.php';

function registerEntityType($code, $label, $pluginCode = null, $orgScopeResolver = null)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO entity_types (code, label, plugin_code, org_scope_resolver)
         VALUES (:code, :label, :plugin, :resolver)'
    );
    $stmt->execute(array(
        ':code' => $code, ':label' => $label, ':plugin' => $pluginCode, ':resolver' => $orgScopeResolver,
    ));
}

function createAttributeDefinition($entityType, array $data)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO attribute_definitions
         (entity_type, code, label, data_type, options_json, is_required, is_multiple,
          default_confidentiality, sort_order, plugin_code, created_by, created_at)
         VALUES (:entity_type, :code, :label, :data_type, :options, :required, :multiple,
                 :confidentiality, :sort, :plugin, :created_by, :created_at)'
    );
    $user = currentUser();
    $stmt->execute(array(
        ':entity_type'     => $entityType,
        ':code'            => slugify($data['code']),
        ':label'           => $data['label'],
        ':data_type'       => $data['data_type'],
        ':options'         => isset($data['options_json']) ? $data['options_json'] : null,
        ':required'        => !empty($data['is_required']) ? 1 : 0,
        ':multiple'        => !empty($data['is_multiple']) ? 1 : 0,
        ':confidentiality' => isset($data['default_confidentiality']) ? $data['default_confidentiality'] : 'service',
        ':sort'            => isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
        ':plugin'          => isset($data['plugin_code']) ? $data['plugin_code'] : null,
        ':created_by'      => $user ? $user['id'] : null,
        ':created_at'      => nowIso(),
    ));
    $id = $pdo->lastInsertId();
    logAction('attribute_definition_created', $entityType . '#' . $data['code']);
    return $id;
}

function updateAttributeDefinition($id, array $data)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'UPDATE attribute_definitions SET label = :label, options_json = :options,
         is_required = :required, is_multiple = :multiple,
         default_confidentiality = :confidentiality, sort_order = :sort WHERE id = :id'
    );
    $stmt->execute(array(
        ':label' => $data['label'],
        ':options' => isset($data['options_json']) ? $data['options_json'] : null,
        ':required' => !empty($data['is_required']) ? 1 : 0,
        ':multiple' => !empty($data['is_multiple']) ? 1 : 0,
        ':confidentiality' => isset($data['default_confidentiality']) ? $data['default_confidentiality'] : 'service',
        ':sort' => isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
        ':id' => $id,
    ));
    logAction('attribute_definition_updated', (string)$id);
}

/**
 * Suppression en cascade (décision) : la contrainte FK ON DELETE CASCADE du
 * schéma supprime automatiquement les attribute_values associées.
 */
function deleteAttributeDefinition($id)
{
    $pdo = getPortailPdo();
    $pdo->prepare('DELETE FROM attribute_definitions WHERE id = :id')->execute(array(':id' => $id));
    logAction('attribute_definition_deleted_cascade', (string)$id);
}

function getAttributeDefinitions($entityType)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare('SELECT * FROM attribute_definitions WHERE entity_type = :et ORDER BY sort_order, label');
    $stmt->execute(array(':et' => $entityType));
    return $stmt->fetchAll();
}

/** Retourne les attributs d'une entité, filtrés par confidentialité pour $user. */
function getEntityAttributes($entityType, $entityId, array $user)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'SELECT av.*, ad.code AS attr_code, ad.label, ad.data_type, ad.default_confidentiality
         FROM attribute_values av
         JOIN attribute_definitions ad ON ad.id = av.definition_id
         WHERE ad.entity_type = :et AND av.entity_id = :eid
         ORDER BY ad.sort_order'
    );
    $stmt->execute(array(':et' => $entityType, ':eid' => $entityId));
    $rows = $stmt->fetchAll();

    $visible = array();
    foreach ($rows as $row) {
        $confidentiality = $row['confidentiality_override'] ? $row['confidentiality_override'] : $row['default_confidentiality'];
        if (canView($confidentiality, $entityType, $entityId, $user)) {
            $visible[] = $row;
        }
    }
    return $visible;
}

function setEntityAttribute($entityType, $entityId, $attrCode, $value, $confidentiality = null)
{
    $pdo = getPortailPdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM attribute_definitions WHERE entity_type = :et AND code = :code'
    );
    $stmt->execute(array(':et' => $entityType, ':code' => $attrCode));
    $def = $stmt->fetch();
    if (!$def) {
        throw new InvalidArgumentException("Attribut inconnu : $entityType.$attrCode");
    }

    $columns = array('value_text' => null, 'value_number' => null, 'value_date' => null, 'value_boolean' => null);
    switch ($def['data_type']) {
        case 'number':
            $columns['value_number'] = (float)$value;
            break;
        case 'date':
            $columns['value_date'] = $value;
            break;
        case 'boolean':
            $columns['value_boolean'] = $value ? 1 : 0;
            break;
        default:
            $columns['value_text'] = (string)$value;
    }

    $user = currentUser();
    $ins = $pdo->prepare(
        'INSERT INTO attribute_values
         (definition_id, entity_id, value_text, value_number, value_date, value_boolean,
          confidentiality_override, updated_by, updated_at)
         VALUES (:def, :eid, :vt, :vn, :vd, :vb, :conf, :uid, :now)'
    );
    $ins->execute(array(
        ':def' => $def['id'], ':eid' => $entityId,
        ':vt' => $columns['value_text'], ':vn' => $columns['value_number'],
        ':vd' => $columns['value_date'], ':vb' => $columns['value_boolean'],
        ':conf' => $confidentiality, ':uid' => $user ? $user['id'] : null, ':now' => nowIso(),
    ));
}
