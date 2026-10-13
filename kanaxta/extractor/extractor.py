#!/usr/bin/env python3
"""
Kanaxta - extractor de precios de Ara y D1.

Recorre las fichas registradas en el módulo administrativo, extrae
denominación, marca, presentación y precio (normal y con descuento) y envía
el resultado a la API de Kanaxta (api/ingesta.php).

Reglas de recolección responsable (RNF-03 del anteproyecto):
  * respeta robots.txt y su Crawl-delay;
  * se identifica con un agente propio (campo "agente" de config.json);
  * espera entre 6 y 12 segundos entre fichas;
  * ante CAPTCHA, inicio de sesión o bloqueo, detiene la cadena y lo informa;
  * nunca intenta eludir protecciones.

Uso:
  python extractor.py                  corrida completa y envío a la API
  python extractor.py --sin-enviar     corrida completa, muestra el JSON sin enviarlo
  python extractor.py --probar URL     prueba una sola ficha y muestra lo extraído
  python extractor.py --probar URL --ver   igual, con el navegador visible
"""
from __future__ import annotations

import argparse
import json
import logging
import random
import re
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlparse
from urllib.robotparser import RobotFileParser

import requests
from playwright.sync_api import Browser, Page, TimeoutError as PWTimeout, sync_playwright

BOGOTA = timezone(timedelta(hours=-5), "America/Bogota")   # Colombia no tiene horario de verano
CARPETA = Path(__file__).resolve().parent
LOG = logging.getLogger("kanaxta")

FRASES_BLOQUEO = (
    "captcha", "verifica que eres humano", "verifica que no eres un robot", "no soy un robot",
    "are you a robot", "are you human", "unusual traffic", "access denied", "acceso denegado",
    "request blocked", "solicitud bloqueada",
)

# ----------------------------------------------------------------- utilidades


def ahora_iso() -> str:
    return datetime.now(BOGOTA).isoformat(timespec="seconds")


def cargar_config() -> dict:
    ruta = CARPETA / "config.json"
    if not ruta.exists():
        sys.exit("Falta config.json. Copie config.ejemplo.json como config.json y complételo.")
    cfg = json.loads(ruta.read_text(encoding="utf-8"))
    cfg.setdefault("pausa_min_s", 6)
    cfg.setdefault("pausa_max_s", 12)
    cfg.setdefault("timeout_s", 45)
    cfg.setdefault("ventana_precio_px", 1500)
    cfg["url_api"] = cfg["url_api"].rstrip("/")
    return cfg


def configurar_log() -> None:
    (CARPETA / "logs").mkdir(exist_ok=True)
    archivo = CARPETA / "logs" / f"extractor_{datetime.now(BOGOTA):%Y%m%d}.log"
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.FileHandler(archivo, encoding="utf-8"), logging.StreamHandler(sys.stdout)],
    )


def parse_cop(valor) -> float | None:
    """'$ 5.200' → 5200.0; '12.990,50' → 12990.5; 5200 → 5200.0."""
    if valor is None:
        return None
    if isinstance(valor, (int, float)):
        return float(valor) if 0 < valor < 100_000_000 else None
    m = re.search(r"\d[\d.,\s]*\d|\d", str(valor))
    if not m:
        return None
    num = re.sub(r"\s", "", m.group(0))
    if "," in num and "." in num:
        num = num.replace(".", "").replace(",", ".") if num.rfind(",") > num.rfind(".") else num.replace(",", "")
    elif "," in num:
        num = num.replace(",", "") if len(num.split(",")[-1]) == 3 else num.replace(",", ".")
    elif "." in num and len(num.split(".")[-1]) == 3:
        num = num.replace(".", "")
    try:
        v = float(num)
    except ValueError:
        return None
    return v if 0 < v < 100_000_000 else None


UNIDADES = {
    "kg": ("g", 1000), "kilo": ("g", 1000), "kilos": ("g", 1000), "gramos": ("g", 1), "grs": ("g", 1),
    "gr": ("g", 1), "g": ("g", 1), "litros": ("ml", 1000), "litro": ("ml", 1000), "lts": ("ml", 1000),
    "lt": ("ml", 1000), "l": ("ml", 1000), "ml": ("ml", 1), "cc": ("ml", 1), "unidades": ("und", 1),
    "unidad": ("und", 1), "unds": ("und", 1), "und": ("und", 1), "un": ("und", 1), "rollos": ("und", 1),
    "rollo": ("und", 1), "huevos": ("und", 1),
}
RE_PRESENTACION = re.compile(
    r"(\d+(?:[.,]\d+)?)\s*(kg|kilos?|gramos|grs?|g|litros?|lts?|lt|ml|l|cc|unidades|unidad|unds?|un|rollos?|huevos)\b",
    re.IGNORECASE,
)


def parse_presentacion(nombre: str | None) -> tuple[float | None, str | None]:
    """'Arroz Diana 1 kg' → (1000, 'g'); 'Leche entera 1,1 L' → (1100, 'ml'); 'Huevo AA x30' → (30, 'und')."""
    if not nombre:
        return None, None
    encontrados = []
    for m in RE_PRESENTACION.finditer(nombre):
        unidad, factor = UNIDADES[m.group(2).lower()]
        encontrados.append((float(m.group(1).replace(",", ".")) * factor, unidad))
    for cantidad, unidad in encontrados:          # prioriza peso o volumen
        if unidad in ("g", "ml"):
            return round(cantidad, 2), unidad
    if encontrados:
        return round(encontrados[0][0], 2), encontrados[0][1]
    m = re.search(r"\bx\s*(\d{1,3})\b", nombre, re.IGNORECASE)   # p. ej. "x30"
    if m:
        return float(m.group(1)), "und"
    return None, None


# ----------------------------------------------------------------- robots.txt


class Robots:
    def __init__(self, agente: str):
        self.agente = agente
        self.cache: dict[str, RobotFileParser | None] = {}

    def _parser(self, url: str) -> RobotFileParser | None:
        p = urlparse(url)
        base = f"{p.scheme}://{p.netloc}"
        if base not in self.cache:
            rp = RobotFileParser()
            try:
                r = requests.get(base + "/robots.txt", headers={"User-Agent": self.agente}, timeout=20)
                if r.status_code == 200:
                    rp.parse(r.text.splitlines())
                elif r.status_code in (401, 403):
                    rp.disallow_all = True
                else:
                    rp.allow_all = True
                self.cache[base] = rp
            except requests.RequestException as e:
                LOG.warning("No se pudo leer %s/robots.txt: %s", base, e)
                self.cache[base] = None
        return self.cache[base]

    def permite(self, url: str) -> bool | None:
        rp = self._parser(url)
        return None if rp is None else rp.can_fetch(self.agente, url)

    def espera(self, url: str) -> float:
        rp = self._parser(url)
        retraso = rp.crawl_delay(self.agente) if rp else None
        return float(retraso or 0)


# ----------------------------------------------------------------- extracción

JS_DATOS = """
(ventana) => {
  const re = /\\$\\s?\\d{1,3}(?:[.,\\s]\\d{3})+(?:,\\d{1,2})?|\\$\\s?\\d{3,7}/;
  const precios = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length > 2) continue;
    const t = (el.innerText || '').trim();
    if (!t || t.length > 40 || !re.test(t)) continue;
    const st = getComputedStyle(el);
    if (st.display === 'none' || st.visibility === 'hidden') continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    const y = r.top + window.scrollY;
    if (y > ventana) continue;
    let tachado = false, n = el;
    for (let i = 0; i < 4 && n; i++, n = n.parentElement) {
      if ((getComputedStyle(n).textDecorationLine || '').includes('line-through') || n.tagName === 'S' || n.tagName === 'DEL') { tachado = true; break; }
    }
    precios.push({ texto: t.match(re)[0], tachado, tam: parseFloat(st.fontSize) || 0, y });
  }
  const meta = (sel) => { const m = document.querySelector(sel); return m ? m.getAttribute('content') : null; };
  const h1 = document.querySelector('h1');
  return {
    precios: precios.slice(0, 80),
    h1: h1 ? h1.innerText.trim() : null,
    og_titulo: meta('meta[property="og:title"]'),
    meta_precio: meta('meta[property="product:price:amount"]'),
    meta_marca: meta('meta[property="product:brand"]'),
    dom_marca: (() => { const e = document.querySelector('[itemprop="brand"], [class*="brand" i], [class*="marca" i]');
                        const t = e ? (e.getAttribute('content') || e.innerText || '').trim() : '';
                        return t && t.length <= 60 ? t : null; })(),
    ldjson: Array.from(document.querySelectorAll('script[type="application/ld+json"]')).map(s => s.textContent),
    texto: (document.body ? document.body.innerText : '').slice(0, 20000).toLowerCase(),
  };
}
"""


def producto_ldjson(bloques: list[str]) -> dict | None:
    """Busca un objeto schema.org Product en los bloques JSON-LD de la página."""
    pendientes = []
    for b in bloques:
        try:
            pendientes.append(json.loads(b))
        except (json.JSONDecodeError, TypeError):
            continue
    while pendientes:
        obj = pendientes.pop(0)
        if isinstance(obj, list):
            pendientes.extend(obj)
        elif isinstance(obj, dict):
            tipo = obj.get("@type")
            tipos = tipo if isinstance(tipo, list) else [tipo]
            if "Product" in tipos:
                return obj
            if "@graph" in obj:
                pendientes.extend(obj["@graph"] if isinstance(obj["@graph"], list) else [obj["@graph"]])
    return None


def precio_ldjson(producto: dict) -> float | None:
    ofertas = producto.get("offers")
    candidatos = ofertas if isinstance(ofertas, list) else [ofertas]
    for o in candidatos:
        if not isinstance(o, dict):
            continue
        if isinstance(o.get("offers"), list):           # AggregateOffer con ofertas internas
            candidatos.extend(o["offers"])
        for clave in ("price", "lowPrice"):
            v = parse_cop(o.get(clave))
            if v:
                return v
    return None


def extraer_ficha(page: Page, ventana_px: int) -> dict:
    """Extrae los datos de la ficha abierta usando varias señales (extracción resiliente, numeral 7.3)."""
    d = page.evaluate(JS_DATOS, ventana_px)
    prod = producto_ldjson(d["ldjson"]) or {}

    nombre = prod.get("name") or d["og_titulo"] or d["h1"]
    marca = prod.get("brand")
    if isinstance(marca, dict):
        marca = marca.get("name")
    marca = marca or d["meta_marca"] or d["dom_marca"]

    precio_actual = precio_ldjson(prod) if prod else None
    precio_actual = precio_actual or parse_cop(d["meta_precio"])
    vistos = [p for p in d["precios"] if parse_cop(p["texto"])]
    sin_tachar = sorted((p for p in vistos if not p["tachado"]), key=lambda p: (-p["tam"], p["y"]))
    tachados = sorted((p for p in vistos if p["tachado"]), key=lambda p: (-p["tam"], p["y"]))
    if precio_actual is None and sin_tachar:
        precio_actual = parse_cop(sin_tachar[0]["texto"])
    precio_tachado = parse_cop(tachados[0]["texto"]) if tachados else None

    if precio_actual and precio_tachado and precio_tachado > precio_actual:
        precio_normal, precio_descuento = precio_tachado, precio_actual
    else:
        precio_normal, precio_descuento = precio_actual, None

    cantidad, unidad = parse_presentacion(nombre)
    señales = [s for s, ok in (("JSON-LD", bool(prod)), ("meta", bool(d["meta_precio"])), ("DOM", bool(vistos))) if ok]
    return {
        "nombre": (nombre or "").strip()[:255] or None,
        "marca": (str(marca).strip()[:120] if marca else None),
        "cantidad": cantidad,
        "unidad": unidad,
        "precio_normal": precio_normal,
        "precio_descuento": precio_descuento,
        "estado": "vigente" if precio_normal else "no_disponible",
        "detalle": ("Señales: " + ", ".join(señales)) if precio_normal else "No se encontró un precio verificable en la página.",
    }


def hay_bloqueo(page: Page, estado_http: int | None) -> str | None:
    if estado_http in (401, 403, 429, 503):
        return f"Respuesta HTTP {estado_http}"
    url = page.url.lower()
    if any(p in url for p in ("/login", "iniciar-sesion", "/account/login", "captcha")):
        return "Redirección a inicio de sesión o verificación"
    try:
        texto = page.inner_text("body", timeout=5000).lower()[:20000]
    except PWTimeout:
        texto = ""
    for frase in FRASES_BLOQUEO:
        if frase in texto:
            return f"La página muestra: «{frase}»"
    return None


def abrir(page: Page, url: str, timeout_s: int) -> int | None:
    resp = page.goto(url, wait_until="domcontentloaded", timeout=timeout_s * 1000)
    try:
        page.wait_for_load_state("networkidle", timeout=15000)
    except PWTimeout:
        pass
    try:   # espera a que aparezca algún precio renderizado con JavaScript (D1)
        page.wait_for_function(r"() => /\$\s?\d/.test(document.body ? document.body.innerText : '')", timeout=10000)
    except PWTimeout:
        pass
    return resp.status if resp else None


def nuevo_contexto(navegador: Browser, cfg: dict):
    return navegador.new_context(
        user_agent=cfg["agente"], locale="es-CO", timezone_id="America/Bogota",
        viewport={"width": 1366, "height": 900},
    )


def extraer_cadena(navegador: Browser, cadena: str, objetivos: list[dict], cfg: dict, robots: Robots) -> dict:
    inicio = ahora_iso()
    fichas, estado, mensajes = [], None, []
    contexto = nuevo_contexto(navegador, cfg)
    page = contexto.new_page()
    try:
        for i, obj in enumerate(objetivos):
            url = obj["url"]
            permitido = robots.permite(url)
            if permitido is None:
                estado = "error"
                mensajes.append("No se pudo verificar robots.txt; se detuvo la consulta por precaución.")
                break
            if not permitido:
                fichas.append({"producto_cadena_id": obj["producto_cadena_id"], "fecha_hora": ahora_iso(),
                               "estado": "no_disponible", "detalle": "robots.txt no permite consultar esta URL."})
                LOG.warning("[%s] robots.txt no permite %s", cadena, url)
                continue
            if i > 0:
                time.sleep(max(random.uniform(cfg["pausa_min_s"], cfg["pausa_max_s"]), robots.espera(url)))
            try:
                codigo = abrir(page, url, cfg["timeout_s"])
            except PWTimeout:
                fichas.append({"producto_cadena_id": obj["producto_cadena_id"], "fecha_hora": ahora_iso(),
                               "estado": "no_disponible", "detalle": "La página no respondió a tiempo."})
                LOG.warning("[%s] tiempo agotado en %s", cadena, url)
                continue
            motivo = hay_bloqueo(page, codigo)
            if motivo:
                estado = "bloqueado"
                mensajes.append(f"{motivo} en {url}. Se detuvo la consulta de {cadena} sin intentar eludir la protección.")
                LOG.error("[%s] bloqueo: %s", cadena, motivo)
                break
            datos = extraer_ficha(page, cfg["ventana_precio_px"])
            datos.update({"producto_cadena_id": obj["producto_cadena_id"], "url": url, "fecha_hora": ahora_iso()})
            if codigo and codigo >= 400:
                datos.update({"estado": "no_disponible", "detalle": f"Respuesta HTTP {codigo}."})
            fichas.append(datos)
            LOG.info("[%s] %s → %s %s", cadena, obj.get("referencia"), datos.get("precio_descuento") or datos.get("precio_normal"), datos["estado"])
    finally:
        contexto.close()

    vigentes = sum(1 for f in fichas if f.get("estado") == "vigente")
    if estado is None:
        estado = "ok" if fichas and vigentes == len(objetivos) else ("parcial" if vigentes else "error")
    return {"cadena": cadena, "inicio": inicio, "fin": ahora_iso(), "estado": estado,
            "mensaje": " ".join(mensajes), "fichas": fichas}


# ----------------------------------------------------------------- API


def cabeceras(cfg: dict) -> dict:
    return {"X-Kanaxta-Token": cfg["token"], "User-Agent": "KanaxtaExtractor/0.1", "Accept": "application/json"}


def obtener_objetivos(cfg: dict) -> list[dict]:
    r = requests.get(cfg["url_api"] + "/api/objetivos.php", headers=cabeceras(cfg), timeout=30)
    r.raise_for_status()
    return r.json()["objetivos"]


def enviar(cfg: dict, resultado: dict) -> bool:
    try:
        r = requests.post(cfg["url_api"] + "/api/ingesta.php", json=resultado, headers=cabeceras(cfg), timeout=60)
        r.raise_for_status()
        LOG.info("Enviado %s: %s", resultado["cadena"], r.json())
        return True
    except (requests.RequestException, ValueError) as e:
        pendientes = CARPETA / "pendientes"
        pendientes.mkdir(exist_ok=True)
        archivo = pendientes / f"{resultado['cadena']}_{datetime.now(BOGOTA):%Y%m%d_%H%M%S}.json"
        archivo.write_text(json.dumps(resultado, ensure_ascii=False, indent=1), encoding="utf-8")
        LOG.error("No se pudo enviar %s (%s). Guardado en %s para reenviar.", resultado["cadena"], e, archivo.name)
        return False


def reenviar_pendientes(cfg: dict) -> None:
    for archivo in sorted((CARPETA / "pendientes").glob("*.json")) if (CARPETA / "pendientes").exists() else []:
        try:
            r = requests.post(cfg["url_api"] + "/api/ingesta.php", data=archivo.read_bytes(),
                              headers={**cabeceras(cfg), "Content-Type": "application/json"}, timeout=60)
            r.raise_for_status()
            archivo.unlink()
            LOG.info("Reenviado %s", archivo.name)
        except requests.RequestException as e:
            LOG.warning("Sigue pendiente %s: %s", archivo.name, e)
            break


# ----------------------------------------------------------------- principal


def main() -> None:
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except AttributeError:
        pass
    ap = argparse.ArgumentParser(description="Extractor de precios de Kanaxta")
    ap.add_argument("--probar", metavar="URL", help="extrae una sola ficha y muestra el resultado")
    ap.add_argument("--sin-enviar", action="store_true", help="no envía los datos a la API")
    ap.add_argument("--ver", action="store_true", help="muestra el navegador (para depurar)")
    args = ap.parse_args()

    configurar_log()
    cfg = cargar_config()
    robots = Robots(cfg["agente"])

    with sync_playwright() as pw:
        navegador = pw.chromium.launch(headless=not args.ver)
        try:
            if args.probar:
                if robots.permite(args.probar) is False:
                    sys.exit("robots.txt no permite consultar esa URL.")
                contexto = nuevo_contexto(navegador, cfg)
                page = contexto.new_page()
                codigo = abrir(page, args.probar, cfg["timeout_s"])
                bloqueo = hay_bloqueo(page, codigo)
                resultado = {"http": codigo, "bloqueo": bloqueo}
                resultado.update(extraer_ficha(page, cfg["ventana_precio_px"]) if not bloqueo else {})
                print(json.dumps(resultado, ensure_ascii=False, indent=2))
                contexto.close()
                return

            if not args.sin_enviar:
                reenviar_pendientes(cfg)
            objetivos = obtener_objetivos(cfg)
            if not objetivos:
                LOG.info("No hay fichas registradas. Agregue las URL en admin/catalogo.php.")
                return
            por_cadena: dict[str, list[dict]] = {}
            for o in objetivos:
                por_cadena.setdefault(o["cadena"], []).append(o)
            for cadena, lista in por_cadena.items():
                LOG.info("Extrayendo %d fichas de %s", len(lista), cadena)
                resultado = extraer_cadena(navegador, cadena, lista, cfg, robots)
                if args.sin_enviar:
                    print(json.dumps(resultado, ensure_ascii=False, indent=2))
                else:
                    enviar(cfg, resultado)
        finally:
            navegador.close()


if __name__ == "__main__":
    main()
