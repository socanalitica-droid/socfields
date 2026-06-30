<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['save']) || empty($_POST['tickets_id'])) {
    Html::back();
    exit();
}

include_once Plugin::getPhpDir('socfields') . '/inc/config.class.php';
include_once Plugin::getPhpDir('socfields') . '/inc/ticketfield.class.php';

$tickets_id   = (int) $_POST['tickets_id'];
$parent_value = trim(strip_tags($_POST['parent_value'] ?? ''));
$child_value  = trim(strip_tags($_POST['child_value']  ?? ''));

// Validate against current DB options
$valid_parents = PluginSocfieldsConfig::getAllParentLabels();
$valid_children = PluginSocfieldsConfig::getChildLabelsForParent($parent_value);

if (!in_array($parent_value, $valid_parents, true)) {
    Session::addMessageAfterRedirect('Valor inválido para el campo 1.', false, ERROR);
    Html::back();
    exit();
}

if (!in_array($child_value, $valid_children, true)) {
    Session::addMessageAfterRedirect('El valor del campo 2 no corresponde a la opción seleccionada en el campo 1.', false, ERROR);
    Html::back();
    exit();
}

// Verify ticket access
$ticket = new Ticket();
if (!$ticket->getFromDB($tickets_id) || !$ticket->canUpdateItem()) {
    Session::addMessageAfterRedirect('Acceso denegado o ticket no encontrado.', false, ERROR);
    Html::back();
    exit();
}

PluginSocfieldsTicketField::saveForTicket($tickets_id, $parent_value, $child_value);

Session::addMessageAfterRedirect('Clasificación SOC guardada correctamente.', false, INFO);

Html::redirect(
    Ticket::getFormURL() . '?id=' . $tickets_id . '&forcetab=PluginSocfieldsTicketField$1'
);
exit();
