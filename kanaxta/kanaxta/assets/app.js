/* Kanaxta - interfaz pública (sin dependencias externas). */
(function () {
  'use strict';
 
  var MIN = 1, MAX = 12;
  var CLAVE = 'kanaxta_estado_v1';
  var cop = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
 
  var catalogo = { productos: [], sedes: [], cadenas: [], parametros: {} };
  var estado = cargarEstado();
 
  var $ = function (id) { return document.getElementById(id); };
 
  var MODOS = { pie: 'A pie o en bicicleta', publico: 'Transporte público', carro: 'Carro particular', taxi: 'Taxi o aplicación' };
 
  /* Iconos de línea (24 × 24) para las categorías y los mensajes */
  var ICONOS = {
    grano: '<path d="M12 21V9"/><path d="M12 9c-2.6 0-4-1.7-4-4.6 2.6 0 4 1.7 4 4.6Z"/><path d="M12 9c2.6 0 4-1.7 4-4.6-2.6 0-4 1.7-4 4.6Z"/><path d="M12 14.5c-2.6 0-4-1.7-4-4.3 2.6 0 4 1.6 4 4.3Z"/><path d="M12 14.5c2.6 0 4-1.7 4-4.3-2.6 0-4 1.6-4 4.3Z"/>',
    aceite: '<path d="M12 3.5s6 6.4 6 10.8a6 6 0 0 1-12 0C6 9.9 12 3.5 12 3.5Z"/><path d="M9.5 14.5A2.6 2.6 0 0 0 12 17"/>',
    dulce: '<path d="M12 3 20 7.5v9L12 21l-8-4.5v-9Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
    bebida: '<path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5Z"/><path d="M16 11h1.5a2.5 2.5 0 0 1 0 5H16M8 3.5V6M12 3.5V6"/>',
    lacteo: '<path d="M8 8.5 9.5 3h5L16 8.5V20a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1Z"/><path d="M8 8.5h8M8 13h8"/>',
    proteina: '<path d="M12 3c3.4 0 6 5.2 6 9.3a6 6 0 0 1-12 0C6 8.2 8.6 3 12 3Z"/>',
    personal: '<ellipse cx="7.5" cy="8" rx="3.5" ry="4.5"/><path d="M7.5 3.5H17c1.9 0 3.5 2 3.5 4.5v12H11V8"/><circle cx="7.5" cy="8" r=".8"/>',
    hogar: '<path d="M9 9h6l1.2 3.2V20a1 1 0 0 1-1 1H8.8a1 1 0 0 1-1-1v-7.8Z"/><path d="M10.5 9V5.5H15M15 5.5l3-1.5M12.5 5.5v-2"/>',
    bolsa: '<path d="M5 8h14l-1 12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    check: '<path d="m5 12.5 4.2 4.2L19 7"/>',
    giro: '<path d="M7 7h12l-3-3M17 17H5l3 3"/>',
    igual: '<path d="M5 9h14M5 15h14"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>'
  };
  function icono(nombre) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (ICONOS[nombre] || ICONOS.bolsa) + '</svg>'; }
  function iconoCategoria(categoria) {
    var c = String(categoria || '').toLowerCase();
    if (/grano|cereal|arroz|pasta/.test(c)) { return 'grano'; }
    if (/aceite|grasa/.test(c)) { return 'aceite'; }
    if (/endulz|az[uú]car|panela|dulce/.test(c)) { return 'dulce'; }
    if (/bebida|caf[eé]/.test(c)) { return 'bebida'; }
    if (/l[aá]cteo|leche/.test(c)) { return 'lacteo'; }
    if (/prote|huevo|carne|at[uú]n/.test(c)) { return 'proteina'; }
    if (/personal/.test(c)) { return 'personal'; }
    if (/hogar|limpieza|aseo/.test(c)) { return 'hogar'; }
    return 'bolsa';
  }
 
  /* ---------------------------------------------------------- estado local */
  function cargarEstado() {
    var base = { seleccion: {}, modo: 'pie', personas: 1, rendimiento: '', parqueadero: '', distancias: { olimpica: '', d1: '' }, aceptadas: [] };
    try {
      var guardado = JSON.parse(window.localStorage.getItem(CLAVE) || 'null');
      if (guardado && typeof guardado === 'object') {
        for (var k in base) { if (guardado[k] !== undefined) { base[k] = guardado[k]; } }
      }
    } catch (e) { /* navegación privada o almacenamiento bloqueado */ }
    return base;
  }
  function guardarEstado() {
    try { window.localStorage.setItem(CLAVE, JSON.stringify(estado)); } catch (e) { /* sin almacenamiento */ }
  }
 
  /* ---------------------------------------------------------- utilidades */
  function esc(texto) {
    return String(texto == null ? '' : texto).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function partesFecha(valor) { return String(valor).split(/[- :T]/); }
  function fecha(valor) {
    if (!valor) { return '—'; }
    var p = partesFecha(valor);
    return p[2] + '/' + p[1] + '/' + p[0] + ' ' + p[3] + ':' + p[4];
  }
  function horasDesde(valor) {
    var p = partesFecha(valor).map(Number);
    var t = new Date(p[0], p[1] - 1, p[2], p[3] || 0, p[4] || 0).getTime();
    return isNaN(t) ? null : (Date.now() - t) / 36e5;
  }
  function dinero(v) { return v == null ? '—' : cop.format(v); }
  function km(v) { return String(v).replace('.', ','); }
  function nombreCadena(codigo) {
    for (var i = 0; i < catalogo.cadenas.length; i++) { if (catalogo.cadenas[i].codigo === codigo) { return catalogo.cadenas[i].nombre; } }
    return codigo;
  }
  function cantidadSeleccionada() { return Object.keys(estado.seleccion).length; }
 
  function mostrarError(msg) {
    var e = $('error');
    e.textContent = msg || '';
    e.hidden = !msg;
  }
 
  /* ---------------------------------------------------------- catálogo */
  function cargarCatalogo() {
    fetch('api/productos.php', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (datos) {
        if (!datos.ok) { throw new Error(datos.error || 'Error'); }
        catalogo = datos;
        var ids = {};
        datos.productos.forEach(function (p) { ids[p.id] = true; });
        Object.keys(estado.seleccion).forEach(function (id) { if (!ids[id]) { delete estado.seleccion[id]; } });
        $('elegir-todos').textContent = datos.productos.length > MAX ? 'Agregar los primeros ' + MAX : 'Agregar los ' + datos.productos.length;
        pintarActualizacion();
        pintarCatalogo();
        pintarViaje();
      })
      .catch(function () {
        $('actualizacion').textContent = 'No se pudo cargar el catálogo. Intenta de nuevo en unos minutos.';
      });
  }
 
  function pintarActualizacion() {
    var limite = Number(catalogo.parametros.horas_vigencia) || 24;
    var html = '<span class="actualizacion__titulo">Última consulta de precios:</span>';
    catalogo.cadenas.forEach(function (c) {
      var u = catalogo.ultima_actualizacion && catalogo.ultima_actualizacion[c.codigo];
      var horas = u ? horasDesde(u) : null;
      var clase = horas == null ? '' : (horas <= limite ? ' reciente' : ' antigua');
      html += '<span class="sello-fecha' + clase + '">' + esc(c.nombre) + ' · ' + (u ? fecha(u) : 'sin datos aún') + '</span>';
    });
    $('actualizacion').innerHTML = html;
  }
 
  function pintarCatalogo() {
    var html = '';
    catalogo.productos.forEach(function (p) {
      var cant = estado.seleccion[p.id] || 0;
      html += '<div class="producto' + (cant ? ' activo' : '') + '" data-id="' + p.id + '">' +
        '<label><input type="checkbox" ' + (cant ? 'checked' : '') + ' data-accion="alternar">' +
        '<span class="producto__icono">' + icono(iconoCategoria(p.categoria)) +
          (cant ? '<span class="producto__check">' + icono('check') + '</span>' : '') + '</span>' +
        '<span class="producto__texto"><span class="producto__nombre">' + esc(p.nombre) + '</span>' +
        '<span class="producto__pres">' + esc(p.presentacion) + ' · ' + esc(p.categoria) + '</span></span>' +
        (cant ? '' : '<span class="producto__agregar" aria-hidden="true">+</span>') + '</label>' +
        (cant ? '<div class="cantidad" role="group" aria-label="Cantidad de ' + esc(p.nombre) + '"><button type="button" data-accion="menos" aria-label="Quitar una unidad">−</button><span>' + cant +
          '</span><button type="button" data-accion="mas" aria-label="Agregar una unidad">+</button></div>' : '') +
        '</div>';
    });
    $('catalogo').innerHTML = html || '<p class="ayuda">Aún no hay productos activos en el catálogo.</p>';
    actualizarContador();
  }
 
  function actualizarContador() {
    var n = cantidadSeleccionada();
    var listo = n >= MIN && n <= MAX;
    var c = $('contador');
    var largo = n + ' de ' + MAX + (n === 1 ? ' producto' : ' productos') +
      (n < MIN ? ' · elige al menos ' + MIN + ' para comparar' : (n > MAX ? ' · máximo ' + MAX : ' · listo para comparar'));
    var corto = n + '/' + MAX + ' · ' + (n < MIN ? 'elige 1' : 'listo');
    c.innerHTML = '<span class="contador__largo">' + largo + '</span><span class="contador__corto">' + corto + '</span>';
    c.className = 'contador' + (listo ? ' ok' : '');
    var barra = $('progreso');
    barra.style.width = Math.min(100, n / MAX * 100) + '%';
    barra.className = 'progreso__relleno' + (listo ? ' ok' : '');
    $('comparar').disabled = !listo;
  }
 
  $('catalogo').addEventListener('click', function (ev) {
    var objetivo = ev.target.closest('[data-accion]');
    if (!objetivo) { return; }
    var tarjeta = objetivo.closest('.producto');
    var id = tarjeta.getAttribute('data-id');
    var accion = objetivo.getAttribute('data-accion');
    if (accion === 'alternar') {
      if (estado.seleccion[id]) { delete estado.seleccion[id]; }
      else if (cantidadSeleccionada() < MAX) { estado.seleccion[id] = 1; }
      else { objetivo.checked = false; mostrarError('La canasta admite máximo ' + MAX + ' productos.'); return; }
    } else if (accion === 'mas') {
      estado.seleccion[id] = Math.min(20, (estado.seleccion[id] || 1) + 1);
    } else if (accion === 'menos') {
      estado.seleccion[id] = Math.max(1, (estado.seleccion[id] || 1) - 1);
    }
    mostrarError('');
    guardarEstado();
    pintarCatalogo();
    var foco = document.querySelector('.producto[data-id="' + id + '"] [data-accion="' + accion + '"]');
    if (foco && accion !== 'alternar') { foco.focus(); }
  });
 
  $('elegir-todos').addEventListener('click', function () {
    catalogo.productos.forEach(function (p) {
      if (!estado.seleccion[p.id] && cantidadSeleccionada() < MAX) { estado.seleccion[p.id] = 1; }
    });
    mostrarError('');
    guardarEstado();
    pintarCatalogo();
  });
  $('vaciar').addEventListener('click', function () {
    estado.seleccion = {};
    mostrarError('');
    guardarEstado();
    pintarCatalogo();
  });
 
  /* ---------------------------------------------------------- desplazamiento */
  function pintarViaje() {
    var radios = document.querySelectorAll('input[name="modo"]');
    for (var i = 0; i < radios.length; i++) { radios[i].checked = radios[i].value === estado.modo; }
    $('personas').value = estado.personas || 1;
    $('rendimiento').value = estado.rendimiento || '';
    $('rendimiento').placeholder = 'Referencia: ' + (catalogo.parametros.rendimiento_km_galon || 35);
    $('parqueadero').value = estado.parqueadero || '';
    $('parqueadero').placeholder = 'Referencia: ' + (catalogo.parametros.parqueadero || 0);
    $('dist-olimpica').value = estado.distancias.olimpica || '';
    $('dist-d1').value = estado.distancias.d1 || '';
    mostrarOpciones();
  }
 
  function mostrarOpciones() {
    $('op-publico').hidden = estado.modo !== 'publico';
    $('op-carro').hidden = estado.modo !== 'carro';
    $('op-distancia').hidden = !(estado.modo === 'carro' || estado.modo === 'taxi');
  }
 
  document.querySelector('.modos').addEventListener('change', function (ev) {
    estado.modo = ev.target.value;
    mostrarOpciones();
    guardarEstado();
  });
  [['personas', 'personas'], ['rendimiento', 'rendimiento'], ['parqueadero', 'parqueadero']].forEach(function (par) {
    $(par[0]).addEventListener('input', function (ev) { estado[par[1]] = ev.target.value; guardarEstado(); });
  });
  $('dist-olimpica').addEventListener('input', function (ev) { estado.distancias.olimpica = ev.target.value; guardarEstado(); });
  $('dist-d1').addEventListener('input', function (ev) { estado.distancias.d1 = ev.target.value; guardarEstado(); });
 
  function haversineKm(a, b) {
    var R = 6371, rad = Math.PI / 180;
    var dLat = (b.lat - a.lat) * rad, dLon = (b.lon - a.lon) * rad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }
 
  $('ubicacion').addEventListener('click', function () {
    var salida = $('sedes-cercanas');
    if (!catalogo.sedes.length) { salida.textContent = 'Aún no hay sedes registradas. Escribe la distancia manualmente.'; return; }
    if (!navigator.geolocation) { salida.textContent = 'Tu navegador no permite obtener la ubicación. Escribe la distancia manualmente.'; return; }
    salida.textContent = 'Obteniendo tu ubicación aproximada…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      // Se redondea a 3 decimales (≈100 m) y no se envía al servidor
      var origen = { lat: Math.round(pos.coords.latitude * 1000) / 1000, lon: Math.round(pos.coords.longitude * 1000) / 1000 };
      var factor = catalogo.parametros.factor_recorrido || 1.3;
      var textos = [];
      ['olimpica', 'd1'].forEach(function (codigo) {
        var mejor = null;
        catalogo.sedes.forEach(function (s) {
          if (s.cadena !== codigo) { return; }
          var d = haversineKm(origen, { lat: s.latitud, lon: s.longitud }) * factor;
          if (!mejor || d < mejor.d) { mejor = { d: d, sede: s }; }
        });
        if (mejor) {
          var dist = Math.round(mejor.d * 10) / 10;
          estado.distancias[codigo] = String(dist);
          textos.push(nombreCadena(codigo) + ' más cercano: ' + mejor.sede.nombre + ' (' + km(dist) + ' km aprox.)');
        }
      });
      guardarEstado();
      pintarViaje();
      salida.textContent = textos.length ? textos.join(' · ') : 'No se encontraron sedes registradas.';
    }, function () {
      salida.textContent = 'No fue posible obtener la ubicación. Escribe la distancia manualmente.';
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
  });
 
  /* ---------------------------------------------------------- comparación */
  function comparar(desplazar) {
    mostrarError('');
    var boton = $('comparar');
    boton.disabled = true;
    boton.textContent = 'Comparando…';
    var cuerpo = {
      items: Object.keys(estado.seleccion).map(function (id) { return { referencia_id: Number(id), cantidad: estado.seleccion[id] }; }),
      aceptadas: estado.aceptadas,
      modo: estado.modo,
      personas: Number(estado.personas) || 1,
      rendimiento: estado.rendimiento === '' ? null : Number(estado.rendimiento),
      parqueadero: estado.parqueadero === '' ? null : Number(estado.parqueadero),
      distancias: {
        olimpica: estado.distancias.olimpica === '' ? null : Number(estado.distancias.olimpica),
        d1: estado.distancias.d1 === '' ? null : Number(estado.distancias.d1)
      }
    };
    fetch('api/comparar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) })
      .then(function (r) { return r.json(); })
      .then(function (datos) {
        if (!datos.ok) { throw new Error(datos.error || 'No se pudo comparar.'); }
        pintarResultados(datos, desplazar);
      })
      .catch(function (e) { mostrarError(e.message || 'No se pudo comparar. Intenta de nuevo.'); })
      .then(function () { boton.textContent = 'Comparar canasta'; actualizarContador(); });
  }
  $('comparar').addEventListener('click', function () { comparar(true); });
 
  var ETIQUETAS = { exacta: 'Coincidencia exacta', alternativa: 'Alternativa similar', pendiente: 'Por clasificar', sin_coincidencia: 'Sin coincidencia' };
 
  function celdaCadena(c, etiqueta, esMejor) {
    var td = '<td data-label="' + esc(etiqueta) + '"' + (esMejor ? ' class="mejor"' : '') + '>';
    if (!c) { return td + '—</td>'; }
    var precio = '';
    if (c.precio_descuento != null && c.precio_normal != null) { precio += '<span class="tachado">' + dinero(c.precio_normal) + '</span>'; }
    precio += '<span class="precio">' + dinero(c.precio_usado) + '</span>';
    if (esMejor) { precio += '<span class="mejor-marca">Más barato</span>'; }
    if (c.normalizado) { precio += '<span class="publicado">Ajustado a la presentación de referencia: ' + dinero(c.precio_ajustado) + '</span>'; }
    var descripcion = [c.nombre_publicado, c.presentacion !== 'sin dato' ? c.presentacion : ''].filter(Boolean).map(esc).join(' · ');
    return td + precio +
      (descripcion ? '<span class="publicado">' + descripcion + '</span>' : '') +
      '<span class="publicado">' + (c.vigente ? 'Consultado ' : 'No vigente · ') + fecha(c.fecha_hora) + '</span>' +
      (c.manual ? '<span class="etiqueta manual">Registro manual</span>' : '') + '</td>';
  }
 
  /* Diferencia entre la cadena ganadora y la siguiente (en CTA). */
  function ahorro(d, ganador) {
    var g = d.totales[ganador] && d.totales[ganador].cta;
    var otros = Object.keys(d.totales).filter(function (c) { return c !== ganador && d.totales[c].cta != null; })
      .map(function (c) { return d.totales[c].cta; });
    if (g == null || !otros.length) { return null; }
    var dif = Math.min.apply(null, otros) - g;
    return dif > 0 ? dif : null;
  }
 
  function otraCadena(d, codigo) {
    var otras = Object.keys(d.totales).filter(function (c) { return c !== codigo; });
    return otras.length === 1 ? d.totales[otras[0]].nombre : 'la otra cadena';
  }
 
  function bloqueDecision(d) {
    var hay = d.cobertura.comparables > 0;
    var conManual = d.items.some(function (it) {
      return it.estado === 'comparable' && Object.keys(it.cadenas).some(function (c) { return it.cadenas[c] && it.cadenas[c].manual; });
    });
    var chips = '<div class="chips"><span class="chip">' + d.cobertura.comparables + ' de ' + d.cobertura.total + ' productos comparados</span>' +
      '<span class="chip">' + esc(MODOS[d.modo] || d.modo) + '</span>' +
      (conManual ? '<span class="chip">Incluye precios de registro manual</span>' : '') + '</div>';
    var variante = '', ico = 'check', titulo = '', texto = '';
 
    if (!hay) {
      variante = 'neutra'; ico = 'info'; titulo = 'Aún no se puede comparar';
      texto = 'Ningún producto de tu canasta tiene precio vigente en las dos cadenas. Intenta más tarde o cambia algunos productos.';
    } else if (d.ganador_cta === 'empate') {
      ico = 'igual'; titulo = 'Empate en el costo total';
      texto = 'Las dos cadenas te cuestan lo mismo, contando el desplazamiento.';
    } else if (d.ganador_cta) {
      var g = nombreCadena(d.ganador_cta), a = ahorro(d, d.ganador_cta);
      if (d.cambia_decision) {
        variante = 'giro'; ico = 'giro'; titulo = 'El costo de llegar cambia la decisión';
        texto = '<strong>' + esc(nombreCadena(d.ganador_precio)) + '</strong> tiene la canasta más barata, pero <strong>' + esc(g) +
          '</strong> sale más económica al sumar el desplazamiento' + (a ? ': ahorras <strong>' + dinero(a) + '</strong>.' : '.');
      } else {
        titulo = g + ' es la opción más económica';
        texto = (a ? 'Ahorras <strong>' + dinero(a) + '</strong> en el costo total frente a ' + esc(otraCadena(d, d.ganador_cta)) + '.' : '') +
          (d.ganador_precio === d.ganador_cta ? ' También tiene la canasta más barata.' : '');
      }
    } else if (d.ganador_precio && d.ganador_precio !== 'empate') {
      variante = 'neutra'; ico = 'info'; titulo = nombreCadena(d.ganador_precio) + ' tiene la canasta más barata';
      texto = 'Para sumar el costo de llegar, indica la distancia a cada tienda en el paso 2.';
    } else {
      variante = 'neutra'; ico = 'info'; titulo = 'Resultado parcial';
      texto = 'Revisa las advertencias para completar la comparación.';
    }
    return '<div class="decision' + (variante ? ' decision--' + variante : '') + '"><span class="decision__icono">' + icono(ico) + '</span>' +
      '<div><p class="decision__titulo">' + esc(titulo) + '</p>' + (texto ? '<p class="decision__texto">' + texto + '</p>' : '') + chips + '</div></div>';
  }
 
  function pintarResultados(d, desplazar) {
    var cods = Object.keys(d.totales);
    var hay = d.cobertura.comparables > 0;
    var html = '<div class="paso__encabezado"><span class="paso__num" aria-hidden="true">3</span><div>' +
      '<h2>Resultado de la comparación</h2><p class="ayuda">Calculado el ' + fecha(d.generado_en) + '</p></div></div>';
 
    html += bloqueDecision(d);
 
    var maximo = 0;
    cods.forEach(function (codigo) {
      var t = d.totales[codigo];
      maximo = Math.max(maximo, (hay ? t.pc : 0) + (t.ca || 0));
    });
 
    html += '<div class="resumen">';
    cods.forEach(function (codigo) {
      var t = d.totales[codigo];
      var gana = d.ganador_cta === codigo;
      var pc = hay ? t.pc : null;
      var anchoPc = maximo ? (pc || 0) / maximo * 100 : 0;
      var anchoCa = maximo ? (t.ca || 0) / maximo * 100 : 0;
      html += '<article class="total' + (gana ? ' ganador' : '') + '">' +
        '<div class="total__cabeza"><h3 class="total__cadena">' + esc(t.nombre) + '</h3>' +
          (gana ? '<span class="insignia">' + icono('check') + 'Menor costo total</span>' : '') + '</div>' +
        '<p class="total__etiqueta">Costo total (CTA)</p><p class="total__cta">' + dinero(t.cta) + '</p>' +
        (maximo ? '<div class="barra-cta" role="img" aria-label="Precio de la canasta ' + dinero(pc) + ' más costo de llegar ' + dinero(t.ca) + '">' +
          '<span class="barra-cta__pc" style="width:' + anchoPc.toFixed(2) + '%"></span><span class="barra-cta__ca" style="width:' + anchoCa.toFixed(2) + '%"></span></div>' : '') +
        '<dl class="desglose"><dt>Precio de la canasta (Pc)</dt><dd>' + dinero(pc) + '</dd>' +
        '<dt class="ca">Costo de llegar (Ca)</dt><dd>' + dinero(t.ca) + '</dd></dl>' +
        '<small>' + esc(t.ca_detalle) + (t.distancia_km != null ? ' · ' + km(t.distancia_km) + ' km por trayecto' : '') + '</small></article>';
    });
    html += '</div>';
 
    if (d.advertencias && d.advertencias.length) {
      html += '<ul class="advertencias">' + d.advertencias.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>';
    }
 
    html += '<div class="tabla"><table><thead><tr><th>Producto</th><th>Cant.</th>' +
      cods.map(function (c) { return '<th>' + esc(d.totales[c].nombre) + '</th>'; }).join('') + '<th>Estado</th></tr></thead><tbody>';
    d.items.forEach(function (it) {
      var excl = it.estado !== 'comparable';
      var mejor = null;
      if (!excl) {
        var valores = cods.map(function (c) { return it.cadenas[c] ? it.cadenas[c].subtotal : null; });
        var min = Math.min.apply(null, valores.filter(function (v) { return v != null; }));
        var cuantos = valores.filter(function (v) { return v === min; }).length;
        if (cuantos === 1) { mejor = cods[valores.indexOf(min)]; }
      }
      html += '<tr class="' + (excl ? 'excluido' : '') + '"><td><strong>' + esc(it.nombre) + '</strong><span class="publicado">' + esc(it.presentacion) + '</span></td>' +
        '<td class="num" data-label="Cantidad">' + it.cantidad + '</td>' +
        cods.map(function (c) { return celdaCadena(it.cadenas[c], d.totales[c].nombre, c === mejor); }).join('') +
        '<td data-label="Estado"><span class="etiqueta ' + esc(it.tipo) + '">' + esc(ETIQUETAS[it.tipo] || it.tipo) + '</span><span class="publicado">' + esc(it.motivo) + '</span>';
      if (it.tipo === 'alternativa' && (it.estado === 'por_aceptar' || it.estado === 'comparable')) {
        var marcado = estado.aceptadas.indexOf(it.referencia_id) !== -1;
        html += '<label class="aceptar"><input type="checkbox" data-aceptar="' + it.referencia_id + '"' + (marcado ? ' checked' : '') + '> Acepto la alternativa</label>';
      }
      html += '</td></tr>';
    });
    html += '</tbody></table></div>';
    html += '<p class="aviso-final">' + esc(d.aviso) + '</p>';
 
    var cont = $('resultados');
    cont.innerHTML = html;
    cont.hidden = false;
    if (desplazar) { cont.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
 
  $('resultados').addEventListener('change', function (ev) {
    var id = ev.target.getAttribute('data-aceptar');
    if (!id) { return; }
    id = Number(id);
    var i = estado.aceptadas.indexOf(id);
    if (ev.target.checked && i === -1) { estado.aceptadas.push(id); }
    if (!ev.target.checked && i !== -1) { estado.aceptadas.splice(i, 1); }
    guardarEstado();
    comparar(false);
  });
 
  cargarCatalogo();
})();