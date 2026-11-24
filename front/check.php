<?php
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);
require_once __DIR__ . '/../inc/sync.class.php';

function fw_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function fw_filter($value, array $allowed) {
    return in_array($value, $allowed, true) ? $value : 'all';
}
function fw_url($offset, $warranty, $nameFilter) {
    return '?'.http_build_query([
        'offset'=>max(0,(int)$offset),
        'warranty'=>$warranty,
        'name_filter'=>$nameFilter
    ], '', '&', PHP_QUERY_RFC3986);
}

$posted = ($_SERVER['REQUEST_METHOD'] === 'POST');
$source = $posted ? $_POST : $_GET;
$offset = max(0, (int)($source['offset'] ?? 0));
$warranty = fw_filter((string)($source['warranty'] ?? 'all'), ['all','missing']);
$nameFilter = fw_filter((string)($source['name_filter'] ?? 'all'), ['all','hyperv','srv','servers']);
$pageSize = 50;
$totalComputers = PluginFujitsuwarrantySync::countComputers($warranty, $nameFilter);
$totalPages = max(1, (int)ceil($totalComputers / $pageSize));
$currentPage = min($totalPages, (int)floor($offset / $pageSize) + 1);
$offset = ($currentPage - 1) * $pageSize;
$rows = PluginFujitsuwarrantySync::computers($offset, $pageSize, $warranty, $nameFilter);
$results = [];
$ids = [];
$action = '';
$write = false;
$overwrite = false;
$warning = '';

if ($posted) {
    $action = (string)($_POST['action'] ?? '');
    $write = isset($_POST['write']);
    $overwrite = isset($_POST['overwrite']);
    if ($write && !isset($_POST['confirm_write'])) {
        $write = false;
        $warning = 'Écriture refusée : confirmation obligatoire. Traitement en simulation.';
    }
    if ($action === 'selected') {
        $requested = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])),
            static function ($id) { return $id > 0; }
        )));
        // Only allow IDs actually displayed in the current filtered page.
        $allowed = array_map('intval', array_column($rows, 'id'));
        $ids = array_slice(array_values(array_intersect($requested, $allowed)), 0, 20);
        if (count($requested) > 20) {
            $warning .= ' Seuls les 20 premiers ordinateurs sélectionnés ont été traités.';
        }
    } elseif ($action === 'batch') {
        foreach (PluginFujitsuwarrantySync::computers($offset, 20, $warranty, $nameFilter) as $computer) {
            $ids[] = (int)$computer['id'];
        }
    } else {
        $warning = 'Action de synchronisation inconnue.';
    }

    // Run before GLPI's HTML header; preserve the proven v1.0.0 rendering fix.
    foreach ($ids as $id) {
        $data = [];
        $message = '';
        $level = ob_get_level();
        ob_start();
        try {
            $data = PluginFujitsuwarrantySync::syncOne($id, $overwrite, !$write);
            $message = ($data['status'] ?? 'Terminé') . ' — ' . ($data['serial'] ?? '')
                . ' — début : ' . ($data['start'] ?? '');
        } catch (Throwable $e) {
            $message = 'Erreur : ' . $e->getMessage();
            error_log('Fujitsu Warranty ID ' . $id . ': ' . $e->getMessage());
        } finally {
            while (ob_get_level() > $level) {
                $unexpected = (string)ob_get_clean();
                if (trim($unexpected) !== '') {
                    error_log('Fujitsu Warranty: sortie interceptée ID ' . $id
                        . ' (' . strlen($unexpected) . ' octets)');
                }
            }
        }
        $results[] = [
            'id' => $id,
            'message' => $message,
            'end' => $data['end'] ?? '—',
            'glpi_end' => $data['glpi_end'] ?? '—',
            'difference_days' => $data['difference_days'] ?? null,
        ];
    }
}

Html::header('Fujitsu Warranty', $_SERVER['PHP_SELF'], 'config', 'plugins');
echo '<div class="center">';
echo '<p><a class="btn btn-outline-primary" href="report.php">Rapport des garanties (postes et serveurs, export)</a></p>';
echo '<h2>Fujitsu Warranty v1.2.0</h2>';
echo '<p>Synchronisation manuelle ou par lots (20 maximum). Simulation par défaut ; garanties existantes protégées.</p>';

// GET filter form, separate from POST sync form.
echo '<form method="get" style="margin:15px 0">';
echo '<label for="fw-warranty">Garantie : </label> ';
echo '<select id="fw-warranty" name="warranty" class="form-select d-inline-block w-auto">';
foreach (['all'=>'Toutes les garanties','missing'=>'Sans garantie renseignée'] as $value=>$label) {
    echo '<option value="'.fw_h($value).'"'.($warranty===$value?' selected':'').'>'.fw_h($label).'</option>';
}
echo '</select> &nbsp; ';
echo '<label for="fw-name">Nom du matériel : </label> ';
echo '<select id="fw-name" name="name_filter" class="form-select d-inline-block w-auto">';
foreach (['all'=>'Tous les noms','hyperv'=>'HYPERV*','srv'=>'SRV*','servers'=>'HYPERV* ou SRV*'] as $value=>$label) {
    echo '<option value="'.fw_h($value).'"'.($nameFilter===$value?' selected':'').'>'.fw_h($label).'</option>';
}
echo '</select> &nbsp; ';
echo '<button type="submit" class="btn btn-primary">Filtrer</button> ';
echo '<a class="btn btn-outline-secondary" href="?">Réinitialiser</a>';
echo '</form>';

echo '<form method="post" id="fw-sync-form">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo '<input type="hidden" name="offset" value="'.(int)$offset.'">';
echo '<input type="hidden" name="warranty" value="'.fw_h($warranty).'">';
echo '<input type="hidden" name="name_filter" value="'.fw_h($nameFilter).'">';
echo '<p><label><input type="checkbox" name="write" value="1"> Autoriser les écritures GLPI</label> &nbsp; ';
echo '<label><input type="checkbox" name="overwrite" value="1"> Écraser les garanties existantes</label></p>';
echo '<p><label><input type="checkbox" name="confirm_write" value="1"> Je confirme les modifications sur les fiches GLPI</label></p>';
echo '<p><button type="submit" class="btn btn-primary" name="action" value="selected">Synchroniser les ordinateurs cochés</button> &nbsp; ';
echo '<button type="submit" class="btn btn-secondary" name="action" value="batch">Synchroniser le lot filtré (20 premiers)</button></p>';
echo '<p><label><input type="checkbox" id="fw-select-all"> <strong>Tout cocher / décocher sur cette page</strong></label> ';
echo '<span class="text-muted" id="fw-selected-count">(0 sélectionné)</span></p>';
echo '<div style="overflow-x:auto"><table class="table table-striped">';
echo '<thead><tr><th>Choix</th><th>ID</th><th>Nom</th><th>Numéro de série</th></tr></thead><tbody>';
foreach ($rows as $computer) {
    echo '<tr><td><input type="checkbox" class="fw-computer-check" name="ids[]" value="'.(int)$computer['id'].'"></td>';
    echo '<td>'.(int)$computer['id'].'</td><td>'.fw_h($computer['name']).'</td><td>'.fw_h($computer['serial']).'</td></tr>';
}
if (!$rows) echo '<tr><td colspan="4">Aucun ordinateur Fujitsu ne correspond aux filtres.</td></tr>';
echo '</tbody></table></div></form>';
echo '<nav aria-label="Pagination Fujitsu Warranty" style="margin:15px 0">';
echo '<p><strong>Page '.(int)$currentPage.' sur '.(int)$totalPages.'</strong> — '.(int)$totalComputers.' matériel(s) correspondant aux filtres</p>';
if ($currentPage > 1) {
    echo '<a class="btn btn-outline-secondary btn-sm" href="'.fw_h(fw_url(0,$warranty,$nameFilter)).'">Première</a> ';
    echo '<a class="btn btn-outline-secondary btn-sm" href="'.fw_h(fw_url(($currentPage-2)*$pageSize,$warranty,$nameFilter)).'">Précédente</a> ';
}
$firstPage = max(1, $currentPage - 2);
$lastPage = min($totalPages, $currentPage + 2);
for ($page = $firstPage; $page <= $lastPage; $page++) {
    $class = $page === $currentPage ? 'btn-primary' : 'btn-outline-secondary';
    echo '<a class="btn '. $class .' btn-sm" href="'.fw_h(fw_url(($page-1)*$pageSize,$warranty,$nameFilter)).'"'.($page === $currentPage ? ' aria-current="page"' : '').'>'.(int)$page.'</a> ';
}
if ($currentPage < $totalPages) {
    echo '<a class="btn btn-outline-secondary btn-sm" href="'.fw_h(fw_url($currentPage*$pageSize,$warranty,$nameFilter)).'">Suivante</a> ';
    echo '<a class="btn btn-outline-secondary btn-sm" href="'.fw_h(fw_url(($totalPages-1)*$pageSize,$warranty,$nameFilter)).'">Dernière</a>';
}
echo '</nav>';

// Standalone browser JS: does not depend on GLPI hotkeys or jQuery.
echo '<script>(function(){';
echo 'const all=document.getElementById("fw-select-all");';
echo 'const boxes=Array.from(document.querySelectorAll(".fw-computer-check"));';
echo 'const count=document.getElementById("fw-selected-count");';
echo 'function update(){const n=boxes.filter(b=>b.checked).length;count.textContent="("+n+" sélectionné(s) sur "+boxes.length+")";all.checked=boxes.length>0&&n===boxes.length;all.indeterminate=n>0&&n<boxes.length;}';
echo 'all.addEventListener("change",function(){boxes.forEach(b=>b.checked=all.checked);update();});';
echo 'boxes.forEach(b=>b.addEventListener("change",update));update();';
echo '})();</script>';

if ($posted) {
    if ($warning !== '') echo '<div class="alert alert-warning">'.fw_h($warning).'</div>';
    echo '<h3>Résultats '.($write?'(écriture autorisée)':'(simulation)').'</h3>';
    if (!$ids) echo '<div class="alert alert-warning">Aucun ordinateur sélectionné.</div>';
    echo '<p>'.count($results).' ordinateur(s) traité(s).</p>';
    echo '<div style="overflow-x:auto"><table class="table table-striped">';
    echo '<thead><tr><th>Matériel</th><th>Résultat</th><th>Fin Fujitsu</th><th>Fin calculée GLPI</th><th>Écart</th></tr></thead><tbody>';
    foreach ($results as $item) {
        $link='/front/computer.form.php?id='.(int)$item['id'];
        echo '<tr><td><a href="'.fw_h($link).'">#'.(int)$item['id'].'</a></td>';
        echo '<td>'.fw_h($item['message']).'</td><td>'.fw_h($item['end']).'</td>';
        echo '<td>'.fw_h($item['glpi_end']).'</td><td>'.($item['difference_days']!==null
            ? fw_h($item['difference_days']).' jour(s)' : '—').'</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';
Html::footer();
