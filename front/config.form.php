<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    PluginSocfieldsConfig::saveFromPost($_POST);
    PluginSocfieldsConfig::saveWebhookConfig($_POST['webhook'] ?? []);
    Session::addMessageAfterRedirect('Configuración guardada correctamente.', true, INFO);
    Html::back();
    exit();
}

Html::header('SOC Fields — Configuración', $_SERVER['REQUEST_URI'], 'config', 'PluginSocfieldsConfig');

$fields      = PluginSocfieldsConfig::getAllFields();
$webhook_cfg = PluginSocfieldsConfig::getWebhookConfig();

// Count existing children across all fields (for JS counter init)
$total_child_count = 0;
$child_counts      = []; // fidx → child_count for JS
foreach ($fields as $fidx => $field) {
    $children_map = PluginSocfieldsConfig::getChildOptionsByField((int) $field['id']);
    $cnt = 0;
    foreach ($children_map as $list) { $cnt += count($list); }
    $child_counts[$fidx] = $cnt;
    $total_child_count  += $cnt;
}

?>
<div class="container-fluid mt-3" style="max-width:900px">

  <!-- ── Header ──────────────────────────────────────────────────────── -->
  <div class="card mb-4">
    <div class="card-header fw-bold d-flex align-items-center justify-content-between">
      <span><i class="ti ti-shield-check me-2"></i>SOC Classification Fields — Configuración</span>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="socfAddField()">
        <i class="ti ti-plus me-1"></i>Nuevo par en cascada
      </button>
    </div>
    <div class="card-body pb-1">
      <p class="text-muted small mb-0">
        Cada <strong>par en cascada</strong> genera dos dropdowns en el ticket (padre → hijo).
        Puedes agregar cuantos pares necesites. Los marcados como <strong>Requerido</strong>
        bloquean el cierre del ticket si no están seleccionados.
      </p>
    </div>
  </div>

  <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken()) ?>">

    <!-- ── Webhook cierre de caso (SOAR) ──────────────────────────────── -->
    <div class="card mb-4 border-dark">
      <div class="card-header bg-dark bg-opacity-10 d-flex align-items-center gap-2">
        <i class="ti ti-webhook"></i>
        <span class="fw-bold">Webhook — Cierre de caso en SOAR</span>
        <div class="form-check form-switch ms-auto mb-0">
          <input class="form-check-input" type="checkbox" name="webhook[active]" value="1"
                 id="webhook_active" <?= !empty($webhook_cfg['active']) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="webhook_active">Activo</label>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted small">
          Cuando un ticket pasa a <strong>Cerrado</strong> con la clasificación SOC diligenciada,
          se envía automáticamente este cierre al SOAR (reemplaza al Webhook nativo de GLPI para
          evitar valores fijos como "NotMalicious").
        </p>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold small text-muted text-uppercase">URL</label>
            <input type="text" name="webhook[url]" class="form-control"
                   value="<?= htmlspecialchars($webhook_cfg['url'] ?? '') ?>"
                   placeholder="https://.../api/external/v1/cases/CloseCase">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small text-muted text-uppercase">AppKey</label>
            <input type="password" name="webhook[appkey]" class="form-control"
                   value="" autocomplete="new-password"
                   placeholder="<?= !empty($webhook_cfg['appkey']) ? '•••••••• (ya configurada — deja en blanco para no cambiarla)' : 'Ej: 17f529ab-3506-...' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold small text-muted text-uppercase">Comentario</label>
            <input type="text" name="webhook[comment_template]" class="form-control"
                   value="<?= htmlspecialchars($webhook_cfg['comment_template'] ?? 'Cerrado desde GLPI') ?>">
          </div>
        </div>
      </div>
    </div>

    <div id="socf-fields-container">
<?php
foreach ($fields as $fidx => $field):
    $field_id    = (int) $field['id'];
    $parents     = PluginSocfieldsConfig::getParentOptionsByField($field_id);
    $children_map= PluginSocfieldsConfig::getChildOptionsByField($field_id);
    $child_cnt   = $child_counts[$fidx];
    $colors      = ['primary','success','warning','danger','info','secondary'];
    $color       = $colors[$fidx % count($colors)];
?>
      <!-- Field <?= $fidx ?> -->
      <div class="card mb-4 border-<?= $color ?> socf-field-block" data-fidx="<?= $fidx ?>">
        <!-- Field header -->
        <div class="card-header bg-<?= $color ?> bg-opacity-10 d-flex align-items-center gap-2 flex-wrap">
          <i class="ti ti-layout-list text-<?= $color ?>"></i>
          <span class="fw-bold text-<?= $color ?>">Par en cascada #<?= $fidx + 1 ?></span>

          <input type="hidden" name="field[<?= $fidx ?>][id]" value="<?= $field_id ?>">

          <!-- Required toggle -->
          <div class="form-check form-switch ms-auto mb-0 me-2">
            <input class="form-check-input" type="checkbox"
                   name="field[<?= $fidx ?>][required]" value="1"
                   id="field_req_<?= $fidx ?>"
                   <?= $field['required'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="field_req_<?= $fidx ?>">Requerido</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger"
                  onclick="socfRemoveField(this)" title="Eliminar este par">
            <i class="ti ti-trash"></i>
          </button>
        </div>

        <!-- Label inputs -->
        <div class="card-body border-bottom pb-3">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-muted text-uppercase">Label Dropdown 1 (padre)</label>
              <input type="text" name="field[<?= $fidx ?>][label_parent]" class="form-control"
                     value="<?= htmlspecialchars($field['label_parent']) ?>" placeholder='Ej: "Acción Tomada"' required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-muted text-uppercase">Label Dropdown 2 (hijo/cascada)</label>
              <input type="text" name="field[<?= $fidx ?>][label_child]" class="form-control"
                     value="<?= htmlspecialchars($field['label_child']) ?>" placeholder='Ej: "Causa Raíz"' required>
            </div>
          </div>
        </div>

        <!-- Options editor -->
        <div class="card-body pt-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-semibold small text-muted text-uppercase">Opciones en cascada</span>
            <button type="button" class="btn btn-sm btn-outline-primary"
                    onclick="socfAddParent(<?= $fidx ?>)">
              <i class="ti ti-plus me-1"></i>Agregar opción padre
            </button>
          </div>

          <div id="socf-parents-<?= $fidx ?>">
<?php
$child_counter = 0;
foreach ($parents as $pidx => $parent):
    $parent_children = $children_map[$parent['id']] ?? [];
?>
            <div class="card mb-3 border-secondary parent-block" data-pidx="<?= $pidx ?>">
              <div class="card-header bg-secondary bg-opacity-10 d-flex align-items-center gap-2">
                <i class="ti ti-tag text-secondary"></i>
                <input type="hidden" name="parent[<?= $fidx ?>][<?= $pidx ?>][id]" value="<?= (int) $parent['id'] ?>">
                <input type="text" name="parent[<?= $fidx ?>][<?= $pidx ?>][label]"
                       class="form-control form-control-sm fw-bold"
                       value="<?= htmlspecialchars($parent['label']) ?>"
                       placeholder="Opción padre" required style="max-width:300px">
                <span class="ms-auto">
                  <button type="button" class="btn btn-sm btn-outline-danger"
                          onclick="socfRemoveParent(this)">
                    <i class="ti ti-trash"></i>
                  </button>
                </span>
              </div>
              <div class="card-body pb-2">
                <p class="text-muted small mb-2">Opciones del dropdown 2 cuando se selecciona este valor:</p>
                <div class="children-container ms-3">
<?php foreach ($parent_children as $child):
    $cc = $child_counter++;
?>
                  <div class="d-flex align-items-center gap-2 mb-2 child-row">
                    <i class="ti ti-corner-down-right text-muted"></i>
                    <input type="hidden" name="child[<?= $fidx ?>][<?= $cc ?>][id]" value="<?= (int) $child['id'] ?>">
                    <input type="hidden" name="child[<?= $fidx ?>][<?= $cc ?>][parent_idx]" value="<?= $pidx ?>">
                    <input type="text" name="child[<?= $fidx ?>][<?= $cc ?>][label]"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($child['label']) ?>"
                           placeholder="Opción hijo" required style="max-width:280px">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="socfRemoveChild(this)">
                      <i class="ti ti-x"></i>
                    </button>
                  </div>
<?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-4 mb-1"
                        onclick="socfAddChild(this, <?= $fidx ?>, <?= $pidx ?>)">
                  <i class="ti ti-plus me-1"></i>Agregar hijo
                </button>
              </div>
            </div>
<?php endforeach; ?>
          </div><!-- #socf-parents-{fidx} -->
        </div><!-- .card-body options -->
      </div><!-- .socf-field-block -->
<?php endforeach; ?>
    </div><!-- #socf-fields-container -->

    <hr>
    <div class="text-end mb-5">
      <button type="submit" name="save" class="btn btn-primary">
        <i class="ti ti-device-floppy me-1"></i>Guardar configuración
      </button>
    </div>
  </form>
</div>

<script>
var socfFieldCount  = <?= count($fields) ?>;
var socfParentCount = <?= json_encode(array_values($fields ? array_map(fn($f, $fi) => count(PluginSocfieldsConfig::getParentOptionsByField((int)$f['id'])), $fields, array_keys($fields)) : [])) ?>;
var socfChildCount  = <?= json_encode(array_values($child_counts)) ?>;
var socfColors      = ['primary','success','warning','danger','info','secondary'];

// ── Add a new cascade field pair ───────────────────────────────────────────
function socfAddField() {
    var fidx  = socfFieldCount++;
    var color = socfColors[fidx % socfColors.length];
    socfParentCount[fidx] = 0;
    socfChildCount[fidx]  = 0;

    var html = `
      <div class="card mb-4 border-${color} socf-field-block" data-fidx="${fidx}">
        <div class="card-header bg-${color} bg-opacity-10 d-flex align-items-center gap-2 flex-wrap">
          <i class="ti ti-layout-list text-${color}"></i>
          <span class="fw-bold text-${color}">Par en cascada #${fidx + 1}</span>
          <input type="hidden" name="field[${fidx}][id]" value="0">
          <div class="form-check form-switch ms-auto mb-0 me-2">
            <input class="form-check-input" type="checkbox"
                   name="field[${fidx}][required]" value="1"
                   id="field_req_${fidx}" checked>
            <label class="form-check-label small" for="field_req_${fidx}">Requerido</label>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="socfRemoveField(this)">
            <i class="ti ti-trash"></i>
          </button>
        </div>
        <div class="card-body border-bottom pb-3">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-muted text-uppercase">Label Dropdown 1 (padre)</label>
              <input type="text" name="field[${fidx}][label_parent]" class="form-control"
                     placeholder='Ej: "Tipo de Incidente"' required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-muted text-uppercase">Label Dropdown 2 (hijo/cascada)</label>
              <input type="text" name="field[${fidx}][label_child]" class="form-control"
                     placeholder='Ej: "Subtipo"' required>
            </div>
          </div>
        </div>
        <div class="card-body pt-3">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-semibold small text-muted text-uppercase">Opciones en cascada</span>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="socfAddParent(${fidx})">
              <i class="ti ti-plus me-1"></i>Agregar opción padre
            </button>
          </div>
          <div id="socf-parents-${fidx}"></div>
        </div>
      </div>`;
    document.getElementById('socf-fields-container').insertAdjacentHTML('beforeend', html);
}

function socfRemoveField(btn) {
    btn.closest('.socf-field-block').remove();
}

// ── Parent options ─────────────────────────────────────────────────────────
function socfAddParent(fidx) {
    if (!socfParentCount[fidx]) socfParentCount[fidx] = 0;
    var pidx = socfParentCount[fidx]++;
    var html = `
      <div class="card mb-3 border-secondary parent-block" data-pidx="${pidx}">
        <div class="card-header bg-secondary bg-opacity-10 d-flex align-items-center gap-2">
          <i class="ti ti-tag text-secondary"></i>
          <input type="hidden" name="parent[${fidx}][${pidx}][id]" value="0">
          <input type="text" name="parent[${fidx}][${pidx}][label]"
                 class="form-control form-control-sm fw-bold"
                 placeholder="Opción padre" required style="max-width:300px">
          <span class="ms-auto">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="socfRemoveParent(this)">
              <i class="ti ti-trash"></i>
            </button>
          </span>
        </div>
        <div class="card-body pb-2">
          <p class="text-muted small mb-2">Opciones del dropdown 2 cuando se selecciona este valor:</p>
          <div class="children-container ms-3"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary ms-4 mb-1"
                  onclick="socfAddChild(this, ${fidx}, ${pidx})">
            <i class="ti ti-plus me-1"></i>Agregar hijo
          </button>
        </div>
      </div>`;
    document.getElementById('socf-parents-' + fidx).insertAdjacentHTML('beforeend', html);
}

function socfRemoveParent(btn) {
    btn.closest('.parent-block').remove();
}

// ── Child options ──────────────────────────────────────────────────────────
function socfAddChild(btn, fidx, pidx) {
    if (!socfChildCount[fidx]) socfChildCount[fidx] = 0;
    var cidx = socfChildCount[fidx]++;
    var html = `
      <div class="d-flex align-items-center gap-2 mb-2 child-row">
        <i class="ti ti-corner-down-right text-muted"></i>
        <input type="hidden" name="child[${fidx}][${cidx}][id]" value="0">
        <input type="hidden" name="child[${fidx}][${cidx}][parent_idx]" value="${pidx}">
        <input type="text" name="child[${fidx}][${cidx}][label]"
               class="form-control form-control-sm"
               placeholder="Opción hijo" required style="max-width:280px">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="socfRemoveChild(this)">
          <i class="ti ti-x"></i>
        </button>
      </div>`;
    btn.previousElementSibling.insertAdjacentHTML('beforeend', html);
}

function socfRemoveChild(btn) {
    btn.closest('.child-row').remove();
}
</script>

<?php Html::footer(); ?>
