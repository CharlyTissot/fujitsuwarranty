<?php
define('PLUGIN_FUJITSUWARRANTY_VERSION','1.2.5');
function plugin_init_fujitsuwarranty() {
 global $PLUGIN_HOOKS;
 $PLUGIN_HOOKS['csrf_compliant']['fujitsuwarranty']=true;
 // Register menu regardless of current session; menu class enforces access rights.
 $PLUGIN_HOOKS['menu_toadd']['fujitsuwarranty']=['tools'=>'PluginFujitsuwarrantyMenu'];
 if (Session::haveRight('config', READ)) $PLUGIN_HOOKS['config_page']['fujitsuwarranty']='front/check.php';
}
function plugin_version_fujitsuwarranty() {
 return ['name'=>'Fujitsu Warranty','version'=>PLUGIN_FUJITSUWARRANTY_VERSION,'author'=>'Charly TISSOT PROXIMIWEB','license'=>'GPLv3+', 'requirements'=>['glpi'=>['min'=>'10.0.0','max'=>'10.0.99'],'php'=>['min'=>'7.4.0']]];
}
function plugin_fujitsuwarranty_check_prerequisites() {
 if (version_compare(GLPI_VERSION,'10.0.0','<') || version_compare(GLPI_VERSION,'10.1.0','>=')) return false;
 return extension_loaded('curl');
}
function plugin_fujitsuwarranty_check_config(){return true;}
