/**
 * Panel "Ruta sugerida" del repartidor (Dijkstra en el servidor).
 * Pide /repartidores/ruta (con el GPS si el repartidor lo permite) y dibuja
 * las paradas en orden + botón para abrir la ruta en Google Maps.
 */
(function () {
    let cargando = false;
    let posicion = null;      // { lat, lng, t }
    const GPS_VIGENCIA_MS = 60000;

    function esc(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function obtenerPosicion() {
        return new Promise(resolve => {
            if (posicion && Date.now() - posicion.t < GPS_VIGENCIA_MS) return resolve(posicion);
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(
                p => {
                    posicion = { lat: p.coords.latitude, lng: p.coords.longitude, t: Date.now() };
                    resolve(posicion);
                },
                () => resolve(null),                       // sin permiso / sin HTTPS: se usa la tienda
                { timeout: 4000, maximumAge: GPS_VIGENCIA_MS }
            );
        });
    }

    function pintar(data) {
        const panel = document.getElementById('panel-ruta');
        if (!panel) return;

        const r = data.ruta;
        if (!r || !r.hay_ruta) {
            const aviso = (r && r.sin_coordenadas && r.sin_coordenadas.length)
                ? `Sin ubicación para los pedidos: ${r.sin_coordenadas.map(i => '#' + i).join(', ')}.`
                : '';
            panel.innerHTML = aviso
                ? `<p class="text-xs text-gray-500">${esc(aviso)}</p>`
                : '';
            return;
        }

        const salida = data.origen_gps ? 'tu ubicación' : 'la tienda';
        const paradas = r.paradas.map(p => `
            <li class="flex items-start gap-3 py-2">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-rose-600 text-xs font-extrabold text-white">${p.orden}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-gray-900">Pedido #${p.id_pedido} · ${esc(p.cliente_nombre)}
                        <span class="ml-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${p.motivo === 'zona' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700'}">
                            ${p.motivo === 'zona' ? 'Misma zona' : 'De paso'}
                        </span>
                    </p>
                    <p class="truncate text-xs text-gray-600">${esc(p.direccion)}</p>
                </div>
                <span class="whitespace-nowrap text-xs font-semibold text-gray-500">+${p.km_tramo} km</span>
            </li>`).join('');

        const nota = r.truncada
            ? '<p class="mt-2 text-[11px] text-amber-700">Google Maps admite 10 paradas: el enlace incluye las primeras 10.</p>'
            : '';
        const sinCoord = (r.sin_coordenadas && r.sin_coordenadas.length)
            ? `<p class="mt-2 text-[11px] text-gray-500">Fuera de la ruta por no tener ubicación: ${r.sin_coordenadas.map(i => '#' + i).join(', ')}.</p>`
            : '';

        panel.innerHTML = `
            <div class="rounded-2xl border border-rose-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-extrabold text-gray-900">Ruta sugerida</h2>
                        <p class="text-xs text-gray-600">${esc(r.motivo)} Sales desde ${salida} · ≈ ${r.distancia_km} km
                            (${r.metodo === 'exacto' ? 'orden óptimo' : 'orden aproximado'}).</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" id="btn-recalcular-ruta"
                                class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-bold text-gray-700 hover:border-rose-300 hover:text-rose-600">
                            Recalcular desde mi ubicación
                        </button>
                        <a href="${esc(r.google_url)}" target="_blank" rel="noopener noreferrer"
                           class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-rose-700">
                            Ver ruta en Google Maps
                        </a>
                    </div>
                </div>
                <ol class="mt-3 divide-y divide-gray-100">${paradas}</ol>
                ${nota}${sinCoord}
            </div>`;

        document.getElementById('btn-recalcular-ruta')?.addEventListener('click', () => {
            posicion = null;
            cargarRutaRepartidor();
        });
    }

    async function cargarRutaRepartidor() {
        if (cargando) return;
        cargando = true;
        try {
            const pos = await obtenerPosicion();
            const qs = pos ? `?lat=${pos.lat}&lng=${pos.lng}` : '';
            const res = await fetch(BASE_URL + 'repartidores/ruta' + qs, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data.ok) pintar(data);
        } catch (e) {
            console.error('No se pudo calcular la ruta:', e);
        } finally {
            cargando = false;
        }
    }

    window.cargarRutaRepartidor = cargarRutaRepartidor;
    document.addEventListener('DOMContentLoaded', cargarRutaRepartidor);
})();
