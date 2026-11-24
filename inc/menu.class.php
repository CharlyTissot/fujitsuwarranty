<?php
/** GLPI Assets menu entry for users with computer read permission. */
class PluginFujitsuwarrantyMenu extends CommonGLPI {
    public static function getTypeName($nb = 0) {
        return 'Rapport des garanties';
    }

    public static function getMenuContent() {
        // Keep the navigation entry visible; report.php checks computer READ.
        return [
            'title' => 'Rapport des garanties',
            'page' => Plugin::getWebDir('fujitsuwarranty').'/front/report.php',
            'icon' => 'ti ti-file-analytics',
        ];
    }
}
