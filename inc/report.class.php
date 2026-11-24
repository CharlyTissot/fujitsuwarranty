<?php
/** Read-only, entity-scoped warranty inventory reporting. */
class PluginFujitsuwarrantyReport {
    public static function filters($source) {
        $allowed = [
            'status' => ['expired', 'soon', 'missing', 'valid', 'all'],
            'type' => ['all', 'workstation', 'server'],
            'scope' => ['all', 'current'],
        ];
        $out = [];
        foreach ($allowed as $key => $values) {
            $value = isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $values[0];
            $out[$key] = in_array($value, $values, true) ? $value : $values[0];
        }
        $out['entity'] = isset($source['entity']) && is_scalar($source['entity']) && preg_match('/^[0-9]+$/D', (string)$source['entity']) ? (string)(int)$source['entity'] : 'all';
        return $out;
    }
    public static function authorizedEntities() {
        global $DB;
        $ids = $_SESSION['glpiactiveentities'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) { return $id >= 0; })));
        if (!$ids) return [];
        $result = [];
        $sql = 'SELECT id, name, completename FROM glpi_entities WHERE id IN ('.implode(',', $ids).') ORDER BY completename, id';
        foreach ($DB->request($sql) as $row) {
            $result[(int)$row['id']] = (string)($row['completename'] ?: $row['name'] ?: $row['id']);
        }
        return $result;
    }
    private static function where($filters) {
        // Use GLPI's effective entity permissions; never trust entity IDs from GET.
        // GLPI 10 exposes effective entity IDs in the active session.
        $entities = $_SESSION['glpiactiveentities'] ?? [];
        if (!is_array($entities)) $entities = [];
        $entities = array_values(array_unique(array_filter(array_map('intval', $entities), static function ($id) { return $id >= 0; })));
        $where = ['c.is_deleted=0', 'c.is_template=0'];
        if (!$entities) {
            $where[] = '1=0';
        } else {
            if ($filters['scope'] === 'current') {
                $current = (int)($_SESSION['glpiactive_entity'] ?? -1);
                $entities = in_array($current, $entities, true) ? [$current] : [];
            }
            if (($filters['entity'] ?? 'all') !== 'all') {
                $requested = (int)$filters['entity'];
                $entities = in_array($requested, $entities, true) ? [$requested] : [];
            }
            $where[] = $entities ? 'c.entities_id IN ('.implode(',', $entities).')' : '1=0';
        }
        // Classification uses GLPI computer type, supplemented by naming conventions.
        $server = "(LOWER(COALESCE(ct.name,'')) LIKE '%serveur%' OR LOWER(COALESCE(ct.name,'')) LIKE '%server%' OR UPPER(c.name) LIKE 'HYPERV%' OR UPPER(c.name) LIKE 'SRV%')";
        if ($filters['type'] === 'server') $where[] = $server;
        if ($filters['type'] === 'workstation') $where[] = 'NOT '.$server;
        $missing = "(i.id IS NULL OR i.warranty_date IS NULL OR i.warranty_date='0000-00-00' OR i.warranty_duration IS NULL OR i.warranty_duration<=0)";
        $end = 'DATE_ADD(i.warranty_date, INTERVAL i.warranty_duration MONTH)';
        if ($filters['status'] === 'missing') $where[] = $missing;
        if ($filters['status'] === 'expired') $where[] = 'NOT '.$missing.' AND '.$end.' < CURDATE()';
        if ($filters['status'] === 'soon') $where[] = 'NOT '.$missing.' AND '.$end.' >= CURDATE() AND '.$end.' <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
        if ($filters['status'] === 'valid') $where[] = 'NOT '.$missing.' AND '.$end.' >= CURDATE()';
        return implode(' AND ', $where);
    }
    public static function count($filters) {
        global $DB;
        $sql = 'SELECT COUNT(*) AS total FROM glpi_computers c LEFT JOIN glpi_computertypes ct ON ct.id=c.computertypes_id LEFT JOIN glpi_infocoms i ON i.itemtype=\'Computer\' AND i.items_id=c.id WHERE '.self::where($filters);
        foreach ($DB->request($sql) as $row) return (int)$row['total'];
        return 0;
    }
    public static function rows($filters, $offset = 0, $limit = 50) {
        global $DB;
        $sql = "SELECT c.id,c.name,c.serial,c.entities_id,e.completename AS entity_name,m.name AS manufacturer,cm.name AS model,ct.name AS computer_type,i.warranty_date,i.warranty_duration,DATE_ADD(i.warranty_date, INTERVAL i.warranty_duration MONTH) AS warranty_end, DATEDIFF(CURDATE(),DATE_ADD(i.warranty_date, INTERVAL i.warranty_duration MONTH)) AS expired_days FROM glpi_computers c LEFT JOIN glpi_entities e ON e.id=c.entities_id LEFT JOIN glpi_manufacturers m ON m.id=c.manufacturers_id LEFT JOIN glpi_computermodels cm ON cm.id=c.computermodels_id LEFT JOIN glpi_computertypes ct ON ct.id=c.computertypes_id LEFT JOIN glpi_infocoms i ON i.itemtype='Computer' AND i.items_id=c.id WHERE ".self::where($filters)." ORDER BY e.completename, c.entities_id, c.name, c.id LIMIT ".max(1,min(10000,(int)$limit)).' OFFSET '.max(0,(int)$offset);
        $rows = [];
        foreach ($DB->request($sql) as $row) {
            $name = strtoupper((string)$row['name']);
            $type = strtolower((string)$row['computer_type']);
            $row['category'] = (strpos($name,'HYPERV')===0 || strpos($name,'SRV')===0 || strpos($type,'serveur')!==false || strpos($type,'server')!==false) ? 'Serveur' : 'Poste';
            $row['warranty_end'] = (!empty($row['warranty_date']) && $row['warranty_date'] !== '0000-00-00' && (int)$row['warranty_duration']>0) ? $row['warranty_end'] : null;
            $row['status'] = !$row['warranty_end'] ? 'Inconnue' : ($row['warranty_end'] < date('Y-m-d') ? 'Expirée' : 'Valide');
            $rows[] = $row;
        }
        return $rows;
    }
}
