<?php

define('PLUGIN_SOCFIELDS_VERSION', '1.2.0');
define('PLUGIN_SOCFIELDS_MIN_GLPI', '11.0');
define('PLUGIN_SOCFIELDS_MAX_GLPI', '12.0');

function plugin_init_socfields() {
    global $PLUGIN_HOOKS;

    include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
    include_once Plugin::getPhpDir('socfields') . '/inc/ticketfield.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['socfields'] = true;
    $PLUGIN_HOOKS['config_page']['socfields']    = 'front/config.form.php';

    if (isset($_SESSION['glpiactiveentities'])) {
        // Admin menu under Setup (same mechanism as Additional Fields)
        $PLUGIN_HOOKS['menu_toadd']['socfields'] = ['config' => 'PluginSocfieldsConfig'];

        // Inject SOC dropdowns inline in the ticket right panel
        $PLUGIN_HOOKS['post_item_form']['socfields'] = [
            'PluginSocfieldsTicketField',
            'showInTicketForm',
        ];
    }

    // Block ticket from being resolved/closed without SOC fields + save values
    // GLPI 11: doHook dispatches object hooks by itemtype key — must be ['Ticket' => callable]
    $PLUGIN_HOOKS['pre_item_update']['socfields'] = [
        'Ticket' => ['PluginSocfieldsTicketField', 'preItemUpdate'],
    ];
}

function plugin_version_socfields() {
    return [
        'name'         => 'SOC Classification Fields',
        'version'      => PLUGIN_SOCFIELDS_VERSION,
        'author'       => 'SOC Team - Linktic',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SOCFIELDS_MIN_GLPI,
                'max' => PLUGIN_SOCFIELDS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_socfields_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_SOCFIELDS_MIN_GLPI, 'lt')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_SOCFIELDS_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_socfields_check_config() {
    return true;
}
