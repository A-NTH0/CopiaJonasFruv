/**
 * Mapa de ruta más corta del repartidor (Leaflet + OpenStreetMap).
 *
 * 1. Toma la ubicación actual del navegador (Geolocation API).
 * 2. Pide /repartidores/ruta?lat=&lng= : el servidor cruza ese punto con la latitud/longitud
 *    guardadas en `direccion` de sus pedidos confirmados / en camino, ordena las paradas con
 *    Dijkstra sobre distancias por calle (OSRM) y devuelve el trazo.
 * 3. Dibuja el trazo, las paradas numeradas y la ubicación en vivo del repartidor.
 */
(function () {
    'use strict';

    const GPS_VIGENCIA_MS = 60000;
    const RECALC_DIST_M   = 500;    // recalcular sola si te moviste más de esto…
    const RECALC_MIN_MS   = 60000;  // …y han pasado al menos 60 s desde el último cálculo

    let mapa = null, capaRuta = null, capaYo = null;
    let marcadorYo = null;
    const marcadoresPedido = {};
    let posicion = null;            // { lat, lng, t }
    let ultimoCalculo = null;       // { lat, lng, t } (solo si salió del GPS)
    let cargando = false;
    let siguiendo = false;
    let ultimoBounds = null;
    let errorGps = '';

    const $ = id => document.getElementById(id);

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function metros(a, b) {
        const R = 6371000, rad = x => x * Math.PI / 180;
        const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
        const h = Math.sin(dLat / 2) ** 2 +
                  Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    function esAproximada(precision) {
        return precision === 'colonia' || precision === 'municipio';
    }

    // ───────────── Mapa ─────────────
    function iniciarMapa() {
        if (mapa) return true;
        const el = $('mapa-ruta');
        if (!el || !window.L) return false;

        const centro = (window.TIENDA_COORD && [TIENDA_COORD.lat, TIENDA_COORD.lng]) || [19.3139, -97.9206];
        mapa = L.map(el).setView(centro, 12);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
        }).addTo(mapa);
        capaRuta = L.layerGroup().addTo(mapa);
        capaYo   = L.layerGroup().addTo(mapa);
        return true;
    }

    function pin(clase, html) {
        return L.divIcon({ className: clase, html: `<span>${html}</span>`, iconSize: [32, 32], iconAnchor: [16, 16], popupAnchor: [0, -14] });
    }

    function actualizarMarcadorYo() {
        if (!mapa || !posicion) return;
        const ll = [posicion.lat, posicion.lng];
        if (marcadorYo) {
            marcadorYo.setLatLng(ll);
        } else {
            marcadorYo = L.marker(ll, { icon: pin('ruta-pin ruta-pin--yo', '●'), zIndexOffset: 1000 })
                .bindPopup('Tu ubicación actual').addTo(capaYo);
        }
    }

    function dibujar(data) {
        const r = data.ruta;
        const contenedor = $('mapa-ruta');
        contenedor.classList.toggle('hidden', !r.hay_ruta || !window.L);
        if (!r.hay_ruta || !iniciarMapa()) return;

        capaRuta.clearLayers();
        Object.keys(marcadoresPedido).forEach(k => delete marcadoresPedido[k]);
        mapa.invalidateSize();

        const linea = L.polyline(r.trazo, {
            color: '#e11d48', weight: 5, opacity: 0.85,
            dashArray: r.trazo_calles ? null : '8 8'   // punteada = línea recta (sin respuesta de OSRM)
        }).addTo(capaRuta);

        if (!data.origen_gps) {
            L.marker([r.origen.lat, r.origen.lng], { icon: pin('ruta-pin ruta-pin--tienda', '🏪') })
                .bindPopup('Punto de salida: tienda').addTo(capaRuta);
        }

        r.paradas.forEach(p => {
            const aprox = esAproximada(p.precision);
            const tarde = p.tarde_min > 0;
            const clase = 'ruta-pin' + (tarde ? ' ruta-pin--tarde' : (aprox ? ' ruta-pin--aprox' : ''));
            const m = L.marker([p.lat, p.lng], { icon: pin(clase, p.orden) })
                .bindPopup(
                    `<strong>${p.orden}. Pedido #${p.id_pedido}</strong><br>${esc(p.cliente_nombre)}<br>` +
                    `<span style="font-size:12px">${esc(p.direccion)}</span><br>` +
                    `<span style="font-size:12px">+${p.km_tramo} km · ≈ ${p.min_tramo} min · llegada ≈ ${esc(p.llegada)}</span>` +
                    (p.limite ? `<br><span style="font-size:12px">Entrega prometida: ${esc(p.limite)}</span>` : '') +
                    (tarde ? `<br><span style="font-size:11px;color:#b91c1c">Llegarías ≈ ${p.tarde_min} min tarde</span>` : '') +
                    (aprox ? `<br><span style="font-size:11px;color:#b45309">Ubicación aproximada (${esc(p.precision)})</span>` : '')
                ).addTo(capaRuta);
            marcadoresPedido[p.id_pedido] = m;
        });

        actualizarMarcadorYo();
        ultimoBounds = linea.getBounds().extend([r.origen.lat, r.origen.lng]);
        mapa.fitBounds(ultimoBounds, { padding: [30, 30] });
    }

    // ───────────── Panel (resumen + lista) ─────────────
    function pintar(data) {
        const r = data.ruta;
        const resumen = $('ruta-resumen'), lista = $('ruta-paradas'), notas = $('ruta-notas');
        const aviso = $('ruta-aviso'), google = $('btn-google-maps');

        const avisos = [];
        if (!data.origen_gps) {
            avisos.push(errorGps
                ? `${errorGps} La ruta sale de la tienda.`
                : 'Sin ubicación del dispositivo: la ruta sale de la tienda.');
        }
        if (r.hay_ruta && !r.trazo_calles) {
            avisos.push('No se pudo consultar el servicio de calles; se muestran líneas rectas (distancia aproximada).');
        }
        aviso.innerHTML = esc(avisos.join(' '));
        aviso.classList.toggle('hidden', avisos.length === 0);

        if (!r.hay_ruta) {
            resumen.textContent = r.motivo || 'Sin ruta por calcular.';
            lista.innerHTML = '';
            google.classList.add('hidden');
        } else {
            const salida = data.origen_gps ? 'tu ubicación' : 'la tienda';
            resumen.textContent = `${r.motivo} Sales desde ${salida} · ${r.distancia_km} km · ≈ ${r.duracion_min} min de manejo` +
                ` · última entrega ≈ ${r.llegada_final}` +
                (r.paradas.length > 1 ? (r.metodo === 'exacto' ? ' (orden óptimo)' : ' (orden aproximado)') : '') + '.';

            lista.innerHTML = r.paradas.map(p => `
                <li class="flex items-start gap-3 py-2">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-rose-600 text-xs font-extrabold text-white">${p.orden}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900">Pedido #${p.id_pedido} · ${esc(p.cliente_nombre)}
                            ${esAproximada(p.precision) ? '<span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">Ubicación aproximada</span>' : ''}
                            ${p.limite ? `<span class="ml-1 rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700">Prometido ${esc(p.limite)}</span>` : ''}
                            ${p.tarde_min > 0 ? `<span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-700">≈ ${p.tarde_min} min tarde</span>` : ''}
                        </p>
                        <p class="truncate text-xs text-gray-600">${esc(p.direccion)}</p>
                        <p class="text-[11px] text-gray-500">Llegada estimada ${esc(p.llegada)}</p>
                    </div>
                    <button type="button" onclick="rutaEnfocarPedido(${p.id_pedido})"
                            class="whitespace-nowrap text-xs font-semibold text-rose-600 hover:underline">
                        +${p.km_tramo} km${p.min_tramo != null ? ' · ' + p.min_tramo + ' min' : ''}
                    </button>
                </li>`).join('');

            google.href = r.google_url;
            google.classList.remove('hidden');
        }

        const n = [];
        if (r.hay_ruta) {
            const f = r.fuente_tiempos;
            n.push(f === 'google' ? 'Tiempos con tráfico en vivo (Google).'
                : f === 'osrm' && r.factor_trafico > 1 ? `Tiempos de calle con tráfico estimado de hora pico (×${r.factor_trafico}); no es tráfico en vivo.`
                : f === 'osrm' ? 'Tiempos de calle sin tráfico en vivo.'
                : 'Tiempos estimados en línea recta (sin servicio de calles).');
        }
        if (r.truncada) n.push('Google Maps admite 10 paradas: el enlace incluye las primeras 10.');
        if (r.omitidas && r.omitidas.length) n.push(`Por el límite de paradas quedaron fuera: ${r.omitidas.map(i => '#' + i).join(', ')}.`);
        if (r.sin_coordenadas && r.sin_coordenadas.length) n.push(`Sin ubicación en el mapa (no se pudo geocodificar la dirección): ${r.sin_coordenadas.map(i => '#' + i).join(', ')}.`);
        notas.textContent = n.join(' ');

        dibujar(data);
    }

    // ───────────── GPS ─────────────
    function mensajeGps(err) {
        if (!window.isSecureContext) return 'El navegador solo da la ubicación en HTTPS o localhost.';
        if (err && err.code === 1) return 'Permiso de ubicación denegado.';
        return 'No se pudo obtener tu ubicación.';
    }

    function obtenerPosicion(forzar) {
        return new Promise(resolve => {
            if (!forzar && posicion && Date.now() - posicion.t < GPS_VIGENCIA_MS) return resolve(posicion);
            if (!navigator.geolocation) { errorGps = 'Este dispositivo no tiene geolocalización.'; return resolve(null); }
            navigator.geolocation.getCurrentPosition(
                p => {
                    errorGps = '';
                    posicion = { lat: p.coords.latitude, lng: p.coords.longitude, t: Date.now() };
                    resolve(posicion);
                },
                err => { errorGps = mensajeGps(err); resolve(null); },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: forzar ? 0 : GPS_VIGENCIA_MS }
            );
        });
    }

    // Mantiene la posición al día y mueve tu marcador; recalcula si te desplazaste bastante.
    function seguirPosicion() {
        if (siguiendo || !navigator.geolocation) return;
        siguiendo = true;
        navigator.geolocation.watchPosition(
            p => {
                posicion = { lat: p.coords.latitude, lng: p.coords.longitude, t: Date.now() };
                actualizarMarcadorYo();
                if (ultimoCalculo && !cargando &&
                    Date.now() - ultimoCalculo.t > RECALC_MIN_MS &&
                    metros(ultimoCalculo, posicion) > RECALC_DIST_M) {
                    cargarRutaRepartidor();
                }
            },
            () => { /* sin permiso: se queda con la tienda */ },
            { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 }
        );
    }

    async function cargarRutaRepartidor(forzarGps) {
        if (cargando) return;
        cargando = true;
        try {
            const pos = await obtenerPosicion(!!forzarGps);
            const qs = pos ? `?lat=${pos.lat}&lng=${pos.lng}` : '';
            const res = await fetch(BASE_URL + 'repartidores/ruta' + qs, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) return;
            ultimoCalculo = data.origen_gps ? { lat: pos.lat, lng: pos.lng, t: Date.now() } : null;
            pintar(data);
            if (data.origen_gps) seguirPosicion();
        } catch (e) {
            console.error('No se pudo calcular la ruta:', e);
        } finally {
            cargando = false;
        }
    }

    // ───────────── API para el resto de la página ─────────────
    window.cargarRutaRepartidor = cargarRutaRepartidor;

    window.rutaRefrescarMapa = function () {          // al volver a la pestaña "Mis entregas"
        if (!mapa) return;
        mapa.invalidateSize();
        if (ultimoBounds) mapa.fitBounds(ultimoBounds, { padding: [30, 30] });
    };

    window.rutaEnfocarPedido = function (id) {         // botón "Ver en mapa de ruta"
        const m = marcadoresPedido[id];
        if (!m || !mapa) {
            if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Este pedido aún no tiene ubicación en el mapa', showConfirmButton: false, timer: 2500 });
            return;
        }
        $('mapa-ruta').scrollIntoView({ behavior: 'smooth', block: 'center' });
        mapa.setView(m.getLatLng(), Math.max(mapa.getZoom(), 16));
        m.openPopup();
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (!window.L) {
            const a = $('ruta-aviso');
            if (a) { a.textContent = 'No se pudo cargar el mapa (revisa tu conexión a internet).'; a.classList.remove('hidden'); }
        }
        $('btn-recalcular-ruta')?.addEventListener('click', () => cargarRutaRepartidor(true));
        cargarRutaRepartidor();
    });
})();
