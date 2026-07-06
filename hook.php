<?php

function plugin_socfields_install() {
    global $DB;

    include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
    include_once Plugin::getPhpDir('socfields') . '/inc/ticketfield.class.php';

    // ── 1. Cascade field definitions (replaces glpi_plugin_socfields_config) ──
    if (!$DB->tableExists('glpi_plugin_socfields_fields')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socfields_fields` (
                `id`           int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `label_parent` varchar(100) NOT NULL DEFAULT 'Acción Tomada',
                `label_child`  varchar(100) NOT NULL DEFAULT 'Causa Raíz',
                `rank`         int(11) NOT NULL DEFAULT 0,
                `required`     tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        // Migrate from old config table if it exists
        $migrated = false;
        if ($DB->tableExists('glpi_plugin_socfields_config')) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_config', 'LIMIT' => 1]) as $row) {
                $DB->insert('glpi_plugin_socfields_fields', [
                    'label_parent' => $row['parent_label'] ?? 'Acción Tomada',
                    'label_child'  => $row['child_label']  ?? 'Causa Raíz',
                    'rank'         => 0,
                    'required'     => 1,
                ]);
                $migrated = true;
            }
        }
        if (!$migrated) {
            $DB->insert('glpi_plugin_socfields_fields', [
                'label_parent' => 'Acción Tomada',
                'label_child'  => 'Causa Raíz',
                'rank'         => 0,
                'required'     => 1,
            ]);
        }
    }

    // Get first field id for seeding / migration references
    $first_field_id = 1;
    foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_fields', 'ORDER' => ['rank ASC', 'id ASC'], 'LIMIT' => 1]) as $row) {
        $first_field_id = (int) $row['id'];
    }

    // ── 2. Parent options table (add field_id if upgrading) ────────────────────
    if (!$DB->tableExists('glpi_plugin_socfields_parent_options')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socfields_parent_options` (
                `id`       int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_id` int(11) UNSIGNED NOT NULL DEFAULT 1,
                `label`    varchar(100) NOT NULL DEFAULT '',
                `rank`     int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `field_id` (`field_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        // Seed default parents for first field
        $parents_seed = ['Malicious' => 0, 'Not Malicious' => 1, 'Maintenance' => 2, 'Inconclusive' => 3];
        $parent_ids   = [];
        foreach ($parents_seed as $label => $rank) {
            $DB->insert('glpi_plugin_socfields_parent_options', ['field_id' => $first_field_id, 'label' => $label, 'rank' => $rank]);
            $parent_ids[$label] = $DB->insertId();
        }
    } else {
        // Migration: add field_id column if it doesn't exist yet
        if (!$DB->fieldExists('glpi_plugin_socfields_parent_options', 'field_id')) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_socfields_parent_options`
                ADD COLUMN `field_id` int(11) UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
                ADD KEY `field_id` (`field_id`)
            ");
            $DB->doQuery("UPDATE `glpi_plugin_socfields_parent_options` SET `field_id` = " . (int) $first_field_id);
        }
        $parent_ids = [];
    }

    // ── 3. Child options table (structure unchanged) ────────────────────────────
    if (!$DB->tableExists('glpi_plugin_socfields_child_options')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socfields_child_options` (
                `id`        int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `label`     varchar(100) NOT NULL DEFAULT '',
                `rank`      int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `parent_id` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        $children_seed = [
            'Malicious'     => ['Infrastructure issue','Irrelevant TCP/UDP port','Misconfigured system','Other','Similar case is already under investigation','System blocked the attack','System/application malfunction','Unforeseen effects of change','Unknown'],
            'Not Malicious' => ['Employee error','Human error','Lab test','Legit action','Misconfigured system','None','Normal behavior','Other','Penetration test','Rule under construction','Similar case is already under investigation','Unknown','User mistake'],
            'Maintenance'   => ['Internal case','Lab test','Other','Rule under construction'],
            'Inconclusive'  => ['No clear conclusion'],
        ];
        foreach ($children_seed as $parent_label => $children) {
            if (empty($parent_ids[$parent_label])) continue;
            foreach ($children as $rank => $label) {
                $DB->insert('glpi_plugin_socfields_child_options', ['parent_id' => $parent_ids[$parent_label], 'label' => $label, 'rank' => $rank]);
            }
        }
    }

    // ── 4. Ticket values table (replaces glpi_plugin_socfields_tickets) ─────────
    if (!$DB->tableExists('glpi_plugin_socfields_ticket_values')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socfields_ticket_values` (
                `id`           int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `tickets_id`   int(11) UNSIGNED NOT NULL DEFAULT 0,
                `field_id`     int(11) UNSIGNED NOT NULL DEFAULT 1,
                `parent_value` varchar(100) NOT NULL DEFAULT '',
                `child_value`  varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ticket_field` (`tickets_id`, `field_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        // Migrate from old tickets table
        if ($DB->tableExists('glpi_plugin_socfields_tickets')) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_tickets']) as $row) {
                if (!empty($row['parent_value']) || !empty($row['child_value'])) {
                    $DB->insert('glpi_plugin_socfields_ticket_values', [
                        'tickets_id'   => $row['tickets_id'],
                        'field_id'     => $first_field_id,
                        'parent_value' => $row['parent_value'] ?? '',
                        'child_value'  => $row['child_value']  ?? '',
                    ]);
                }
            }
        }
    }

    // ── 5. SOAR close-case webhook config (single row) ──────────────────────────
    if (!$DB->tableExists('glpi_plugin_socfields_webhook_config')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_socfields_webhook_config` (
                `id`               int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `active`           tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
                `url`              text NOT NULL,
                `appkey`           text NOT NULL,
                `comment_template` text NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        $DB->insert('glpi_plugin_socfields_webhook_config', [
            'id'               => 1,
            'active'           => 0,
            'url'              => '',
            'appkey'           => '',
            'comment_template' => 'Cerrado desde GLPI',
        ]);
    }

    return true;
}

function plugin_socfields_uninstall() {
    global $DB;

    foreach ([
        'glpi_plugin_socfields_child_options',
        'glpi_plugin_socfields_parent_options',
        'glpi_plugin_socfields_fields',
        'glpi_plugin_socfields_ticket_values',
        'glpi_plugin_socfields_webhook_config',
        // legacy tables
        'glpi_plugin_socfields_config',
        'glpi_plugin_socfields_tickets',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
