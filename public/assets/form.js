// Formulario público de inscripción a 1° año.
// - Valida en el navegador para avisar rápido, pero la validación que vale es la del servidor
//   (api/_validar.php): acá se repiten las mismas reglas.
// - Muestra los datos siempre con textContent (nunca innerHTML), así nada de lo escrito se
//   puede interpretar como HTML.
// - No guarda datos del alumno en el celular (ni localStorage ni cookies).
(() => {
  const $ = (s) => document.querySelector(s);
  const form = $('#form');
  const viewForm = $('#view-form');
  const viewCargando = $('#view-cargando');
  const viewConst = $('#view-constancia');
  const submitBtn = $('#submit');
  const formError = $('#form-error');
  const themeColor = document.querySelector('meta[name="theme-color"]');
  const CARGANDO_MIN_MS = 600; // "Enviando…" se ve al menos esto, así no es un parpadeo

  // Igual que _validar.php
  const LETRAS = 'A-Za-zÀ-ÖØ-öø-ÿ';
  const RE_NOMBRE = new RegExp(`^[${LETRAS}]+(?:['’-][${LETRAS}]+)*(?: [${LETRAS}]+(?:['’-][${LETRAS}]+)*)*$`);
  const NO_NOMBRE = new RegExp(`[^${LETRAS} '’-]`, 'g');
  const RE_DIRECCION = new RegExp(`^[${LETRAS}0-9][${LETRAS}0-9 .,°º'’"\\-/#()]*$`);
  const RE_NUMERO = /^(?:\d{1,6}(?: ?(?:bis|[A-Za-z]))?|S\/N)$/i;
  const RE_PISO = /^[A-Za-z0-9][A-Za-z0-9 .°º\-/]*$/;
  const RE_CP = /^[A-Z]?(\d{4})(?:[A-Z]{3})?$/;
  const DNI_MIN = 2000000, DNI_MAX_ARG = 64999999, DNI_EXTRANJERO = 90000000;
  const dniPlausible = (n) => n >= DNI_MIN && !(n > DNI_MAX_ARG && n < DNI_EXTRANJERO);

  // Rango de nacimiento: llega de /api/ping (sale de _config.php). Mientras no llegue,
  // el navegador no controla el rango y lo controla solo el servidor.
  let rango = null; // { desde: 'AAAA-MM-DD', hasta: 'AAAA-MM-DD', edadMin, edadMax }
  let ciclo = null;

  // Cuándo se abrió el formulario: el servidor marca para revisar lo enviado demasiado rápido
  let abierto = Date.now();

  /* ---------- Fecha de nacimiento: listas de día y año ---------- */
  for (let d = 1; d <= 31; d++) {
    const o = document.createElement('option');
    o.value = String(d).padStart(2, '0');
    o.textContent = String(d);
    form.nacDia.appendChild(o);
  }
  function armarAnios(desde, hasta) {
    const elegido = form.nacAnio.value;
    form.nacAnio.length = 1; // deja solo "Año"
    for (let a = hasta; a >= desde; a--) { // primero los más chicos: es lo más común
      const o = document.createElement('option');
      o.value = o.textContent = String(a);
      form.nacAnio.appendChild(o);
    }
    if (elegido) form.nacAnio.value = elegido;
  }

  // Warm-up de la base + configuración del ciclo lectivo
  fetch('api/ping', { cache: 'no-store' })
    .then((r) => r.json())
    .then((c) => {
      if (!c || !c.nacimiento || !Number.isInteger(c.ciclo)) return;
      rango = c.nacimiento;
      ciclo = c.ciclo;
      armarAnios(Number(rango.desde.slice(0, 4)), Number(rango.hasta.slice(0, 4)));
      $('#hero-ciclo').textContent = `Ciclo lectivo ${ciclo}`;
      document.querySelectorAll('.ciclo-txt').forEach((s) => { s.textContent = String(ciclo); });
    })
    .catch(() => {});

  /* ---------- Filtros mientras se escribe ---------- */
  // Nombres: letras, espacios, ' y -. Lo demás no se puede escribir.
  for (const n of ['alumnoApellido', 'alumnoNombre', 'responsableApellido', 'responsableNombre']) {
    const campo = form[n];
    campo.addEventListener('input', () => {
      const limpio = campo.value.normalize('NFC').replace(NO_NOMBRE, '').replace(/ {2,}/g, ' ').replace(/^ /, '');
      if (limpio !== campo.value) campo.value = limpio;
    });
  }
  // DNI: solo números (si pegan "50.123.456", quedan los números)
  form.dni.addEventListener('input', () => {
    const limpio = form.dni.value.replace(/\D/g, '').slice(0, 8);
    if (limpio !== form.dni.value) form.dni.value = limpio;
  });
  // CP: mayúsculas, solo letras y números
  form.cp.addEventListener('input', () => {
    const limpio = form.cp.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
    if (limpio !== form.cp.value) form.cp.value = limpio;
  });

  form.addEventListener('input', (e) => limpiarError(nombreCampo(e.target.name)));
  form.addEventListener('change', (e) => limpiarError(nombreCampo(e.target.name)));
  // Los tres selects de la fecha comparten un solo mensaje de error
  const nombreCampo = (n) => (n === 'nacDia' || n === 'nacMes' || n === 'nacAnio' ? 'fechaNacimiento' : n);

  /* ---------- Errores ---------- */
  function limpiarError(nombre) {
    const field = form.querySelector(`[data-field="${nombre}"]`);
    if (!field) return;
    field.classList.remove('has-error');
    field.querySelector('.field-error')?.remove();
    field.querySelectorAll('[aria-invalid]').forEach((i) => i.removeAttribute('aria-invalid'));
  }
  function mostrarErrores(errores) {
    form.querySelectorAll('.field.has-error').forEach((f) => limpiarError(f.dataset.field));
    let primero = null;
    for (const [nombre, msg] of Object.entries(errores)) {
      const field = form.querySelector(`[data-field="${nombre}"]`);
      if (!field) continue;
      field.classList.add('has-error');
      const p = document.createElement('p');
      p.className = 'field-error';
      p.id = `err-${nombre}`;
      p.textContent = msg;
      field.appendChild(p);
      field.querySelectorAll('input, select').forEach((i) => {
        i.setAttribute('aria-invalid', 'true');
        i.setAttribute('aria-describedby', p.id);
      });
      primero ||= field;
    }
    if (primero) {
      primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
      primero.querySelector('input, select')?.focus({ preventScroll: true });
    }
  }
  function mostrarErrorGeneral(msg) {
    formError.textContent = msg;
    formError.hidden = false;
    formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    formError.focus({ preventScroll: true });
  }

  /* ---------- Validación (las mismas reglas que el servidor) ---------- */
  const limpiar = (v) => v.normalize('NFC').trim().replace(/\s+/g, ' ');

  function errorNombre(v, falta) {
    if (v.length < 2) return falta;
    if (v.length > 80) return 'Es demasiado largo (máximo 80 caracteres).';
    if (/\d/.test(v)) return 'No puede tener números.';
    if (!RE_NOMBRE.test(v)) return "Solo letras y espacios (se aceptan ' y - entre letras).";
    return null;
  }
  function errorTexto(v, max, re, falta, formato, min = 2) {
    if (v.length < min) return falta;
    if (v.length > max) return `Es demasiado largo (máximo ${max} caracteres).`;
    if (!re.test(v)) return formato;
    return null;
  }
  function fechaReal(f) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(f);
    if (!m) return false;
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3];
  }

  function validar(d) {
    const e = {};
    let x;
    if ((x = errorNombre(d.alumnoApellido, 'Escribí el apellido del alumno.'))) e.alumnoApellido = x;
    if ((x = errorNombre(d.alumnoNombre, 'Escribí el nombre del alumno.'))) e.alumnoNombre = x;

    if (!/^\d{7,8}$/.test(d.dni)) e.dni = 'El DNI tiene que tener 7 u 8 números.';
    else if (!dniPlausible(Number(d.dni))) e.dni = 'Revisá el DNI: ese número no corresponde a un documento válido.';

    if (!form.nacDia.value || !form.nacMes.value || !form.nacAnio.value) e.fechaNacimiento = 'Elegí día, mes y año de nacimiento.';
    else if (!fechaReal(d.fechaNacimiento)) e.fechaNacimiento = 'Esa fecha no existe. Revisá el día y el mes.';
    else if (rango && (d.fechaNacimiento < rango.desde || d.fechaNacimiento > rango.hasta)) {
      e.fechaNacimiento = `Para 1° año ${ciclo} el alumno tiene que tener entre ${rango.edadMin} y ${rango.edadMax} años al 30/06/${ciclo}.`;
    }

    if ((x = errorTexto(d.escuela, 120, RE_DIRECCION, 'Escribí la escuela primaria.', 'Usá letras, números y signos comunes (. , ° - /).'))) e.escuela = x;
    if ((x = errorTexto(d.calle, 100, RE_DIRECCION, 'Escribí la calle.', 'Usá letras, números y signos comunes (. , ° - /).'))) e.calle = x;
    if ((x = errorTexto(d.numero, 10, RE_NUMERO, 'Escribí el número (o S/N si no tiene).', 'Solo el número, por ejemplo 1234, 1234 bis o S/N.', 1))) e.numero = x;
    if (d.pisoDepto && (x = errorTexto(d.pisoDepto, 20, RE_PISO, '', 'Solo letras, números, . ° - y /. Por ejemplo: 2° B.', 1))) e.pisoDepto = x;

    if (!d.cp) e.cp = 'Escribí el código postal.';
    else if (!RE_CP.test(d.cp)) e.cp = 'Son 4 números (por ejemplo 1884) o el formato B1884ABC.';

    if (!d.email) e.email = 'Escribí un email de contacto.';
    else if (d.email.length > 120) e.email = 'El email es demasiado largo (máximo 120 caracteres).';
    else if (!/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(d.email) || !form.email.checkValidity()) e.email = 'Revisá el email: tiene que ser como nombre@ejemplo.com.';

    if ((x = errorNombre(d.responsableApellido, 'Escribí el apellido del adulto responsable.'))) e.responsableApellido = x;
    if ((x = errorNombre(d.responsableNombre, 'Escribí el nombre del adulto responsable.'))) e.responsableNombre = x;

    if (!d.privacidad) e.privacidad = 'Para enviar, leé y aceptá el aviso de privacidad.';
    return e;
  }

  /* ---------- Envío ---------- */
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    formError.hidden = true;

    const datos = {
      alumnoApellido: limpiar(form.alumnoApellido.value),
      alumnoNombre: limpiar(form.alumnoNombre.value),
      dni: form.dni.value.replace(/\D/g, ''),
      fechaNacimiento: `${form.nacAnio.value}-${form.nacMes.value}-${form.nacDia.value}`,
      escuela: limpiar(form.escuela.value),
      calle: limpiar(form.calle.value),
      numero: limpiar(form.numero.value),
      pisoDepto: limpiar(form.pisoDepto.value),
      cp: form.cp.value.toUpperCase().replace(/\s/g, ''),
      email: limpiar(form.email.value).toLowerCase(),
      responsableApellido: limpiar(form.responsableApellido.value),
      responsableNombre: limpiar(form.responsableNombre.value),
      privacidad: form.privacidad.checked,
      web: form.web.value,              // campo trampa: una persona lo deja vacío
      t: Date.now() - abierto,          // ms desde que se abrió el formulario
    };

    const errores = validar(datos);
    if (Object.keys(errores).length) {
      mostrarErrorGeneral('Revisá los datos marcados en rojo.');
      return mostrarErrores(errores);
    }

    submitBtn.disabled = true;
    mostrarCargando();
    const inicio = Date.now();
    const lentoTimer = setTimeout(() => {
      $('#cargando-sub').textContent = 'Hay muchas familias enviando a la vez. Esperá, no cierres esta pantalla.';
    }, 5000);
    try {
      const r = await fetch('api/registro', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos),
      });
      const data = await r.json().catch(() => null);
      await new Promise((ok) => setTimeout(ok, Math.max(0, CARGANDO_MIN_MS - (Date.now() - inicio))));
      // Respuesta cortada (señal débil): la solicitud puede haberse guardado igual.
      // Volver a enviar es seguro: con el mismo DNI y fecha se ve la misma constancia.
      if (!data || (r.ok && !data.constancia)) throw new Error('sin-respuesta');
      if (!r.ok) {
        volverAlFormulario();
        if (data.errores) mostrarErrores(data.errores);
        mostrarErrorGeneral(data.error || 'No se pudo enviar la solicitud.');
        return;
      }
      mostrarConstancia(data.constancia, data.duplicado === true);
    } catch (err) {
      volverAlFormulario();
      const cortado = err.message === 'sin-respuesta' || err instanceof TypeError; // TypeError = fetch sin respuesta
      mostrarErrorGeneral(!navigator.onLine
        ? 'No hay conexión a internet. Revisá los datos móviles o el Wi-Fi y tocá Enviar solicitud otra vez.'
        : cortado
          ? 'No llegó la confirmación del servidor. Tocá Enviar solicitud otra vez: si ya se había guardado, vas a ver la misma constancia (no ocupa otra vacante).'
          : 'No se pudo enviar la solicitud. Probá de nuevo en unos segundos.');
    } finally {
      clearTimeout(lentoTimer);
      submitBtn.disabled = false;
    }
  });

  /* ---------- Pantallas ---------- */
  function mostrarCargando() {
    $('#cargando-sub').textContent = 'Guardando la solicitud. No cierres esta pantalla.';
    viewForm.hidden = true;
    viewConst.hidden = true;
    viewCargando.hidden = false;
    window.scrollTo(0, 0);
  }

  // Si no se pudo guardar, se vuelve al formulario con todos los datos cargados
  function volverAlFormulario() {
    viewCargando.hidden = true;
    viewConst.hidden = true;
    viewForm.hidden = false;
    themeColor.content = '#0f2d52';
  }

  // Textos y colores de cada estado
  const ESTADOS = {
    ingresante: {
      titulo: 'Inscripción Recibida',
      explica: 'La solicitud quedó dentro de las vacantes de 1° año. Guardá el número de solicitud.',
      color: '#14773f',
      icono: 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z',
    },
    espera: {
      titulo: 'Lista de Espera',
      explica: 'Las vacantes ya están ocupadas. La solicitud quedó en lista de espera: si se libera una vacante, se asigna por orden de llegada.',
      color: '#9a4a00',
      icono: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 5v5.4l4.2 2.5-.8 1.3L11 13V7z',
    },
    fuera_zona: {
      titulo: 'Fuera de zona',
      explica: 'El código postal no corresponde al partido de Berazategui. La solicitud quedó registrada aparte y no ocupa una vacante. Si el código postal está mal, comunicate con la escuela.',
      color: '#475467',
      icono: 'M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z',
    },
  };

  function icono(path) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('class', 'ico');
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', path);
    svg.appendChild(p);
    return svg;
  }

  function mostrarConstancia(c, duplicado) {
    const e = ESTADOS[c.estado] || ESTADOS.ingresante;
    viewConst.classList.remove('estado-ingresante', 'estado-espera', 'estado-fuera_zona');
    viewConst.classList.add(`estado-${c.estado}`);
    $('#c-icono').replaceChildren(icono(e.icono));
    $('#c-titulo').textContent = e.titulo;
    $('#c-explica').textContent = e.explica;
    $('#c-dup').hidden = !duplicado;
    $('#c-numero').textContent = `N° ${c.numero}`;
    $('#c-alumno').textContent = c.alumno;
    $('#c-dni').textContent = c.dni;
    $('#c-fecha').textContent = c.fechaMs
      ? new Date(c.fechaMs).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false })
      : '—';
    const tag = $('#c-estado');
    tag.textContent = c.estadoTexto || e.titulo;
    tag.className = `estado tag-${c.estado}`;

    viewCargando.hidden = true;
    viewForm.hidden = true;
    viewConst.hidden = false;
    themeColor.content = e.color;
    window.scrollTo(0, 0);
    $('#c-titulo').focus({ preventScroll: true }); // el lector de pantalla anuncia el resultado
  }

  $('#imprimir').addEventListener('click', () => window.print());

  // Otro alumno (por ejemplo, un hermano): se borran los datos del alumno y quedan cargados
  // el domicilio, el email y el adulto responsable.
  $('#otro').addEventListener('click', () => {
    for (const n of ['alumnoApellido', 'alumnoNombre', 'dni', 'escuela', 'nacDia', 'nacMes', 'nacAnio']) form[n].value = '';
    formError.hidden = true;
    abierto = Date.now();
    volverAlFormulario();
    window.scrollTo(0, 0);
    form.alumnoApellido.focus();
  });
})();
