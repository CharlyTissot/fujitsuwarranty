<?php
require_once __DIR__.'/client.class.php';
class PluginFujitsuwarrantySync {
 /** Identical WHERE clause for listing and counting. */
 private static function filterWhere($warranty='all',$nameFilter='all') {
  if (!in_array($warranty,['all','missing'],true)) $warranty='all';
  if (!in_array($nameFilter,['all','hyperv','srv','servers'],true)) $nameFilter='all';
  $where="c.is_deleted=0 AND c.is_template=0 AND c.serial REGEXP '^[A-Za-z0-9-]{4,40}$' AND LOWER(m.name) LIKE '%fujitsu%'";
  if ($warranty==='missing') {
   $where.=" AND NOT EXISTS (SELECT 1 FROM glpi_infocoms i WHERE i.itemtype='Computer' AND i.items_id=c.id AND ((i.warranty_date IS NOT NULL AND i.warranty_date <> '') OR (i.warranty_duration IS NOT NULL AND i.warranty_duration > 0)))";
  }
  if ($nameFilter==='hyperv') $where.=" AND UPPER(c.name) LIKE 'HYPERV%'";
  if ($nameFilter==='srv') $where.=" AND UPPER(c.name) LIKE 'SRV%'";
  if ($nameFilter==='servers') $where.=" AND (UPPER(c.name) LIKE 'HYPERV%' OR UPPER(c.name) LIKE 'SRV%')";
  return $where;
 }
 public static function countComputers($warranty='all',$nameFilter='all') {
  global $DB;
  $sql="SELECT COUNT(*) AS total FROM glpi_computers c INNER JOIN glpi_manufacturers m ON m.id=c.manufacturers_id WHERE ".self::filterWhere($warranty,$nameFilter);
  foreach ($DB->request($sql) as $row) return (int)$row['total'];
  return 0;
 }
 /** Return Fujitsu computers matching server-side filters. */
 public static function computers($offset=0,$limit=50,$warranty='all',$nameFilter='all') {
  global $DB;
  $offset=max(0,(int)$offset);
  $limit=max(1,min(100,(int)$limit));
  $sql="SELECT c.id,c.name,c.serial,m.name AS manufacturer FROM glpi_computers c INNER JOIN glpi_manufacturers m ON m.id=c.manufacturers_id WHERE ".self::filterWhere($warranty,$nameFilter)." ORDER BY c.id LIMIT ".$limit." OFFSET ".$offset;
  $rows=[];
  foreach ($DB->request($sql) as $r) $rows[]=$r;
  return $rows;
 }
 public static function syncOne($id,$overwrite=false,$dryRun=true) {
  global $DB;
  $id=(int)$id;
  $computer=new Computer();
  if (!$id || !$computer->getFromDB($id) || !empty($computer->fields['is_deleted']) || !empty($computer->fields['is_template'])) throw new RuntimeException('Ordinateur introuvable.');
  $manufacturer=new Manufacturer();
  if (!$manufacturer->getFromDB((int)$computer->fields['manufacturers_id']) || stripos((string)$manufacturer->fields['name'],'fujitsu')===false) throw new RuntimeException('Fabricant non Fujitsu.');
  $serial=trim((string)$computer->fields['serial']);
  $result=PluginFujitsuwarrantyClient::check($serial);
  if (empty($result['start']) || empty($result['end'])) throw new RuntimeException($result['result']);
  $start=new DateTimeImmutable($result['start']); $end=new DateTimeImmutable($result['end']);
  if ($end<$start) throw new RuntimeException('Dates de garantie incohérentes.');
  // GLPI stores whole months; choose the nearest representable expiry date.
  $base=max(0,((int)$end->format('Y')-(int)$start->format('Y'))*12+(int)$end->format('m')-(int)$start->format('m'));
  $candidates=array_unique([max(0,$base-1),$base,$base+1]);
  $months=0; $closest=null; $best=PHP_INT_MAX;
  foreach ($candidates as $candidate) {
   $candidateDate=$start->modify('+'.$candidate.' months');
   $delta=abs($candidateDate->getTimestamp()-$end->getTimestamp());
   if ($delta<$best) {$best=$delta;$months=$candidate;$closest=$candidateDate;}
  }
  $glpiEnd=$closest->format('Y-m-d');
  $differenceDays=(int)$closest->diff($end)->format('%r%a');
  $info=new Infocom();
  $existing=$info->getFromDBByCrit(['itemtype'=>'Computer','items_id'=>$id]);
  $current=$existing ? $info->fields : [];
  if (!$overwrite && $existing && (!empty($current['warranty_date']) || !empty($current['warranty_duration']))) return ['id'=>$id,'serial'=>$serial,'status'=>'Ignoré : garantie déjà renseignée','start'=>$result['start'],'end'=>$result['end'],'glpi_end'=>$glpiEnd,'difference_days'=>$differenceDays];
  if ($dryRun) return ['id'=>$id,'serial'=>$serial,'status'=>'Simulation : aucune écriture','start'=>$result['start'],'end'=>$result['end'],'duration_months'=>$months,'glpi_end'=>$glpiEnd,'difference_days'=>$differenceDays];
  $input=['warranty_date'=>$result['start'],'warranty_duration'=>$months];
  if ($existing) { $input['id']=(int)$current['id']; $ok=$info->update($input); }
  else { $input['itemtype']='Computer'; $input['items_id']=$id; $ok=$info->add($input); }
  if (!$ok) throw new RuntimeException('Écriture GLPI refusée (droits ou validation).');
  return ['id'=>$id,'serial'=>$serial,'status'=>'Garantie enregistrée','start'=>$result['start'],'end'=>$result['end'],'duration_months'=>$months,'glpi_end'=>$glpiEnd,'difference_days'=>$differenceDays];
 }
}
