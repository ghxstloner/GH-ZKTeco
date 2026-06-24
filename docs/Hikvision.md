# Integración Hikvision (PULL via ISUP Bridge)

> Esta fase **NO modifica** la integración ZKTeco/ProFaceX existente. Lee
> `app/Services/ZKTeco` solo como referencia de estilo.

## 1. Flujo de alto nivel

```
   Laravel  --(HTTP JSON, header X-Flow-Bridge-Token)-->  Bridge ISUP  --(ISUP/EHome)-->  Dispositivo Hikvision
                                                              ^
                                   (PULL periódico desde Artisan / Scheduler)
```

Laravel **nunca** habla con el dispositivo directamente. Lo hace siempre por
medio del Bridge ISUP externo. Las credenciales (URL + token del bridge)
**viven únicamente en `config/hikvision.php` desde `.env`**; no se guardan
por dispositivo en base de datos.

## 2. Diferencia con ZKTeco

| | ZKTeco / ProFaceX | Hikvision |
|---|---|---|
| Modelo | **PUSH**: el dispositivo llama a `api/{codigo}/iclock/*` | **PULL**: Laravel consulta el Bridge cada N minutos |
| Trigger | Request HTTP del dispositivo | Scheduler Artisan |
| Tenant routing | URL `{codigo}` + `EmpresaMiddleware` | Itera `nomempresa` por código |
| Credenciales en BD | por dispositivo (`profacex_device_info`) | NO (solo `config/hikvision.php`) |
| Tabla unificada | (pendiente fase posterior) | `asistencia_dispositivos` (driver `hikvision`) |

## 3. Variables `.env` (ver `.env.example`)

```dotenv
# Obligatorias para operar
HIK_BRIDGE_URL=http://159.223.166.80:16233
HIK_BRIDGE_TOKEN=<token configurado en el bridge>
HIK_BRIDGE_TIMEOUT=15
HIK_EVENTS_MAX_RESULTS=30
HIK_EVENTS_LOOKBACK_MINUTES=10

# Cadencia del scheduler (minutos)
HIK_SYNC_SCHEDULE_MINUTES=5
HIK_PULL_SCHEDULE_MINUTES=1
```

Las variables `HIK_PUBLIC_IP`, `HIK_CMS_LISTEN_*`, `HIK_DAS_*`, `HIK_ISUP_KEY`
son del **lado del servidor ISUP/Bridge**, no de Laravel. Se documentan
comentadas en `.env.example` solo como referencia.

## 4. Migraciones tenant

Las 4 migraciones nuevas corren en **todas** las BD tenant activas listadas
en `nomempresa.bd_nomina WHERE nomina_activo = 1`:

```
database/migrations/
  2026_06_24_000001_create_hikvision_device_info_on_tenant_databases.php
  2026_06_24_000002_create_hikvision_event_log_on_tenant_databases.php
  2026_06_24_000003_create_hikvision_user_info_on_tenant_databases.php
  2026_06_24_000004_create_asistencia_dispositivos_on_tenant_databases.php
```

Reglas de ejecución (`App\Support\TenantMigrationRunner`):

1. Si la BD tenant **no existe** (SQLSTATE `1049` / "Unknown database") → se
   omite con warning, sin fallo.
2. Si la BD existe pero la migración falla por **cualquier otro motivo**
   (permisos, SQL, estructura, conexión, timeout) → la excepción se relanza
   y `php artisan migrate` falla.
3. La conexión central `mysql` nunca se rompe.

Para aplicar:

```bash
php artisan config:clear
php artisan migrate --force
```

### ⚠️ Requisito previo: tablas de caché/sesión/cola

El proyecto usa `CACHE_STORE=database`, `SESSION_DRIVER=database` y
`QUEUE_CONNECTION=database`. **Verifica** que las tablas `cache`,
`cache_locks` y `jobs` existan en la BD central (`planilla_configuracion`).
El repo solo incluye la migración `sessions`. Si faltan, créalas con:

```bash
php artisan make:migration create_cache_table
php artisan make:migration create_jobs_table
# (o pega el contenido oficial de Laravel: cache, cache_locks, jobs, failed_jobs)
php artisan migrate --force
```

Sin `cache_locks`, el método `->withoutOverlapping()` del scheduler fallará
en tiempo de ejecución.

## 5. Comandos Artisan

```bash
# Verificar conectividad con el Bridge (no toca BD):
php artisan hikvision:verify-bridge

# Sincronizar dispositivos (recorre el Bridge y resuelve tenant por deviceId):
php artisan hikvision:sync-devices

# Pull de marcaciones (rango por defecto = últimos 10 min):
php artisan hikvision:pull-events

# Pull con rango explícito (ISO-8601 con offset):
php artisan hikvision:pull-events \
    --from="2026-06-24T00:00:00-05:00" \
    --to="2026-06-24T12:00:00-05:00"

# Pull para un solo dispositivo del Bridge (deviceId == codigo empresa):
php artisan hikvision:pull-events --device=123

# Catálogo unificado (delegación a Hikvision; ZKTeco vendrá):
php artisan attendance:sync-devices
```

> **Modelo device-driven**: el `deviceId` configurado en cada dispositivo
> físico Hikvision en el Bridge ES EXACTAMENTE el `codigo` de la empresa en
> `nomempresa`. Por eso los comandos no llevan `--empresa`: el propio
> dispositivo lleva implícito a qué tenant pertenecen sus marcaciones.

## 6. Scheduler

Este proyecto usa el bootstrap fluido de Laravel 12 (`Application::configure`
en `bootstrap/app.php`), por lo que el método `schedule()`
de `app/Console/Kernel.php` **no se invoca**. Las cadencias se registran en
`routes/console.php` con la facade `Schedule::command(...)`:

- `hikvision:sync-devices` ↔ `HIK_SYNC_SCHEDULE_MINUTES` (defecto 5).
- `hikvision:pull-events` ↔ `HIK_PULL_SCHEDULE_MINUTES` (defecto 1).

`N > 1` → cron `*/N * * * *`; `N <= 1` → cada minuto. Ambos
con `->withoutOverlapping()`.

```bash
php artisan schedule:list
php artisan schedule:run
# En producción: añadir al crontab del servidor:
#   * * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Mapeo de eventos Hikvision → BD

El campo `eventsJson` (string JSON) del Bridge se parsea y mapea:

| Hikvision (AcsEvent.InfoList[]) | `hikvision_event_log` |
|---|---|
| `serialNo` | `SERIAL_NO` (también usado en `DEDUP_KEY`) |
| `employeeNo` | `EMPLOYEE_NO` + `EMPLOYEE_NO_STRING` (+ `FICHA` si numérico) |
| `time` / `currentEventTime` / `eventTime` | `EVENT_TIME` |
| `majorEventType` / `major` | `MAJOR_EVENT` |
| `minorEventType` / `minor` | `MINOR_EVENT` |
| `verifyMode` | `VERIFY_MODE` |
| `currentVerifyMode` | `CURRENT_VERIFY_MODE` |
| `name` / `eventName` | `EVENT_NAME` |
| `cardReaderNo` | `CARD_READER_NO` |
| `doorNo` | `DOOR_NO` |
| `cardType` | `CARD_TYPE` |
| `userType` | `USER_TYPE` |
| `mask` | `MASK` |
| `faceRect` | `FACE_RECT` (JSON) |
| (evento completo) | `RAW_RESPONSE` (JSON) |

**Idempotencia**: la columna `DEDUP_KEY` (varchar 64, UNIQUE) evita duplicados:
- Si el evento trae `serialNo` → `DEDUP_KEY = "{serial}|{bridge}|{serialNo}"`.
- Si no → `DEDUP_KEY = sha1("{serial}|{bridge}|{empNo}|{eventTime}|{major}|{minor}")`.

Un INSERT con `DEDUP_KEY` ya existente se ignora silenciosamente (MySQL
sqlstate 23000 / code 1062).

## 8. Tablas creadas

- `hikvision_device_info` — catálogo de dispositivos por tenant (sin
  credenciales por dispositivo; `TRANSPORT_MODE='bridge'`).
- `hikvision_event_log` — marcaciones/ACS events (idempotente por `DEDUP_KEY`).
- `hikvision_user_info` — usuarios provisionados y estado de sincronización
  (`SYNC_STATUS`: pending/synced/failed/offline/validation_error/feature_disabled/unauthorized/not_found).
- `asistencia_dispositivos` — catálogo unificado Hikvision + ZKTeco (futuro),
  unique por `(driver, source_table, source_device_id, empresa_codigo)` — el
  mismo dispositivo físico puede aparecer replicado entre tenants; es
  intención del negocio.

## 9. Verificación manual (runbook)

1. **Conectividad Bridge**:
   ```bash
   php artisan hikvision:verify-bridge
   # Esperado: "OK: el Bridge respondio. Dispositivos conocidos: N"
   ```

2. **Migraciones**:
   ```bash
   php artisan migrate --force
   # En cada tenant activo: 4 tablas nuevas.
   # Los tenants con BD inexistente: warning de omisión, no falla.
   ```
   Para forzar el path "falla si BD existe pero error": apunta un
   `nomempresa.bd_nomina` a una BD donde el usuario no tenga `CREATE` y
   re-ejecuta → `php artisan migrate` debe salir con código != 0.

3. **Sync de dispositivos** (todos los del Bridge, cada uno a su tenant):
   ```bash
   php artisan hikvision:sync-devices
   # Salida: devices=N updated=M skipped=K errors=0
   ```
   Cada dispositivo cuya `deviceId` coincide con un `nomempresa.codigo`
   activo se escribe en la BD DE ESA empresa. Los dispositivos con
   `deviceId` que no existe en `nomempresa` quedan como `skipped`.

   Verificar en la BD de un tenant (ej: codigo=123):
   ```sql
   SELECT DEVICE_SERIAL, BRIDGE_DEVICE_ID, STATE, FW_VERSION, LAST_POLLED_AT
   FROM hikvision_device_info;
   SELECT driver, marca, source_device_id, bridge_device_id, estado, last_seen_at, metadata
   FROM asistencia_dispositivos WHERE driver='hikvision' AND empresa_codigo='123';
   ```
   - `TRANSPORT_MODE='bridge'`, sin columna `PASSWORD`/`BRIDGE_TOKEN`.
   - `metadata` JSON con `model`, `firmware`, `deviceType`, `bridgeRaw`.
   - `last_seen_at` avanzando.

4. **Pull de eventos + idempotencia** (correr 2 veces):
   ```bash
   php artisan hikvision:pull-events \
     --from="2026-06-24T00:00:00-05:00" --to="2026-06-24T12:00:00-05:00"
   # Recorre todos los dispositivos del Bridge y resuelve tenant por deviceId.
   # Para acotar a un solo dispositivo:
   php artisan hikvision:pull-events --device=123
   # Repetir: el conteo de inserted debe ser el mismo de la 1ª vez y duplicates subir.
   ```
   ```sql
   SELECT COUNT(*) total, COUNT(DISTINCT DEDUP_KEY) unicos FROM hikvision_event_log;
   -- total == unicos  =>  no hay duplicados.
   SELECT DEDUP_KEY, EVENT_TIME, EMPLOYEE_NO, RAW_RESPONSE
   FROM hikvision_event_log ORDER BY EVENT_TIME DESC LIMIT 5;
   ```

5. **Resiliencia** (offline / feature_disabled):
   ```bash
   php artisan hikvision:pull-events --device=999999
   # deviceId que no existe en nomempresa -> skipped, no toca BD.
   ```
   No debe romper; el dispositivo queda como `skipped`/marcado y el resto
   sigue procesando. Exit code 0.

6. **Scheduler**:
   ```bash
   php artisan schedule:list   # muestra hikvision:sync-devices y hikvision:pull-events
   ```
   Variar `HIK_SYNC_SCHEDULE_MINUTES` y reiniciar `config:clear` para ver
   la cadencia reflejada en la expresión cron.

7. **Smoke test automático**:
   ```bash
   php artisan test --filter=HikvisionBridgeClient
   ```

8. **Sin regresión ZKTeco**:
   ```bash
   # grep vacío =>	ZKTeco intacto
   grep -r "profacex_\|Services\\\\ZKTeco" app/Services/Hikvision app/Models/Hikvision
   ```
   Las rutas `api/{codigo}/iclock/*` siguen operativas y un PUSH de
   dispositivo ZKTeco debe seguir respondiendo `OK`.

## 10. Provisioning (futuro inmediato)

El `HikvisionProvisioningService` está implementado y usa los endpoints
`PUT /users/{employeeNo}` y `PUT /users/{employeeNo}/face`. El endpoint
`DELETE /users/{employeeNo}` **NO** se llama porque el Bridge lo retorna
`501 / not_implemented`. Queda marcado como `TODO deleteUser` en el servicio
para cuando el Bridge lo soporte.

## 11. To-do post-fase

- Reflejar ZKTeco/ProFaceX en `asistencia_dispositivos` (driver `zkteco`)
  **sin** tocar `app/Services/ZKTeco` (solo un wrapper que llame
  `AsistenciaDispositivoService::upsert`).
- Drenar `hikvision_event_log.PROCESSED=0` hacia `AmaxoniaMarcacionService`
  (similar a como `profacex_att_log.procesado=0` ya alimenta nómina).
