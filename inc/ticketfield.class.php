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
            echo '<div class="form-field row align-items-center col-12 glpi-full-width mb-2 socf-field">';
            echo '<label for="' . $pid . '" class="col-form-label col-xxl-5 text-xxl-end">' . $parent_label . $req_mark . '</label>';
            echo '<div class="col-xxl-7 field-container">';
            echo '<select id="' . $pid . '" name="' . $pname . '" class="form-select socf-select">';
            echo '<option value="">—</option>';
            foreach ($parents as $p) {
                $sel = ($p['label'] === $saved_pv) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($p['label']) . '"' . $sel . '>' . htmlspecialchars($p['label']) . '</option>';
            }
            echo '</select></div></div>';

            // ── Child dropdown ───────────────────────────────────────────────
            echo '<div class="form-field row align-items-center col-12 glpi-full-width mb-2 socf-field">';
            echo '<label for="' . $cid . '" class="col-form-label col-xxl-5 text-xxl-end">' . $child_label . $req_mark . '</label>';
            echo '<div class="col-xxl-7 field-container">';
            echo '<select id="' . $cid . '" name="' . $cname . '" class="form-select socf-select">';
            echo '<option value="">—</option>';
            foreach ($all_child_labels as $cl) {
                $sel = ($cl === $saved_cv) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($cl) . '"' . $sel . '>' . htmlspecialchars($cl) . '</option>';
            }
            echo '</select></div></div>';

            // ── Per-field JS: cascade rebuild + AJAX autosave ─────────────────
            $save_url = Plugin::getWebDir('socfields') . '/front/ticketfield.form.php';
            $cfg_js          = json_encode([
                'cascade'    => $js_cascade,
                'all_child'  => $all_child_labels,
                'saved_pv'   => $saved_pv,
                'saved_cv'   => $saved_cv,
                'pid'        => $pid,
                'cid'        => $cid,
                'tickets_id' => $tickets_id,
                'field_id'   => $field_id,
                'save_url'   => $save_url,
                // standalone: independent from the page's single-use $CURRENTCSRFTOKEN,
                // so repeated autosave calls don't invalidate the main ticket/solution forms
                'csrf'       => Session::getNewCSRFToken(true),
            ]);
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

    function currentValues() {
        var pv = (pSel.tomselect ? pSel.tomselect.getValue() : pSel.value) || '';
        var cv = (cSel.tomselect ? cSel.tomselect.getValue() : cSel.value) || '';
        return [pv, cv];
    }

    function autosave() {
        var vals = currentValues();
        var body = new URLSearchParams();
        body.set('tickets_id', cfg.tickets_id);
        body.set('field_id', cfg.field_id);
        body.set('parent_value', vals[0]);
        body.set('child_value', vals[1]);
        fetch(cfg.save_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                // GLPI's kernel-level CSRF listener only preserves (doesn't consume) the
                // token when it recognizes the request as AJAX — via this header, checking
                // X-Glpi-Csrf-Token instead of the POST body. Without it, our repeated
                // autosave calls would consume the shared page token on the first hit.
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': cfg.csrf
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).catch(function () {});
    }

    function setupCascade() {
        rebuildChild(cfg.saved_cv);
        if (pSel.tomselect) {
            pSel.tomselect.on('change', function () { rebuildChild(''); autosave(); });
        } else {
            pSel.addEventListener('change', function () { rebuildChild(''); autosave(); });
        }
        if (cSel.tomselect) {
            cSel.tomselect.on('change', autosave);
        } else {
            cSel.addEventListener('change', autosave);
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

    // Register with the global solution-submit blocker (see socf_style_injected block)
    window.__socfRequiredFields = window.__socfRequiredFields || [];
    window.__socfRequiredFields.push({pSel: pSel, cSel: cSel, parentLabel: {$parent_label_js}, childLabel: {$child_label_js}});

    // Block submit when status → Closed and this required field is empty
    var form = document.getElementById('itil-form');
    if (form && !form.__socfListenerAdded_{$field_id}) {
        form.__socfListenerAdded_{$field_id} = true;
        form.addEventListener('submit', function (e) {
            var statusEl = form.querySelector('[name="status"]');
            if (!statusEl) return;
            var st = parseInt(statusEl.value, 10);
            if (st !== 6) return;
            var pv = (pSel.tomselect ? pSel.tomselect.getValue() : pSel.value) || '';
            var cv = (cSel.tomselect ? cSel.tomselect.getValue() : cSel.value) || '';
            if (pv && cv) return;
            e.preventDefault(); e.stopImmediatePropagation();
            var msg = 'SOC Classification requerida: selecciona ' + {$parent_label_js} + ' y ' + {$child_label_js} + ' antes de cerrar el ticket.';
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

        // ── CSS + JS: strip Bootstrap is-valid (green checkmark) — emitted once ──
        if (!isset($GLOBALS['socf_style_injected'])) {
            $GLOBALS['socf_style_injected'] = true;
            echo <<<'HTML'
<style>
.socf-field .ts-wrapper.is-valid,
.socf-field .ts-wrapper.is-valid .ts-control,
form.was-validated .socf-field .socf-select:valid,
.socf-field .socf-select.is-valid {
    border-color: var(--tblr-border-color, #dee2e6) !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l4 4 4-4'/%3e%3c/svg%3e") !important;
    box-shadow: none !important;
}
</style>
<script>
(function () {
    function stripValid() {
        document.querySelectorAll('.socf-field .ts-wrapper').forEach(function (w) {
            w.classList.remove('is-valid');
        });
    }
    document.addEventListener('submit', function () { setTimeout(stripValid, 0); }, true);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', stripValid);
    } else {
        stripValid();
    }
    new MutationObserver(stripValid).observe(document.body, { subtree: true, attributeFilter: ['class'] });

    // Block "Add solution" submit client-side when required SOC fields aren't filled —
    // stops the browser navigating to itilsolution.form.php at all, so the request
    // never reaches the server-side pre_item_add guard (which only exists as a backstop).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var action = form && form.getAttribute ? (form.getAttribute('action') || '') : '';
        if (!action || action.indexOf('itilsolution.form.php') === -1) return;

        var missing = [];
        (window.__socfRequiredFields || []).forEach(function (f) {
            var pv = (f.pSel.tomselect ? f.pSel.tomselect.getValue() : f.pSel.value) || '';
            var cv = (f.cSel.tomselect ? f.cSel.tomselect.getValue() : f.cSel.value) || '';
            if (!pv || !cv) {
                missing.push(f.parentLabel + ' / ' + f.childLabel);
            }
        });
        if (!missing.length) return;

        e.preventDefault();
        e.stopImmediatePropagation();
        var div = document.createElement('div');
        div.className = 'alert alert-warning d-flex align-items-center gap-2 mb-2';
        div.innerHTML = '<i class="ti ti-alert-triangle"></i><span>SOC Classification requerida: selecciona ' +
            missing.join(', ') + ' antes de dar la solución.</span>';
        form.prepend(div);
        form.scrollIntoView({behavior: 'smooth', block: 'center'});
    }, true);
})();
</script>
HTML;
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
        $is_closing = ($new_status === CommonITILObject::CLOSED);
        // $item->fields still holds the pre-update DB row at this point in CommonDBTM::update() —
        // used to fire the SOAR webhook only on the actual open→closed transition, not on
        // every subsequent edit of an already-closed ticket.
        $was_closed = ((int) ($item->fields['status'] ?? 0) === CommonITILObject::CLOSED);

        // Only save SOC values when the ticket is being Closed —
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
                        '⚠️ SOC Classification requerida: selecciona "' . $field['label_parent'] . '" y "' . $field['label_child'] . '" antes de cerrar el ticket.',
                        false,
                        ERROR
                    );
                    $item->input = false;
                    return;
                }
            }

            if (!$was_closed) {
                self::sendCloseCaseWebhook($tickets_id);
            }
        }
    }

    // ── SOAR close-case webhook ─────────────────────────────────────────────────

    static function getCaseId(int $tickets_id): string {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_fields_ticketsecops')) {
            return '';
        }
        foreach ($DB->request(['FROM' => 'glpi_plugin_fields_ticketsecops', 'WHERE' => ['items_id' => $tickets_id], 'LIMIT' => 1]) as $row) {
            $case_id = trim($row['caseidfield'] ?? '');
            return (strtoupper($case_id) === 'NA') ? '' : $case_id;
        }
        return '';
    }

    static function sendCloseCaseWebhook(int $tickets_id): void {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_socfields_webhook_config')) {
            return;
        }

        include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
        $cfg = PluginSocfieldsConfig::getWebhookConfig();
        if (empty($cfg['active']) || empty($cfg['url']) || empty($cfg['appkey'])) {
            return;
        }

        $case_id = self::getCaseId($tickets_id);
        if ($case_id === '') {
            return;
        }

        // Reason/root cause come from the first configured cascade field pair.
        $all_fields = PluginSocfieldsConfig::getAllFields();
        if (empty($all_fields)) {
            return;
        }
        $data       = self::getForTicket($tickets_id, (int) $all_fields[0]['id']);
        $reason     = $data['parent_value'] ?? '';
        $root_cause = $data['child_value']  ?? '';
        if ($reason === '' || $root_cause === '') {
            return;
        }

        // Siemplify's CloseCase API validates "reason" against a fixed enum
        // (Malicious, NotMalicious, Maintenance, Inconclusive — no spaces), while
        // "rootCause" accepts free text. Our dropdown label is "Not Malicious"
        // (with a space) so it must be collapsed or the API rejects the call
        // with a 400 "condition was not met for 'Reason'" error.
        $reason = str_replace(' ', '', $reason);

        $payload = json_encode([
            'caseId'    => $case_id,
            'reason'    => $reason,
            'rootCause' => $root_cause,
            'comment'   => $cfg['comment_template'] ?: 'Cerrado desde GLPI',
        ]);

        $ch = curl_init($cfg['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'AppKey: ' . $cfg['appkey'],
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }

    // ── pre_item_add (ITILSolution): block giving a solution until required SOC fields are saved ──

    static function preSolutionAdd($item): void {
        $tickets_id = 0;
        if (($item->input['itemtype'] ?? '') === 'Ticket') {
            $tickets_id = (int) ($item->input['items_id'] ?? 0);
        }
        if (!$tickets_id) {
            return;
        }

        include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
        $all_fields = PluginSocfieldsConfig::getAllFields();

        foreach ($all_fields as $field) {
            if (!$field['required']) {
                continue;
            }
            $field_id = (int) $field['id'];
            $data     = self::getForTicket($tickets_id, $field_id);
            if (empty($data['parent_value']) || empty($data['child_value'])) {
                Session::addMessageAfterRedirect(
                    '⚠️ SOC Classification requerida: selecciona "' . $field['label_parent'] . '" y "' . $field['label_child'] . '" antes de dar la solución.',
                    false,
                    ERROR
                );
                $item->input = false;
                return;
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

    static function clearForTicket(int $tickets_id, int $field_id): void {
        global $DB;
        $DB->delete('glpi_plugin_socfields_ticket_values', ['tickets_id' => $tickets_id, 'field_id' => $field_id]);
    }
}
