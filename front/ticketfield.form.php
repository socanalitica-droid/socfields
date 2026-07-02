<?php

include('../../../inc/includes.php');

header('Content-Type: application/json');

Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

Session::checkCSRF($_POST);

include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
include_once Plugin::getPhpDir('socfields') . '/inc/ticketfield.class.php';

$tickets_id   = (int) ($_POST['tickets_id'] ?? 0);
$field_id     = (int) ($_POST['field_id']   ?? 0);
$parent_value = trim(strip_tags($_POST['parent_value'] ?? ''));
$child_value  = trim(strip_tags($_POST['child_value']  ?? ''));

$ticket = new Ticket();
if (!$tickets_id || !$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado o ticket no encontrado.']);
    exit();
}

$field = PluginSocfieldsConfig::getFieldById($field_id);
if (empty($field)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Campo inválido.']);
    exit();
}

// Incomplete selection → clear any previously saved value for this field
if ($parent_value === '' || $child_value === '') {
    PluginSocfieldsTicketField::clearForTicket($tickets_id, $field_id);
    echo json_encode(['status' => 'ok', 'cleared' => true]);
    exit();
}

$valid_parents = array_column(PluginSocfieldsConfig::getParentOptionsByField($field_id), 'label');
if (!in_array($parent_value, $valid_parents, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valor inválido para el campo padre.']);
    exit();
}

$valid_children = PluginSocfieldsConfig::getChildLabelsForParent($field_id, $parent_value);
if (!in_array($child_value, $valid_children, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'El valor del campo hijo no corresponde a la opción seleccionada en el campo padre.']);
    exit();
}

PluginSocfieldsTicketField::saveForTicket($tickets_id, $field_id, $parent_value, $child_value);

echo json_encode(['status' => 'ok']);
exit();
