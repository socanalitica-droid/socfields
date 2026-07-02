# SOC Classification Fields — GLPI Plugin

Plugin para GLPI 11 desarrollado por el **SOC Team de Linktic**. Agrega campos de clasificación en cascada directamente en el panel lateral del ticket, con validación que bloquea el cierre del ticket si los campos requeridos no están diligenciados.

## Funcionalidades

- **Dropdowns en cascada** configurables desde el panel de administración (ej: "Acción Tomada" → "Causa Raíz")
- **Múltiples pares de campos** — el admin puede agregar cuantos pares en cascada necesite
- **Campo requerido** — cada par puede marcarse como obligatorio; si lo está, el ticket no se puede Cerrar hasta que estén seleccionados
- **Integración nativa con GLPI 11** — usa el mismo CSS del panel lateral y los dropdowns Tom Select del core
- **Opciones por defecto** precargadas: Malicious, Not Malicious, Maintenance, Inconclusive (con sus hijos)

## Compatibilidad

| GLPI | Plugin |
|------|--------|
| ~11.0 | 1.0.0 |

## Instalación

### Opción 1 — Desde repositorio

```bash
cd /var/www/glpi/plugins
git clone https://github.com/socanalitica-droid/socfields.git
```

### Opción 2 — Manual

1. Descarga o clona este repositorio
2. Copia la carpeta `socfields/` dentro de `/var/www/glpi/plugins/`
3. En GLPI: **Configuración → Plugins → SOC Classification Fields → Instalar → Activar**

## Estructura

```
socfields/
├── setup.php                  # Registro de hooks y permisos
├── hook.php                   # Install / uninstall (crea/migra tablas DB)
├── inc/
│   ├── config.class.php       # CRUD de campos, opciones padre/hijo
│   └── ticketfield.class.php  # Render en ticket + validación pre-cierre
└── front/
    └── config.form.php        # UI de configuración (Bootstrap 5 cards)
```

## Tablas de base de datos

| Tabla | Descripción |
|-------|-------------|
| `glpi_plugin_socfields_fields` | Definición de cada par en cascada (label padre, label hijo, requerido) |
| `glpi_plugin_socfields_parent_options` | Opciones del dropdown padre por campo |
| `glpi_plugin_socfields_child_options` | Opciones del dropdown hijo por opción padre |
| `glpi_plugin_socfields_ticket_values` | Valores guardados por ticket y campo |

## Configuración

1. Ve a **Plugins → SOC Fields** en el menú de GLPI
2. Usa **"Nuevo par en cascada"** para agregar campos
3. Define el label del dropdown padre y del hijo
4. Agrega las opciones padre y sus respectivos hijos
5. Marca **Requerido** si deseas bloquear el cierre sin selección
6. Guarda

## Autor

SOC Team — Linktic  
Licencia: GPL v2+
