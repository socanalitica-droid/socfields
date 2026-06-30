<?php

class PluginSocfieldsTicketField extends CommonGLPI {

    static $rightname = 'ticket';

    // ── post_item_form hook: render all cascade fields in ticket right panel ──

    static function showInTicketForm(array $params): void {
        static $already_rendered = [];

        $item = $params['item'] ?? null;
        if (!($item instanceof Ticket)) {
            return;
        }

        // post_item_form fires multiple times per page — render only once per ticket
        $tickets_id = (int) $item->getID();
        if (isset($already_rendered[$tickets_id])) {
            return;
        }
        $already_rendered[$tickets_id] = true;

        // Only in the main ticket form, not AJAX sub-forms
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, 'ticket.form.php') === false && strpos($uri, 'tracking.injector.php') === false) {
            $in_show_form = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $frame) {
                if (in_array($frame['function'] ?? '', ['showForm', 'showPrimaryForm'], true)) {
                    $in_show_form = true;
                    break;
                }
            }
            if (!$in_show_form) {
                return;
            }
        }

        include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';

        $all_fields = PluginSocfieldsConfig::getAllFields();
        if (empty($all_fields)) {
            return;
        }

        foreach ($all_fields as $field) {
            $field_id = (int) $field['id'];
            $parents  = PluginSocfieldsConfig::getParentOptionsByField($field_id);
            if (empty($parents)) {
                continue;
            }
            $children_map   = PluginSocfieldsConfig::getChildOptionsByField($field_id);
            $saved          = self::getForTicket($tickets_id, $field_id);
            $saved_pv       = $saved['parent_value'] ?? '';
            $saved_cv       = $saved['child_value']  ?? '';

            $js_cascade       = [];
            $all_child_labels = [];
            foreach ($parents as $parent) {
                $child_list = array_map(fn($c) => $c['label'], $children_map[$parent['id']] ?? []);
                $js_cascade[$parent['label']] = $child_list;
                foreach ($child_list as $cl) {
                    $all_child_labels[] = $cl;
                }
            }

            $parent_label = htmlspecialchars($field['label_parent']);
            $child_label  = htmlspecialchars($field['label_child']);
            $rand         = mt_rand();
            $pid          = 'socf_parent_' . $field_id . '_' . $rand;
            $cid          = 'socf_child_'  . $field_id . '_' . $rand;
            $pname        = 'plugin_socfields_' . $field_id . '_parent';
            $cname        = 'plugin_socfields_' . $field_id . '_child';
            $req_mark     = $field['required'] ? ' <span class="text-danger">*</span>' : '';

            // ── Parent dropdown ──────────────────────────────────────────────
            echo '<div class="form-field row align-items-center col-12 glpi-full-width mb-2">';
            echo '<label for="' . $pid . '" class="col-form-label col-xxl-5 text-xxl-end">' . $parent_label . $req_mark . '</label>';
            echo '<div class="col-xxl-7 field-container">';
            echo '<select id="' . $pid . '" name="' . $pname . '" class="form-select">';
            echo '<option value="">—</option>';
            foreach ($parents as $p) {
                $sel = ($p['label'] === $saved_pv) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($p['label']) . '"' . $sel . '>' . htmlspecialchars($p['label']) . '</option>';
            }
            echo '</select></div></div>';

            // ── Child dropdown ───────────────────────────────────────────────
            echo '<div class="form-field row align-items-center col-12 glpi-full-width mb-2">';
            echo '<label for="' . $cid . '" class="col-form-label col-xxl-5 text-xxl-end">' . $child_label . $req_mark . '</label>';
            echo '<div class="col-xxl-7 field-container">';
            echo '<select id="' . $cid . '" name="' . $cname . '" class="form-select">';
            echo '<option value="">—</option>';
            foreach ($all_child_labels as $cl) {
                $sel = ($cl === $saved_cv) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($cl) . '"' . $sel . '>' . htmlspecialchars($cl) . '</option>';
            }
            echo '</select></div></div>';

            // ── Per-field JS: cascade rebuild ────────────────────────────────
            $cfg_js          = json_encode(['cascade' => $js_cascade, 'all_child' => $all_child_labels, 'saved_pv' => $saved_pv, 'saved_cv' => $saved_cv, 'pid' => $pid, 'cid' => $cid]);
            $parent_label_js = json_encode($parent_label);
            $child_label_js  = json_encode($child_label);
            $required_js     = $field['required'] ? 'true' : 'false';

            echo <<<JS
<script>
(function () {
    var cfg      = {$cfg_js};
    var required = {$required_js};
    var pSel = document.getElementById(cfg.pid);
    var cSel = document.getElementById(cfg.cid);
    if (!pSel || !cSel) return;

    function rebuildChild(keep) {
        var pVal = pSel.tomselect ? pSel.tomselect.getValue() : pSel.value;
        var opts = cfg.cascade[pVal] || cfg.all_child;
        var ts   = cSel.tomselect;
        if (ts) {
            ts.clear(true); ts.clearOptions();
            ts.addOption({value: '', text: '—'});
            opts.forEach(function (o) { ts.addOption({value: o, text: o}); });
            ts.setValue(keep || '', true);
        } else {
            cSel.innerHTML = '<option value="" selected>—</option>';
            opts.forEach(function (o) {
                var el = document.createElement('option');
                el.value = o; el.textContent = o;
                if (o === keep) el.selected = true;
                cSel.appendChild(el);
            });
        }
    }

    function setupCascade() {
        rebuildChild(cfg.saved_cv);
        if (pSel.tomselect) {
            pSel.tomselect.on('change', function () { rebuildChild(''); });
        } else {
            pSel.addEventListener('change', function () { rebuildChild(''); });
        }
    }

    function waitForTS(n) {
        if (pSel.tomselect && cSel.tomselect) { setupCascade(); }
        else if (n > 0) { setTimeout(function () { waitForTS(n - 1); }, 80); }
        else { setupCascade(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { waitForTS(20); });
    } else {
        waitForTS(20);
    }

    if (!required) return;

    // Block submit when status → Resolved/Closed and this required field is empty
    var form = document.getElementById('itil-form');
    if (form && !form.__socfListenerAdded_{$field_id}) {
        form.__socfListenerAdded_{$field_id} = true;
        form.addEventListener('submit', function (e) {
            var statusEl = form.querySelector('[name="status"]');
            if (!statusEl) return;
            var st = parseInt(statusEl.value, 10);
            if (st !== 5 && st !== 6) return;
            var pv = (pSel.tomselect ? pSel.tomselect.getValue() : pSel.value) || '';
            var cv = (cSel.tomselect ? cSel.tomselect.getValue() : cSel.value) || '';
            if (pv && cv) return;
            e.preventDefault(); e.stopImmediatePropagation();
            var msg = 'SOC Classification requerida: selecciona ' + {$parent_label_js} + ' y ' + {$child_label_js} + ' antes de resolver o cerrar el ticket.';
            var banner = document.querySelector('.alerts-container, #message_after_redirect');
            if (banner) {
                var div = document.createElement('div');
                div.className = 'alert alert-danger alert-dismissible fade show';
                div.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                banner.prepend(div);
                banner.scrollIntoView({behavior:'smooth', block:'start'});
            } else { alert(msg); }
        }, true);
    }
})();
</script>
JS;
        }

    }

    // ── pre_item_update: save all SOC fields + validate required on close ─────

    static function preItemUpdate($item): void {
        if (!($item instanceof Ticket)) {
            return;
        }

        include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';

        $tickets_id = (int) $item->getID();
        $all_fields = PluginSocfieldsConfig::getAllFields();

        // Determine the resulting status after this save
        $new_status = (int) ($item->input['status'] ?? $_POST['status'] ?? $item->fields['status'] ?? 0);
        $is_closing = in_array($new_status, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true);

        // Only save SOC values when the ticket is being Resolved or Closed —
        // this prevents the "saved" visual indicator from appearing on other status changes.
        if ($is_closing) {
            foreach ($all_fields as $field) {
                $field_id = (int) $field['id'];
                $pv_key   = 'plugin_socfields_' . $field_id . '_parent';
                $cv_key   = 'plugin_socfields_' . $field_id . '_child';

                $pv = isset($_POST[$pv_key]) ? trim($_POST[$pv_key]) : null;
                $cv = isset($_POST[$cv_key]) ? trim($_POST[$cv_key]) : null;

                if ($pv !== null && $cv !== null && $pv !== '' && $cv !== '') {
                    $valid_children = PluginSocfieldsConfig::getChildLabelsForParent($field_id, $pv);
                    if (in_array($cv, $valid_children, true)) {
                        self::saveForTicket($tickets_id, $field_id, $pv, $cv);
                    }
                }
            }

            // Validate required fields before allowing close
            foreach ($all_fields as $field) {
                if (!$field['required']) {
                    continue;
                }
                $field_id = (int) $field['id'];
                $data     = self::getForTicket($tickets_id, $field_id);
                if (empty($data['parent_value']) || empty($data['child_value'])) {
                    Session::addMessageAfterRedirect(
                        '⚠️ SOC Classification requerida: selecciona "' . $field['label_parent'] . '" y "' . $field['label_child'] . '" antes de resolver o cerrar el ticket.',
                        false,
                        ERROR
                    );
                    $item->input = false;
                    return;
                }
            }
        }
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    static function getForTicket(int $tickets_id, int $field_id): array {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_plugin_socfields_ticket_values', 'WHERE' => ['tickets_id' => $tickets_id, 'field_id' => $field_id], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return [];
    }

    static function saveForTicket(int $tickets_id, int $field_id, string $pv, string $cv): void {
        global $DB;
        $existing = self::getForTicket($tickets_id, $field_id);
        if (empty($existing)) {
            $DB->insert('glpi_plugin_socfields_ticket_values', ['tickets_id' => $tickets_id, 'field_id' => $field_id, 'parent_value' => $pv, 'child_value' => $cv]);
        } else {
            $DB->update('glpi_plugin_socfields_ticket_values', ['parent_value' => $pv, 'child_value' => $cv], ['tickets_id' => $tickets_id, 'field_id' => $field_id]);
        }
    }
}
