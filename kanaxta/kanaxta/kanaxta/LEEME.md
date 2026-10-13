# Kanaxta – MVP (versión base)

Prototipo web que compara el **costo total de adquisición (CTA = Pc + Ca)** de una canasta de 10 a 12 productos entre Éxito y D1.

| Carpeta | Qué contiene | Dónde va |
|---|---|---|
| `sql/` | Tablas (`01_esquema.sql`) y datos iniciales (`02_datos_iniciales.sql`) | Se importan en phpMyAdmin (DonWeb) |
| `servidor/` | Página web, API en PHP, módulo administrativo y tarea programada | Carpeta del subdominio en DonWeb |
| `extractor/` | Extractor de precios en Python + Playwright | Computador de la estudiante |

```
PC (extractor, 1 vez al día) ──JSON + token──► DonWeb: api/ingesta.php → MySQL ◄── usuarios (index.html)
```

---

## 1. Base de datos (DonWeb → Ferozo → phpMyAdmin)

1. Abra phpMyAdmin, seleccione la base de datos que creó para el proyecto.
2. Pestaña **Importar** → suba `sql/01_esquema.sql` → Continuar.
3. Repita con `sql/02_datos_iniciales.sql` (carga Éxito, D1, los 12 productos de la canasta y las tarifas de transporte de 2026).

## 2. Subir la aplicación

1. En `servidor/privado/` copie `config.ejemplo.php` con el nombre **`config.php`** y complete:
   - `db_host`, `db_nombre`, `db_usuario`, `db_clave`: los datos de la base que creó en Ferozo.
   - `token_ingesta`: una clave larga y aleatoria. Para generarla, en el PC ejecute
     `python -c "import secrets; print(secrets.token_hex(32))"` y copie el resultado.
2. Suba **todo el contenido** de `servidor/` a la carpeta del subdominio (Administrador de archivos de Ferozo o FileZilla). Verifique que se subieron los archivos ocultos `.htaccess` (el de la raíz y el de `privado/`).
3. Abra `https://SU-SUBDOMINIO/admin/crear_admin.php`, cree el usuario administrador y **borre ese archivo del servidor**.
4. Cuando el SSL del subdominio esté activo, quite los `#` del bloque "Fuerza HTTPS" en `servidor/.htaccess` y vuelva a subirlo.

## 3. Configurar desde el módulo administrativo (`/admin`)

1. **Parámetros y sedes**
   - Registre las sedes de Éxito y D1 sobre la Av. Cra. 68 con sus coordenadas (en Google Maps: clic derecho sobre la sede → copiar coordenadas).
   - Revise el **sitio web de cada cadena**. Si la tienda en línea de D1 está en otro dominio, cámbielo aquí: las URL del catálogo deben pertenecer a ese dominio.
   - Las tarifas de 2026 ya vienen cargadas con su fuente; actualícelas si cambian.
2. **Catálogo**: para cada producto pegue la URL de su ficha en Éxito y en D1. Ajuste la presentación de referencia si hace falta (1 kg = 1000 g; 1 L = 1000 ml).
3. **Equivalencias**: después de la primera extracción, confirme o corrija lo que propone el sistema (coincidencia exacta o alternativa similar).
4. **Panel**: muestra las últimas ejecuciones del extractor y cuántos precios están vigentes.

## 4. Tarea programada (Ferozo → Herramientas → Tareas programadas)

Marca como "no vigente" todo precio con más de 24 horas. Cree una tarea con minuto `5`, hora `*`, día `*`, mes `*`, día de la semana `*` y el comando:

```
php "/home/USUARIO/RUTA-DEL-SUBDOMINIO/privado/cron_vencidos.php"
```

La ruta exacta aparece en el Administrador de archivos de Ferozo.

## 5. Extractor en el PC (Windows)

> **Antes de extraer (semana 1):** lea los términos de uso y el archivo `robots.txt` de cada sitio. El extractor respeta `robots.txt`, se identifica, espera entre 6 y 12 s entre fichas y **se detiene ante CAPTCHA, inicio de sesión o bloqueo**. No lo modifique para eludir protecciones: si un sitio bloquea, documéntelo como resultado.

1. Instale Python 3.12 desde python.org (marque *Add python.exe to PATH*).
2. Abra una terminal en la carpeta `extractor` y ejecute:
   ```
   python -m venv .venv
   .venv\Scripts\activate
   pip install -r requirements.txt
   playwright install chromium
   ```
3. Copie `config.ejemplo.json` como **`config.json`** y complete `url_api` (https://su-subdominio), `token` (el mismo de `config.php`) y su correo en `agente`.
4. Pruebe una ficha antes de todo: `python extractor.py --probar URL_DE_UN_PRODUCTO`
   (agregue `--ver` para ver el navegador). Debe mostrar nombre, marca, presentación y precio.
5. Corrida completa: `python extractor.py` (o `--sin-enviar` para ver el JSON sin mandarlo).
6. Programe la corrida diaria (6:05 a. m.) en una terminal de Windows:
   ```
   schtasks /Create /SC DAILY /ST 06:05 /TN "Kanaxta extractor" /TR "\"C:\RUTA\kanaxta\extractor\ejecutar_extractor.bat\""
   ```
   Los registros quedan en `extractor/logs/`. Si la API no responde, el resultado se guarda en `extractor/pendientes/` y se reenvía en la siguiente corrida.

**Ajuste esperado (semanas 2 y 3):** cada sitio presenta los datos a su manera. El extractor combina varias señales (datos estructurados JSON-LD, metaetiquetas y precios visibles, incluido el precio tachado como precio normal). Si una ficha sale incompleta con `--probar`, el ajuste se hace en la función `extraer_ficha()` de `extractor.py`.

## 6. Reglas que implementa (relación con el anteproyecto)

| Regla | Dónde |
|---|---|
| Coincidencia exacta = misma marca y presentación dentro del ±10 % de la referencia; si no, alternativa similar | `privado/funciones.php` → `kx_recalcular_equivalencias()` (numeral 9.9) |
| Las alternativas solo entran al total si el usuario las acepta | `kx_comparar()` (RF-08) |
| Pc se calcula solo con productos comparables en ambas cadenas; se informa la cobertura (p. ej. 10 de 12) | `kx_comparar()` (regla de comparabilidad) |
| Precio con presentación distinta se ajusta por precio por unidad de medida | `kx_comparar()` |
| Costo de acceso: a pie = 0; público = 2 × T × n; carro = (2d ÷ r) × Pg + Pk; taxi = 2 × máx(Tmín; Tb + Tk × d) | `kx_costo_acceso()` (Tabla 5) |
| Precio con más de 24 h = no vigente; nunca se presenta como vigente | `cron_vencidos.php` y `kx_comparar()` (RF-05) |
| La canasta y la ubicación quedan solo en el navegador; al servidor llegan solo distancias | `assets/app.js` (RNF-05) |
| Contraseñas con `password_hash`, formularios con token CSRF, token para el extractor | `admin/`, `api/ingesta.php` (RNF-06) |

## 7. Pruebas realizadas en esta versión

Se probó de punta a punta en un servidor local (PHP 8.3 + MariaDB 10.11) con páginas de ejemplo:

- extracción de una página con JSON-LD y precio tachado (normal $5.800 / descuento $5.200) y de otra cuyo precio aparece por JavaScript;
- respeto de `robots.txt` (una URL prohibida quedó como "no disponible") y detección de una página con CAPTCHA;
- ingesta con token (sin token → 401), propuesta automática de equivalencias (exacta, alternativa, pendiente), normalización de 900 ml a 1 L;
- cálculo del CTA en los cuatro modos, incluido un caso en que el costo del taxi cambia la cadena más conveniente;
- vencimiento de precios con la tarea programada; módulo administrativo (crear usuario, ingreso, CSRF, catálogo, sedes, aprobación de equivalencias);
- interfaz en computador y celular, en modo claro y oscuro, sin errores de consola.
