<?php

class PluginSocfieldsConfig extends CommonGLPI {

    static $rightname = 'entity';

    static function getTypeName($nb = 0) { return 'SOC Classification Fields'; }
    static function getMenuName()        { return 'SOC Fields'; }

    static function getMenuContent() {
        if (!Session::haveRight('entity', READ)) {
            return false;
        }
        $front = Plugin::getPhpDir('socfields', false) . '/front';
        return ['title' => self::getMenuName(), 'page' => "$front/config.form.php", 'icon' => 'ti ti-shield-check'];
    }

    // ── Field definitions ─────────────────────────────────────────────────────

    static function getAllFields(): array {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_fields', 'ORDER' => ['rank ASC', 'id ASC']]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    static function getFieldById(int $field_id): array {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_fields', 'WHERE' => ['id' => $field_id], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return [];
    }

    // ── Parent options ────────────────────────────────────────────────────────

    static function getParentOptionsByField(int $field_id): array {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_parent_options', 'WHERE' => ['field_id' => $field_id], 'ORDER' => ['rank ASC', 'id ASC']]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    // ── Child options — returns map: parent_id → [child_rows] ────────────────

    static function getChildOptionsByField(int $field_id): array {
        global $DB;
        $parent_ids = array_column(self::getParentOptionsByField($field_id), 'id');
        if (empty($parent_ids)) {
            return [];
        }
        $map = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_child_options', 'WHERE' => ['parent_id' => $parent_ids], 'ORDER' => ['parent_id ASC', 'rank ASC', 'id ASC']]) as $row) {
            $map[$row['parent_id']][] = $row;
        }
        return $map;
    }

    // Returns child labels valid for a given parent label within a field
    static function getChildLabelsForParent(int $field_id, string $parent_label): array {
        global $DB;
        $parent = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_socfields_parent_options', 'WHERE' => ['field_id' => $field_id, 'label' => $parent_label], 'LIMIT' => 1]) as $row) {
            $parent = $row;
        }
        if (empty($parent)) {
            return [];
        }
        $labels = [];
        foreach ($DB->request(['SELECT' => ['label'], 'FROM' => 'glpi_plugin_socfields_child_options', 'WHERE' => ['parent_id' => $parent['id']]]) as $row) {
            $labels[] = $row['label'];
        }
        return $labels;
    }

    // ── SOAR close-case webhook config ───────────────────────────────────────

    static function getWebhookConfig(): array {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_webhook_config', 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return [];
    }

    // Never overwrites appkey with an empty value — leave the field blank in the
    // form to keep the currently stored key unchanged (so it never round-trips
    // back into rendered HTML for anyone to read from the page source).
    static function saveWebhookConfig(array $input): void {
        global $DB;
        $data = [
            'active'           => isset($input['active']) ? 1 : 0,
            'url'              => trim($input['url'] ?? ''),
            'comment_template' => trim($input['comment_template'] ?? '') ?: 'Cerrado desde GLPI',
        ];
        $appkey = trim($input['appkey'] ?? '');
        if ($appkey !== '') {
            $data['appkey'] = $appkey;
        }
        $DB->update('glpi_plugin_socfields_webhook_config', $data, ['id' => 1]);
    }

    // ── Save from admin form ──────────────────────────────────────────────────

    static function saveFromPost(array $post): void {
        global $DB;

        $fields_post   = $post['field']  ?? [];
        $parents_post  = $post['parent'] ?? [];
        $children_post = $post['child']  ?? [];

        $submitted_field_ids = [];
        $field_db_ids        = []; // fidx → db_id

        // 1. Upsert cascade field definitions
        foreach ($fields_post as $fidx => $fdata) {
            $lp = trim($fdata['label_parent'] ?? '');
            $lc = trim($fdata['label_child']  ?? '');
            if ($lp === '' || $lc === '') {
                continue;
            }
            $existing_id = (int)($fdata['id'] ?? 0);
            $required    = isset($fdata['required']) ? 1 : 0;

            if ($existing_id > 0) {
                $DB->update('glpi_plugin_socfields_fields', ['label_parent' => $lp, 'label_child' => $lc, 'rank' => (int) $fidx, 'required' => $required], ['id' => $existing_id]);
                $db_id = $existing_id;
            } else {
                $DB->insert('glpi_plugin_socfields_fields', ['label_parent' => $lp, 'label_child' => $lc, 'rank' => (int) $fidx, 'required' => $required]);
                $db_id = $DB->insertId();
            }
            $field_db_ids[$fidx]   = $db_id;
            $submitted_field_ids[] = $db_id;
        }

        // Delete removed fields
        $all_field_ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_socfields_fields']) as $row) {
            $all_field_ids[] = (int) $row['id'];
        }
        foreach (array_diff($all_field_ids, $submitted_field_ids) as $del_id) {
            $pids = array_column(self::getParentOptionsByField($del_id), 'id');
            if ($pids) {
                $DB->doQuery("DELETE FROM `glpi_plugin_socfields_child_options` WHERE `parent_id` IN (" . implode(',', array_map('intval', $pids)) . ")");
            }
            $DB->doQuery("DELETE FROM `glpi_plugin_socfields_parent_options` WHERE `field_id` = " . (int) $del_id);
            $DB->doQuery("DELETE FROM `glpi_plugin_socfields_fields` WHERE `id` = " . (int) $del_id);
        }

        // 2. Upsert parents + children per field
        foreach ($parents_post as $fidx => $parents) {
            if (!isset($field_db_ids[$fidx])) {
                continue;
            }
            $field_db_id          = $field_db_ids[$fidx];
            $submitted_parent_ids = [];
            $parent_db_ids        = []; // pidx → db_id

            foreach ($parents as $pidx => $pdata) {
                $label = trim($pdata['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $existing_id = (int)($pdata['id'] ?? 0);
                if ($existing_id > 0) {
                    $DB->update('glpi_plugin_socfields_parent_options', ['label' => $label, 'rank' => (int) $pidx], ['id' => $existing_id]);
                    $db_id = $existing_id;
                } else {
                    $DB->insert('glpi_plugin_socfields_parent_options', ['field_id' => $field_db_id, 'label' => $label, 'rank' => (int) $pidx]);
                    $db_id = $DB->insertId();
                }
                $parent_db_ids[$pidx]   = $db_id;
                $submitted_parent_ids[] = $db_id;
            }

            // Delete removed parents
            $all_pids = array_column(self::getParentOptionsByField($field_db_id), 'id');
            foreach (array_diff($all_pids, $submitted_parent_ids) as $del_pid) {
                $DB->doQuery("DELETE FROM `glpi_plugin_socfields_child_options` WHERE `parent_id` = " . (int) $del_pid);
                $DB->doQuery("DELETE FROM `glpi_plugin_socfields_parent_options` WHERE `id` = " . (int) $del_pid);
            }

            // Upsert children
            $children_for_field   = $children_post[$fidx] ?? [];
            $submitted_child_by_p = []; // parent_db_id → [child_db_ids]

            foreach ($children_for_field as $cidx => $cdata) {
                $label      = trim($cdata['label'] ?? '');
                $parent_idx = (int)($cdata['parent_idx'] ?? -1);
                if ($label === '' || !isset($parent_db_ids[$parent_idx])) {
                    continue;
                }
                $parent_db_id = $parent_db_ids[$parent_idx];
                $existing_cid = (int)($cdata['id'] ?? 0);
                if ($existing_cid > 0) {
                    $DB->update('glpi_plugin_socfields_child_options', ['label' => $label, 'rank' => (int) $cidx, 'parent_id' => $parent_db_id], ['id' => $existing_cid]);
                    $submitted_child_by_p[$parent_db_id][] = $existing_cid;
                } else {
                    $DB->insert('glpi_plugin_socfields_child_options', ['parent_id' => $parent_db_id, 'label' => $label, 'rank' => (int) $cidx]);
                    $submitted_child_by_p[$parent_db_id][] = $DB->insertId();
                }
            }

            foreach ($parent_db_ids as $parent_db_id) {
                $kept = $submitted_child_by_p[$parent_db_id] ?? [];
                if (empty($kept)) {
                    $DB->doQuery("DELETE FROM `glpi_plugin_socfields_child_options` WHERE `parent_id` = " . (int) $parent_db_id);
                } else {
                    $not_in = implode(',', array_map('intval', $kept));
                    $DB->doQuery("DELETE FROM `glpi_plugin_socfields_child_options` WHERE `parent_id` = " . (int) $parent_db_id . " AND `id` NOT IN ($not_in)");
                }
            }
        }
    }
}
