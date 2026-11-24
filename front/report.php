<?php
include('../../../inc/includes.php');
Session::checkRight('computer', READ);
require_once __DIR__.'/../inc/report.class.php';
function fw_report_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fw_report_url($filters, $page = 1, $export = '') {
    $args = $filters;
    $args['page'] = max(1,(int)$page);
    if ($export) $args['export'] = $export;
    return '?'.http_build_query($args, '', '&', PHP_QUERY_RFC3986);
}
$filters = PluginFujitsuwarrantyReport::filters($_GET);
$page = max(1,(int)($_GET['page'] ?? 1));
$total = PluginFujitsuwarrantyReport::count($filters);
$pages = max(1,(int)ceil($total/50));
$page = min($page,$pages);
$export = isset($_GET['export']) && is_string($_GET['export']) ? $_GET['export'] : '';
$columns = ['Type','Nom','Fabricant','Modèle','Numéro de série','Entité','Début garantie','Fin garantie','État','Jours depuis expiration'];
$makeRow = static function ($r) {
    return [$r['category'],$r['name'],$r['manufacturer'],$r['model'],$r['serial'],$r['entity_name'] ?: $r['entities_id'],$r['warranty_date'],$r['warranty_end'],$r['status'],$r['status']==='Expirée' ? $r['expired_days'] : ''];
};
if ($export === 'csv' || $export === 'xlsx') {
    // Bound export size to avoid excessive memory and time usage.
    if ($total > 10000) {
        http_response_code(413);
        die('Export limité à 10 000 matériels. Affinez les filtres.');
    }
    $rows = $total ? PluginFujitsuwarrantyReport::rows($filters,0,max(1,$total)) : [];
    $filename = 'garanties-glpi-'.date('Y-m-d');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output','w');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,$columns,';');
        foreach ($rows as $r) {
            $values = $makeRow($r);
            // Protect spreadsheet users from formula injection.
            $values = array_map(static function($v) { $s=(string)$v; return preg_match('/^[\s]*[=+@\-]/u',$s) ? "'".$s : $s; },$values);
            fputcsv($out,$values,';');
        }
        fclose($out);
        exit;
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('Extension PHP zip nécessaire pour exporter au format XLSX. Utilisez CSV.');
    }
    $tmp = tempnam(sys_get_temp_dir(),'fwxlsx_');
    if ($tmp === false) { http_response_code(500); die('Impossible de créer le fichier temporaire.'); }
    $zip = new ZipArchive();
    if ($zip->open($tmp,ZipArchive::OVERWRITE)!==true) { @unlink($tmp); http_response_code(500); die('Erreur de création XLSX.'); }
    $xml = static function($v) { return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8'); };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $all = array_merge([$columns],array_map($makeRow,$rows));
    foreach ($all as $idx=>$values) {
        $sheet .= '<row r="'.($idx+1).'">';
        foreach ($values as $v) $sheet .= '<c t="inlineStr"><is><t xml:space="preserve">'.$xml($v).'</t></is></c>';
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Garanties" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
    header('Content-Length: '.filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}
$rows = $total ? PluginFujitsuwarrantyReport::rows($filters,($page-1)*50,50) : [];
Html::header('Rapport des garanties',$_SERVER['PHP_SELF'],'assets','computer');
echo '<div class="container-fluid"><h2>Fujitsu Warranty — Rapport des garanties v1.2.5</h2>';
echo '<p>Inventaire multimarque, en lecture seule. Les garanties inconnues ne sont pas considérées comme expirées.</p>';
echo '<form method="get" class="mb-3"><div class="row g-2">';
$choices = [
 'status'=>['expired'=>'Hors garantie','soon'=>'Expire sous 90 jours','missing'=>'Sans information','valid'=>'Garantie valide','all'=>'Tous les états'],
 'type'=>['all'=>'Postes et serveurs','workstation'=>'Postes','server'=>'Serveurs'],
 'scope'=>['all'=>'Entités autorisées','current'=>'Entité courante'],
];
foreach ($choices as $key=>$options) {
    echo '<div class="col-auto"><label for="fw_'.$key.'">'.fw_report_h(ucfirst($key)).'</label><select class="form-select" id="fw_'.$key.'" name="'.$key.'">';
    foreach ($options as $value=>$label) echo '<option value="'.fw_report_h($value).'"'.($filters[$key]===$value?' selected':'').'>'.fw_report_h($label).'</option>';
    echo '</select></div>';
}
// Entity selection is limited to entities authorized by the active GLPI session.
$authorizedEntities = PluginFujitsuwarrantyReport::authorizedEntities();
echo '<div class="col-auto"><label for="fw_entity">Entité</label><select class="form-select" id="fw_entity" name="entity">';
echo '<option value="all"'.($filters['entity']==='all'?' selected':'').'>Toutes les entités autorisées</option>';
foreach ($authorizedEntities as $id => $name) {
    echo '<option value="'.(int)$id.'"'.($filters['entity']===(string)$id?' selected':'').'>'.fw_report_h($name).'</option>';
}
echo '</select></div>';
echo '<div class="col-auto align-self-end"><button type="submit" class="btn btn-primary">Filtrer</button></div></div></form>';
echo '<p><strong>'.(int)$total.' matériels</strong> — Page '.(int)$page.' sur '.(int)$pages.' — <a class="btn btn-outline-secondary" href="'.fw_report_h(fw_report_url($filters,1,'csv')).'">Exporter CSV</a> <a class="btn btn-outline-secondary" href="'.fw_report_h(fw_report_url($filters,1,'xlsx')).'">Exporter Excel (.xlsx)</a></p>';
echo '<div class="table-responsive"><table class="table table-striped"><thead><tr>';
foreach ($columns as $col) echo '<th>'.fw_report_h($col).'</th>';
echo '</tr></thead><tbody>';
// Keep rows grouped by entity, including when the list spans several pages.
$lastEntity = null;
foreach ($rows as $r) {
    $entityId = (int)$r['entities_id'];
    if ($lastEntity !== $entityId) {
        $entityLabel = $r['entity_name'] ?: ('Entité n°'.$entityId);
        echo '<tr class="table-secondary"><th colspan="'.count($columns).'">'.fw_report_h($entityLabel).'</th></tr>';
        $lastEntity = $entityId;
    }
    echo '<tr>';
    foreach ($makeRow($r) as $i=>$value) {
        if ($i===1) {
            $url = $CFG_GLPI['root_doc'].'/front/computer.form.php?id='.(int)$r['id'];
            echo '<td><a href="'.fw_report_h($url).'">'.fw_report_h($value).'</a></td>';
        } else echo '<td>'.fw_report_h($value).'</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';
if (!$rows) echo '<p>Aucun matériel correspondant aux filtres.</p>';
echo '<nav class="d-flex gap-3">';
if ($page>1) echo '<a href="'.fw_report_h(fw_report_url($filters,$page-1)).'">← Précédente</a>';
echo '<span>Page '.(int)$page.' / '.(int)$pages.'</span>';
if ($page<$pages) echo '<a href="'.fw_report_h(fw_report_url($filters,$page+1)).'">Suivante →</a>';
echo '</nav></div>';
Html::footer();
