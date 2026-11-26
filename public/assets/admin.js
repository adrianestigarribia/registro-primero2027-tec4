// Panel de administración de la inscripción a 1° año.
// - Login con contraseña: el servidor abre una sesión (cookie HttpOnly). Este archivo nunca
//   guarda la contraseña ni datos de alumnos en el navegador (ni localStorage ni sessionStorage).
// - Se actualiza solo: cada POLL_MS pide a /api/inscripciones lo que cambió desde la última vez
//   (?since=cursor) y los contadores. Si la pestaña está oculta, deja de consultar.
// - Todo se muestra con textContent (función el()), nunca con innerHTML.
(() => {
  const $ = (s) => document.querySelector(s);
  const POLL_MS = 3000;

  const state = {
    items: new Map(),    // id -> inscripción (sin anuladas)
    cursor: null,        // desde cuándo pedir cambios
    timer: null,
    activo: false,       // hay sesión abierta
    primeraCarga: true,
    filtro: 'todos',
    busqueda: '',
    contadores: null,
    nuevos: new Set(),   // ids que llegaron por polling (se resaltan un momento)
    anulando: null,      // inscripción que se está por anular
  };

  /* ================= Utilidades ================= */
  function el(tag, attrs = {}, ...hijos) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k.includes('-')) n.setAttribute(k, v);
      else n[k] = v;
    }
    for (const h of hijos.flat()) if (h != null && h !== false) n.append(h);
    return n;
  }

  const ETIQUETA = { ingresante: 'Ingresante', espera: 'Lista de Espera', fuera_zona: 'Fuera de zona' };
  const ORDEN_ESTADO = { ingresante: 0, espera: 1, fuera_zona: 2 };

  // Texto sin tildes y en minúsculas, para buscar "perez" y encontrar "Pérez"
  const norm = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const fechaHora = (ms) => (ms ? new Date(ms).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }) : '');
  const fechaNac = (iso) => (iso ? iso.split('-').reverse().join('/') : '');

  let toastTimer;
  function toast(msg, { warn = false, ms = 4500 } = {}) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('warn', warn);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, ms);
  }

  // Pedido a la API. Si la sesión venció (401), vuelve al login.
  async function api(path, opts = {}) {
    const r = await fetch(path, {
      ...opts,
      cache: 'no-store',
      credentials: 'same-origin',
      headers: opts.body ? { 'Content-Type': 'application/json' } : {},
    });
    const data = await r.json().catch(() => ({}));
    if (r.status === 401 && path !== 'api/login') {
      cerrar(data.error || 'Tu sesión terminó. Volvé a entrar.');
      const e = new Error('auth'); e.auth = true; throw e;
    }
    return { status: r.status, data };
  }
  const post = (path, body) => api(path, { method: 'POST', body: JSON.stringify(body || {}) });

  /* ================= Login ================= */
  $('#login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#login-error');
    const clave = $('#clave').value;
    err.hidden = true;
    if (clave.length < 6) {
      err.textContent = 'La contraseña tiene al menos 6 caracteres.';
      err.hidden = false;
      return;
    }
    $('#login-btn').disabled = true;
    try {
      const { status, data } = await post('api/login', { clave });
      if (status !== 200) throw new Error(data.error || 'No se pudo entrar.');
      $('#clave').value = '';
      $('#aviso-clave').hidden = !data.debeCambiar;
      abrir();
    } catch (ex) {
      err.textContent = ex instanceof TypeError ? 'No se pudo conectar con el servidor. Revisá la conexión.' : ex.message;
      err.hidden = false;
      $('#login-btn').disabled = false;
      $('#clave').focus();
    }
  });

  function abrir() {
    state.items.clear();
    state.cursor = null;
    state.primeraCarga = true;
    state.activo = true;
    poll();
  }

  // Vuelve al login y borra de la memoria todo lo que se había cargado
  function cerrar(msg) {
    clearTimeout(state.timer);
    state.activo = false;
    state.items.clear();
    state.cursor = null;
    $('#filas').replaceChildren();
    document.querySelectorAll('dialog[open]').forEach((d) => d.close());
    $('#view-panel').hidden = true;
    $('#view-login').hidden = false;
    $('#login-btn').disabled = false;
    const err = $('#login-error');
    err.textContent = msg || '';
    err.hidden = !msg;
    $('#clave').focus();
  }

  $('#btn-salir').addEventListener('click', async () => {
    try { await post('api/logout'); } catch {}
    cerrar();
  });

  /* ================= Sincronización (polling) ================= */
  function setLive(ok) {
    const l = $('#live');
    l.classList.toggle('on', ok);
    l.classList.toggle('off', !ok);
    $('#live-text').textContent = ok ? 'En vivo' : 'Sin conexión, reintentando…';
  }

  // Aplica filas que llegaron del servidor (polling o respuesta de anular)
  function aplicar(filas, marcarNuevas) {
    let cambios = false;
    for (const f of filas) {
      const previo = state.items.get(f.id);
      if (f.anulada) {
        if (previo) { state.items.delete(f.id); cambios = true; }
        continue;
      }
      if (!previo && marcarNuevas) state.nuevos.add(f.id);
      if (!previo || JSON.stringify(previo) !== JSON.stringify(f)) cambios = true;
      state.items.set(f.id, f);
    }
    return cambios;
  }

  async function poll() {
    clearTimeout(state.timer);
    if (!state.activo) return;
    try {
      const qs = state.cursor ? `?since=${encodeURIComponent(state.cursor)}` : '';
      const { status, data } = await api(`api/inscripciones${qs}`);
      if (status !== 200) throw new Error(data.error || 'Error del servidor');

      if (state.primeraCarga) {
        $('#view-login').hidden = true;
        $('#view-panel').hidden = false;
        medirTopbar();
      }
      const cambios = aplicar(data.inscripciones, !state.primeraCarga);
      const contCambio = JSON.stringify(state.contadores) !== JSON.stringify(data.contadores);
      state.contadores = data.contadores;
      state.cursor = data.cursor;
      const primera = state.primeraCarga;
      state.primeraCarga = false;
      setLive(true);
      if (cambios || contCambio || primera) render();
    } catch (e) {
      if (e.auth) return;
      if (state.primeraCarga) {
        cerrar('No se pudo conectar con el servidor. Revisá la conexión y probá de nuevo.');
        return;
      }
      setLive(false);
    }
    if (!document.hidden) state.timer = setTimeout(poll, POLL_MS);
  }

  // Pestaña oculta: no consulta. Al volver, consulta enseguida.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && state.activo && !state.primeraCarga) poll();
  });

  /* ================= Filtros y búsqueda ================= */
  document.querySelectorAll('.stat').forEach((b) => b.addEventListener('click', () => {
    state.filtro = b.dataset.filtro;
    document.querySelectorAll('.stat').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    render();
  }));

  let buscarTimer;
  $('#buscar').addEventListener('input', (e) => {
    clearTimeout(buscarTimer);
    buscarTimer = setTimeout(() => { state.busqueda = e.target.value; render(); }, 120);
  });

  // Busca por apellido (o nombre) del alumno, o por DNI (con o sin puntos)
  function coincide(f, q) {
    if (!q) return true;
    const digitos = q.replace(/[.\s]/g, '');
    if (/^\d+$/.test(digitos)) return f.dni.includes(digitos);
    const texto = norm(`${f.alumnoApellido} ${f.alumnoNombre}`);
    return norm(q).split(/\s+/).filter(Boolean).every((p) => texto.includes(p));
  }

  /* ================= Pantalla ================= */
  function renderContadores() {
    const c = state.contadores;
    if (!c) return;
    $('#n-ocupadas').textContent = c.ocupadas;
    $('#n-cupo').textContent = c.cupo;
    const libres = $('#n-libres');
    libres.textContent = c.libres > 0 ? `${c.libres} libre${c.libres === 1 ? '' : 's'}` : 'Cupo completo';
    libres.classList.toggle('lleno', c.libres === 0);
    const relleno = $('#cupo-relleno');
    relleno.style.width = `${Math.min(100, (c.ocupadas / Math.max(1, c.cupo)) * 100)}%`; // CSSOM: permitido por la CSP
    relleno.classList.toggle('lleno', c.libres === 0);
    $('#n-ing').textContent = c.ocupadas;
    $('#n-esp').textContent = c.espera;
    $('#n-fz').textContent = c.fueraZona;
    $('#n-total').textContent = c.ocupadas + c.espera + c.fueraZona;
  }

  // Posición de cada uno en la lista de espera (1 = el próximo en entrar)
  function posicionesEspera() {
    const pos = new Map();
    [...state.items.values()]
      .filter((f) => f.estado === 'espera')
      .sort((a, b) => a.orden - b.orden)
      .forEach((f, i) => pos.set(f.id, i + 1));
    return pos;
  }

  function render() {
    renderContadores();
    const pos = posicionesEspera();
    const q = state.busqueda.trim();
    const lista = [...state.items.values()]
      .filter((f) => state.filtro === 'todos' || f.estado === state.filtro)
      .filter((f) => coincide(f, q))
      .sort((a, b) => ORDEN_ESTADO[a.estado] - ORDEN_ESTADO[b.estado] || a.orden - b.orden);

    $('#filas').replaceChildren(...lista.map((f) => fila(f, pos.get(f.id))));
    state.nuevos.clear();

    const vacio = $('#vacio');
    vacio.hidden = lista.length > 0;
    $('#tabla').hidden = lista.length === 0;
    if (!lista.length) {
      vacio.textContent = q ? `No hay inscripciones que coincidan con "${q}".` : 'Todavía no hay inscripciones en esta lista.';
    }
    const total = state.filtro === 'todos' ? state.items.size
      : [...state.items.values()].filter((f) => f.estado === state.filtro).length;
    $('#resumen').textContent = q ? `${lista.length} de ${total} coinciden con la búsqueda.` : `${lista.length} ${lista.length === 1 ? 'inscripción' : 'inscripciones'}.`;
  }

  function fila(f, posEspera) {
    const domicilio = `${f.calle} ${f.numero}${f.pisoDepto ? `, ${f.pisoDepto}` : ''}`;
    const tags = [el('span', { class: `tag tag-${f.estado}` }, ETIQUETA[f.estado])];
    if (posEspera) tags.push(el('span', { class: 'tag tag-info' }, `${posEspera}° en espera`));
    if (f.promovidaMs) tags.push(el('span', { class: 'tag tag-info', title: `Pasó de la lista de espera el ${fechaHora(f.promovidaMs)}` }, 'Promovida'));
    if (f.alerta) tags.push(el('span', { class: 'tag tag-alerta', title: 'Marca del antiabuso: conviene revisar los datos' }, `⚠ ${f.alerta}`));

    return el('tr', { class: `${f.estado}${state.nuevos.has(f.id) ? ' is-new' : ''}` },
      el('td', { class: 'c-orden', 'data-label': 'Orden' },
        el('span', { class: 'orden' }, `#${f.orden}`), el('span', { class: 'sub num' }, `Solicitud N° ${f.id}`)),
      el('td', { class: 'c-estado' }, tags),
      el('td', { class: 'c-alumno nombre' }, el('b', {}, f.alumnoApellido), `, ${f.alumnoNombre}`),
      el('td', { class: 'c-dni dni', 'data-label': 'DNI' }, f.dni),
      el('td', { class: 'c-nac', 'data-label': 'Nacimiento' }, fechaNac(f.fechaNacimiento)),
      el('td', { class: 'c-escuela', 'data-label': 'Escuela primaria' }, f.escuela),
      el('td', { class: 'c-dom', 'data-label': 'Domicilio' }, domicilio, el('span', { class: 'sub' }, `CP ${f.cp}`)),
      el('td', { class: 'c-contacto', 'data-label': 'Adulto responsable' },
        `${f.responsableApellido}, ${f.responsableNombre}`, el('span', { class: 'sub' }, f.email)),
      el('td', { class: 'c-fecha', 'data-label': 'Enviada' }, fechaHora(f.createdMs)),
      el('td', { class: 'c-acc' },
        el('button', { class: 'btn btn-anular', type: 'button', onclick: () => abrirAnular(f.id) }, 'Anular')),
    );
  }

  /* ================= Anular ================= */
  function abrirAnular(id) {
    const f = state.items.get(id);
    if (!f) return;
    state.anulando = f;
    $('#anular-error').hidden = true;
    $('#anular-ok').disabled = false;
    $('#anular-quien').textContent = `#${f.orden} · ${f.alumnoApellido}, ${f.alumnoNombre} · DNI ${f.dni} (${ETIQUETA[f.estado]})`;
    const efecto = $('#anular-efecto');
    efecto.className = '';
    if (f.estado === 'ingresante') {
      const primero = [...state.items.values()].filter((x) => x.estado === 'espera').sort((a, b) => a.orden - b.orden)[0];
      if (primero) {
        efecto.className = 'efecto';
        efecto.textContent = `Se libera una vacante y pasa a Ingresante el primero de la lista de espera: ${primero.alumnoApellido}, ${primero.alumnoNombre} (#${primero.orden}).`;
      } else {
        efecto.textContent = 'Se libera una vacante. No hay nadie en lista de espera: la ocupará la próxima inscripción de Berazategui.';
      }
    } else {
      efecto.textContent = f.estado === 'espera' ? 'Sale de la lista de espera. No cambia ninguna vacante.' : 'No cambia ninguna vacante.';
    }
    $('#dlg-anular').showModal();
  }

  $('#form-anular').addEventListener('submit', async (e) => {
    // "Cancelar" cierra solo (method="dialog"); "Anular" llama a la API
    if (e.submitter?.value !== 'anular') return;
    e.preventDefault();
    const f = state.anulando;
    if (!f) return;
    $('#anular-ok').disabled = true;
    try {
      const { status, data } = await post('api/anular', { id: f.id });
      if (status !== 200) {
        if (status === 409 || status === 404) {
          // Ya la anularon desde otro dispositivo: se actualiza la lista
          $('#dlg-anular').close();
          toast(data.error, { warn: true });
          poll();
          return;
        }
        throw new Error(data.error || 'No se pudo anular.');
      }
      aplicar([data.anulada, ...data.promovidas], false);
      state.contadores = data.contadores;
      render();
      $('#dlg-anular').close();
      const p = data.promovidas[0];
      toast(p
        ? `Anulada. Pasó a Ingresante: ${p.alumnoApellido}, ${p.alumnoNombre} (#${p.orden}).`
        : 'Inscripción anulada.', { ms: 7000 });
    } catch (ex) {
      if (ex.auth) return;
      const err = $('#anular-error');
      err.textContent = ex instanceof TypeError ? 'Sin conexión. Probá de nuevo.' : ex.message;
      err.hidden = false;
      $('#anular-ok').disabled = false;
    }
  });

  /* ================= Exportar CSV ================= */
  // Se baja con fetch (y no con un link común) para poder avisar si la sesión venció
  $('#btn-exportar').addEventListener('click', async () => {
    const btn = $('#btn-exportar');
    btn.disabled = true;
    try {
      const r = await fetch('api/exportar', { cache: 'no-store', credentials: 'same-origin' });
      if (r.status === 401) { cerrar('Tu sesión terminó. Volvé a entrar.'); return; }
      if (!r.ok) throw new Error('No se pudo generar el archivo.');
      const nombre = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '')?.[1] || 'inscripciones.csv';
      const url = URL.createObjectURL(await r.blob());
      const a = el('a', { href: url, download: nombre });
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      toast('Se descargó el listado. Tiene datos de menores: guardalo en un lugar seguro.');
    } catch (ex) {
      toast(ex instanceof TypeError ? 'Sin conexión. Probá de nuevo.' : ex.message, { warn: true });
    } finally {
      btn.disabled = false;
    }
  });

  /* ================= Cambiar contraseña ================= */
  const dlgClave = $('#dlg-clave');
  function abrirClave() {
    $('#form-clave').reset();
    marcarErrores({});
    $('#clave-error').hidden = true;
    $('#clave-ok').disabled = false;
    dlgClave.showModal();
    $('#clave-actual').focus();
  }
  $('#btn-clave').addEventListener('click', abrirClave);
  $('#aviso-clave-btn').addEventListener('click', abrirClave);
  $('#clave-cancelar').addEventListener('click', () => dlgClave.close());

  function marcarErrores(errores) {
    document.querySelectorAll('#form-clave .field').forEach((f) => {
      f.classList.remove('has-error');
      f.querySelector('.field-error')?.remove();
      const msg = errores[f.dataset.field];
      if (msg) {
        f.classList.add('has-error');
        f.append(el('p', { class: 'field-error' }, msg));
      }
    });
  }

  $('#form-clave').addEventListener('submit', async (e) => {
    e.preventDefault();
    const actual = $('#clave-actual').value;
    const nueva = $('#clave-nueva').value;
    const errores = {};
    if (!actual) errores.actual = 'Escribí la contraseña actual.';
    if (nueva.length < 6 || nueva.length > 72) errores.nueva = 'Entre 6 y 72 caracteres.';
    else if (nueva === actual) errores.nueva = 'Tiene que ser distinta de la actual.';
    if ($('#clave-repetir').value !== nueva) errores.repetir = 'No coincide con la nueva.';
    marcarErrores(errores);
    if (Object.keys(errores).length) return;

    $('#clave-ok').disabled = true;
    try {
      const { status, data } = await post('api/clave', { actual, nueva });
      if (status !== 200) {
        marcarErrores(data.errores || {});
        throw new Error(data.error || 'No se pudo cambiar la contraseña.');
      }
      dlgClave.close();
      $('#aviso-clave').hidden = true;
      toast('Contraseña cambiada. Los demás dispositivos tienen que volver a entrar.');
    } catch (ex) {
      if (ex.auth) return;
      const err = $('#clave-error');
      err.textContent = ex instanceof TypeError ? 'Sin conexión. Probá de nuevo.' : ex.message;
      err.hidden = false;
      $('#clave-ok').disabled = false;
    }
  });

  /* ================= Arranque ================= */
  // Alto de la barra fija, para que los títulos de la tabla queden pegados justo debajo
  function medirTopbar() {
    document.documentElement.style.setProperty('--topbar-h', `${$('#topbar').offsetHeight}px`);
  }
  window.addEventListener('resize', medirTopbar);

  // Si ya hay una sesión abierta (por ejemplo, se recargó la página), entra directo
  // (fetch directo y no api(): sin sesión, el 401 es lo normal y no hay que mostrar "tu sesión terminó")
  (async () => {
    try {
      const r = await fetch('api/cupos', { cache: 'no-store', credentials: 'same-origin' });
      if (r.status === 200) { abrir(); return; }
    } catch { /* sin conexión: queda el login */ }
    $('#clave').focus();
  })();
})();
