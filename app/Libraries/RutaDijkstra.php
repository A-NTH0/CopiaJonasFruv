<?php

namespace App\Libraries;

use SplPriorityQueue;

/**
 * Planificación de ruta del repartidor con Dijkstra.
 *
 * 1. Se decide si conviene trazar ruta:
 *      - ZONA:    3 o más pedidos a <= RADIO_ZONA_KM entre sí (encadenados), o
 *      - DE PASO: un pedido queda "en el camino" hacia otro (desvío pequeño).
 * 2. Con las paradas elegidas se arma un grafo (nodo 0 = repartidor/tienda).
 *    Aristas: árbol de expansión mínima (conexo) + los K vecinos más cercanos.
 *    Peso = distancia Haversine * FACTOR_VIAL (aprox. de calles).
 * 3. Dijkstra desde cada nodo da la distancia más corta entre todos los pares.
 * 4. Con esa matriz se ordena la visita: exacto (permutaciones) si hay
 *    <= MAX_EXACTO paradas; vecino más cercano si hay más.
 *
 * Para usar distancias reales por calle basta cambiar el cálculo de $peso
 * en construirGrafo() (p. ej. con la Distance Matrix API de Google).
 */
class RutaDijkstra
{
    public const RADIO_ZONA_KM    = 2.0;
    public const MIN_PEDIDOS_ZONA = 3;
    public const DESVIO_MAX_KM    = 1.0;
    public const DESVIO_MAX_PCT   = 0.15;
    public const FACTOR_VIAL      = 1.3;
    public const VECINOS_K        = 3;
    public const MAX_EXACTO       = 8;
    public const MAX_PARADAS_GOOGLE = 10; // destino + 9 waypoints
    public const MAX_PARADAS_RUTA   = 25; // tope de paradas por cálculo (límite de OSRM público)
    public const PESO_TARDE         = 3.0; // cada minuto de retraso sobre la hora prometida cuesta 3 min de ruta
    public const VELOCIDAD_KMH      = 28.0; // respaldo en línea recta si no hay servicio de calles

    /** Distancia en km entre dos puntos ['lat'=>, 'lng'=>]. */
    public static function haversine(array $a, array $b): float
    {
        $r    = 6371.0088;
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h    = sin($dLat / 2) ** 2
              + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($h)));
    }

    /**
     * @param array $origen  ['lat'=>float,'lng'=>float]
     * @param array $pedidos cada uno con id_pedido, lat, lng (+ campos extra que se conservan)
     */
    public static function planificar(array $origen, array $pedidos): array
    {
        $conCoord = [];
        $sinCoord = [];
        foreach ($pedidos as $p) {
            if (isset($p['lat'], $p['lng']) && $p['lat'] !== null && $p['lng'] !== null) {
                $conCoord[] = $p;
            } else {
                $sinCoord[] = (int) $p['id_pedido'];
            }
        }

        $vacio = [
            'hay_ruta'        => false,
            'motivo'          => '',
            'origen'          => $origen,
            'paradas'         => [],
            'distancia_km'    => 0.0,
            'metodo'          => null,
            'sin_coordenadas' => $sinCoord,
            'google_url'      => null,
            'truncada'        => false,
        ];

        if (count($conCoord) < 2) {
            return $vacio;
        }

        // ── 1. ¿Conviene trazar ruta? ─────────────────────────────
        $motivos = []; // indice => 'zona' | 'de_paso'

        foreach (self::zonas($conCoord) as $grupo) {
            if (count($grupo) >= self::MIN_PEDIDOS_ZONA) {
                foreach ($grupo as $i) {
                    $motivos[$i] = 'zona';
                }
            }
        }

        $n = count($conCoord);
        for ($t = 0; $t < $n; $t++) {
            $dOT = self::haversine($origen, $conCoord[$t]);
            for ($p = 0; $p < $n; $p++) {
                if ($p === $t) {
                    continue;
                }
                $dOP = self::haversine($origen, $conCoord[$p]);
                if ($dOP >= $dOT) {
                    continue; // el pedido "de paso" debe quedar antes que el destino
                }
                $desvio = $dOP + self::haversine($conCoord[$p], $conCoord[$t]) - $dOT;
                $limite = max(self::DESVIO_MAX_KM, self::DESVIO_MAX_PCT * $dOT);
                if ($desvio <= $limite) {
                    $motivos[$p] ??= 'de_paso';
                    $motivos[$t] ??= 'de_paso';
                }
            }
        }

        if (count($motivos) < 2) {
            $vacio['motivo'] = 'Tus pedidos están en zonas distintas y ninguno queda de paso; no hay ruta combinada.';

            return $vacio;
        }

        // ── 2-4. Grafo + Dijkstra + orden de visita ───────────────
        $indices = array_keys($motivos);
        sort($indices);

        $nodos = [['lat' => $origen['lat'], 'lng' => $origen['lng']]];
        foreach ($indices as $i) {
            $nodos[] = ['lat' => $conCoord[$i]['lat'], 'lng' => $conCoord[$i]['lng']];
        }

        $grafo = self::construirGrafo($nodos);
        $D     = [];
        for ($i = 0, $m = count($nodos); $i < $m; $i++) {
            $D[$i] = self::dijkstra($grafo, $i)['dist'];
        }

        [$orden, $total, $metodo] = self::ordenarVisita($D, count($nodos) - 1);

        $paradas = [];
        $previo  = 0;
        $acum    = 0.0;
        foreach ($orden as $pos => $nodo) {
            $pedido = $conCoord[$indices[$nodo - 1]];
            $tramo  = $D[$previo][$nodo];
            $acum  += $tramo;
            $paradas[] = $pedido + [
                'orden'    => $pos + 1,
                'motivo'   => $motivos[$indices[$nodo - 1]],
                'km_tramo' => round($tramo, 2),
                'km_acum'  => round($acum, 2),
            ];
            $previo = $nodo;
        }

        $hayZona = in_array('zona', $motivos, true);

        return [
            'hay_ruta'        => true,
            'motivo'          => $hayZona
                ? 'Tienes ' . count(array_filter($motivos, static fn ($m) => $m === 'zona')) . ' pedidos en la misma zona.'
                : 'Uno de tus pedidos te queda de paso hacia otro.',
            'origen'          => $origen,
            'paradas'         => $paradas,
            'distancia_km'    => round($total, 2),
            'metodo'          => $metodo,
            'sin_coordenadas' => $sinCoord,
            'google_url'      => self::urlGoogleMaps($origen, $paradas),
            'truncada'        => count($paradas) > self::MAX_PARADAS_GOOGLE,
        ];
    }

    /** Zona horaria del negocio (la app corre en UTC; los horarios de entrega son locales). */
    public static function zona(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) env('fruver.timezone', 'America/Mexico_City'));
        } catch (\Throwable $e) {
            return new \DateTimeZone('America/Mexico_City');
        }
    }

    /**
     * Ruta MÁS RÁPIDA para TODOS los pedidos con coordenadas.
     *
     * Factores que considera:
     *  - Tiempo de viaje por calle entre paradas (OSRM, o Google con tráfico en vivo si hay API key).
     *  - Hora pico (factor sobre los tiempos de OSRM; ver Matrices).
     *  - Tiempo de servicio por parada (fruver.minutosPorParada, 4 por defecto).
     *  - Horarios de entrega prometidos: cada minuto de retraso suma PESO_TARDE minutos de costo,
     *    así que una entrega con hora límite cercana sube de prioridad aunque implique más km.
     *
     * @param array         $origen    ['lat'=>float,'lng'=>float] (GPS del repartidor o la tienda)
     * @param array         $pedidos   cada uno con id_pedido, lat, lng y opcional limite_ts (unix) + extras
     * @param callable|null $matrices  fn(array $puntos): ?array{km,min,fuente?,factor?}; si es null o falla
     *                                 se usa línea recta * FACTOR_VIAL a VELOCIDAD_KMH.
     */
    public static function planificarTodos(array $origen, array $pedidos, ?callable $matrices = null, ?int $ahora = null): array
    {
        $ahora ??= time();
        $tz      = self::zona();
        $hhmm    = static fn (int $ts): string => (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i');

        $conCoord = [];
        $sinCoord = [];
        foreach ($pedidos as $p) {
            if (($p['lat'] ?? null) !== null && ($p['lng'] ?? null) !== null) {
                $conCoord[] = $p;
            } else {
                $sinCoord[] = (int) $p['id_pedido'];
            }
        }

        $plan = [
            'hay_ruta'        => false,
            'motivo'          => '',
            'origen'          => $origen,
            'paradas'         => [],
            'distancia_km'    => 0.0,
            'duracion_min'    => null,
            'llegada_final'   => null,
            'metodo'          => null,
            'base'            => null,
            'fuente_tiempos'  => null,
            'factor_trafico'  => 1.0,
            'sin_coordenadas' => $sinCoord,
            'omitidas'        => [],
            'google_url'      => null,
            'truncada'        => false,
        ];

        if (! $conCoord) {
            $plan['motivo'] = $sinCoord
                ? 'Ningún pedido activo tiene ubicación en el mapa todavía.'
                : 'No tienes pedidos confirmados o en camino.';

            return $plan;
        }

        // Tope de paradas: se conservan las más cercanas al origen.
        if (count($conCoord) > self::MAX_PARADAS_RUTA) {
            usort($conCoord, static fn (array $a, array $b): int => self::haversine($origen, $a) <=> self::haversine($origen, $b));
            $plan['omitidas'] = array_map(
                static fn (array $p): int => (int) $p['id_pedido'],
                array_slice($conCoord, self::MAX_PARADAS_RUTA)
            );
            $conCoord = array_slice($conCoord, 0, self::MAX_PARADAS_RUTA);
        }

        $nodos = [['lat' => $origen['lat'], 'lng' => $origen['lng']]];
        foreach ($conCoord as $p) {
            $nodos[] = ['lat' => $p['lat'], 'lng' => $p['lng']];
        }
        $n = count($nodos);

        $m = $matrices !== null ? $matrices($nodos) : null;
        if (is_array($m) && isset($m['km'], $m['min']) && count($m['km']) === $n && count($m['min']) === $n) {
            $KM                     = $m['km'];
            $MIN                    = $m['min'];
            $plan['base']           = 'calles';
            $plan['fuente_tiempos'] = (string) ($m['fuente'] ?? 'osrm');
            $plan['factor_trafico'] = (float) ($m['factor'] ?? 1.0);
        } else {
            $KM = [];
            $MIN = [];
            foreach ($nodos as $i => $a) {
                foreach ($nodos as $j => $b) {
                    $KM[$i][$j]  = $i === $j ? 0.0 : self::haversine($a, $b) * self::FACTOR_VIAL;
                    $MIN[$i][$j] = $KM[$i][$j] / self::VELOCIDAD_KMH * 60;
                }
            }
            $plan['base']           = 'aerea';
            $plan['fuente_tiempos'] = 'aerea';
        }

        // Grafo dirigido completo con los TIEMPOS; Dijkstra desde cada nodo.
        $grafo = array_fill(0, $n, []);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($i !== $j) {
                    $grafo[$i][$j] = (float) $MIN[$i][$j];
                }
            }
        }
        $D = [];
        for ($i = 0; $i < $n; $i++) {
            $D[$i] = self::dijkstra($grafo, $i)['dist'];
        }

        // Horas límite en minutos desde ahora (solo pedidos con horario prometido).
        $limites = [];
        foreach ($conCoord as $k => $p) {
            if (! empty($p['limite_ts'])) {
                $limites[$k + 1] = ((int) $p['limite_ts'] - $ahora) / 60;
            }
        }
        $servicio = max(0.0, (float) env('fruver.minutosPorParada', 4));

        [$orden, , $metodo] = self::ordenarPorCosto($D, $servicio, $limites, $n - 1);

        $paradas = [];
        $previo  = 0;
        $t       = 0.0;
        $km      = 0.0;
        $viaje   = 0.0;
        foreach ($orden as $pos => $nodo) {
            $tramoMin = $D[$previo][$nodo];
            $tramoKm  = $KM[$previo][$nodo];
            $t       += $tramoMin;
            $viaje   += $tramoMin;
            $km      += $tramoKm;

            $tarde = isset($limites[$nodo]) ? max(0.0, $t - $limites[$nodo]) : 0.0;
            $paradas[] = $conCoord[$nodo - 1] + [
                'orden'     => $pos + 1,
                'km_tramo'  => round($tramoKm, 2),
                'km_acum'   => round($km, 2),
                'min_tramo' => (int) round($tramoMin),
                'llegada'   => $hhmm($ahora + (int) round($t * 60)),
                'limite'    => ! empty($conCoord[$nodo - 1]['limite_ts']) ? $hhmm((int) $conCoord[$nodo - 1]['limite_ts']) : null,
                'tarde_min' => (int) ceil($tarde - 1e-9),
            ];
            $t     += $servicio;
            $previo = $nodo;
        }

        $conHorario = count($limites) > 0;
        $plan['hay_ruta']      = true;
        $plan['motivo']        = count($paradas) === 1
            ? 'Ruta más rápida hacia tu pedido activo.'
            : 'Orden de visita de menor tiempo' . ($conHorario ? ', priorizando los horarios de entrega prometidos.' : '.');
        $plan['paradas']       = $paradas;
        $plan['distancia_km']  = round($km, 2);
        $plan['duracion_min']  = (int) round($viaje);
        $plan['llegada_final'] = $paradas[count($paradas) - 1]['llegada'];
        $plan['metodo']        = $metodo;
        $plan['google_url']    = self::urlGoogleMaps($origen, $paradas);
        $plan['truncada']      = count($paradas) > self::MAX_PARADAS_GOOGLE;

        return $plan;
    }

    // ───────────────────────── Orden por tiempo + horarios ─────────────────────────

    /**
     * Costo de un orden de visita: tiempo total (viaje + servicio) + PESO_TARDE × minutos de retraso.
     *
     * @param int[]               $orden   nodos (1..n) en orden de visita; el 0 es el origen
     * @param float[][]           $D       tiempos mínimos entre nodos (min)
     * @param array<int, float>   $limites nodo => minutos desde ahora hasta su hora límite
     */
    private static function evaluarOrden(array $orden, array $D, float $servicio, array $limites): float
    {
        $t     = 0.0;
        $tarde = 0.0;
        $prev  = 0;
        foreach ($orden as $nodo) {
            $t += $D[$prev][$nodo];
            if (isset($limites[$nodo])) {
                $tarde += max(0.0, $t - $limites[$nodo]);
            }
            $t   += $servicio;
            $prev = $nodo;
        }

        return $t + self::PESO_TARDE * $tarde;
    }

    /** @return array{0: int[], 1: float, 2: string} [orden, costo, método] */
    private static function ordenarPorCosto(array $D, float $servicio, array $limites, int $paradas): array
    {
        // Punto de partida 1: vecino más cercano en tiempo.
        $pend = range(1, $paradas);
        $nn   = [];
        $act  = 0;
        while ($pend) {
            $kMejor = array_key_first($pend);
            foreach ($pend as $k => $j) {
                if ($D[$act][$j] < $D[$act][$pend[$kMejor]]) {
                    $kMejor = $k;
                }
            }
            $act  = $pend[$kMejor];
            $nn[] = $act;
            unset($pend[$kMejor]);
        }

        // Punto de partida 2: primero las entregas con hora límite más próxima.
        $edf = range(1, $paradas);
        usort($edf, static function (int $a, int $b) use ($limites): int {
            return ($limites[$a] ?? INF) <=> ($limites[$b] ?? INF);
        });

        $orden = self::evaluarOrden($edf, $D, $servicio, $limites) < self::evaluarOrden($nn, $D, $servicio, $limites)
            ? $edf
            : $nn;
        $costo = self::evaluarOrden($orden, $D, $servicio, $limites);

        // Pocas paradas: búsqueda exhaustiva con poda (el costo parcial solo crece).
        if ($paradas <= self::MAX_EXACTO) {
            $mejor      = $costo + 1e-9;
            $mejorOrden = $orden;
            $buscar = function (int $act, array $resto, float $t, float $tarde, array $camino) use (&$buscar, &$mejor, &$mejorOrden, $D, $servicio, $limites): void {
                $parcial = $t + self::PESO_TARDE * $tarde;
                if ($parcial >= $mejor) {
                    return;
                }
                if (! $resto) {
                    $mejor      = $parcial;
                    $mejorOrden = $camino;

                    return;
                }
                foreach ($resto as $k => $j) {
                    $otros = $resto;
                    unset($otros[$k]);
                    $t2     = $t + $D[$act][$j];
                    $tarde2 = $tarde + (isset($limites[$j]) ? max(0.0, $t2 - $limites[$j]) : 0.0);
                    $buscar($j, $otros, $t2 + $servicio, $tarde2, array_merge($camino, [$j]));
                }
            };
            $buscar(0, range(1, $paradas), 0.0, 0.0, []);

            return [$mejorOrden, min($mejor, $costo), 'exacto'];
        }

        // Muchas paradas: mejora local con inversión de tramos (2-opt) y reubicación de paradas.
        $m = count($orden);
        for ($pasada = 0; $pasada < 20; $pasada++) {
            $mejoro = false;

            for ($i = 0; $i < $m - 1; $i++) {
                for ($j = $i + 1; $j < $m; $j++) {
                    $cand = array_merge(
                        array_slice($orden, 0, $i),
                        array_reverse(array_slice($orden, $i, $j - $i + 1)),
                        array_slice($orden, $j + 1)
                    );
                    $c = self::evaluarOrden($cand, $D, $servicio, $limites);
                    if ($c + 1e-9 < $costo) {
                        $orden  = $cand;
                        $costo  = $c;
                        $mejoro = true;
                    }
                }
            }

            for ($i = 0; $i < $m; $i++) {
                for ($j = 0; $j < $m; $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    $cand  = $orden;
                    $nodo  = array_splice($cand, $i, 1);
                    array_splice($cand, $j, 0, $nodo);
                    $c = self::evaluarOrden($cand, $D, $servicio, $limites);
                    if ($c + 1e-9 < $costo) {
                        $orden  = $cand;
                        $costo  = $c;
                        $mejoro = true;
                    }
                }
            }

            if (! $mejoro) {
                break;
            }
        }

        return [$orden, $costo, 'mejorado'];
    }

    // ───────────────────────── Zonas ─────────────────────────

    /** Agrupa pedidos por cercanía encadenada (<= RADIO_ZONA_KM). @return int[][] */
    private static function zonas(array $pedidos): array
    {
        $n       = count($pedidos);
        $visto   = array_fill(0, $n, false);
        $grupos  = [];

        for ($i = 0; $i < $n; $i++) {
            if ($visto[$i]) {
                continue;
            }
            $grupo = [];
            $cola  = [$i];
            $visto[$i] = true;
            while ($cola) {
                $a       = array_shift($cola);
                $grupo[] = $a;
                for ($j = 0; $j < $n; $j++) {
                    if (! $visto[$j] && self::haversine($pedidos[$a], $pedidos[$j]) <= self::RADIO_ZONA_KM) {
                        $visto[$j] = true;
                        $cola[]    = $j;
                    }
                }
            }
            $grupos[] = $grupo;
        }

        return $grupos;
    }

    // ───────────────────────── Grafo y Dijkstra ─────────────────────────

    /** @return array<int, array<int, float>> lista de adyacencia [i][j] = peso */
    private static function construirGrafo(array $nodos): array
    {
        $n     = count($nodos);
        $peso  = static fn (int $i, int $j): float => self::haversine($nodos[$i], $nodos[$j]) * self::FACTOR_VIAL;
        $grafo = array_fill(0, $n, []);

        $conectar = static function (int $i, int $j) use (&$grafo, $peso): void {
            $w = $peso($i, $j);
            $grafo[$i][$j] = $w;
            $grafo[$j][$i] = $w;
        };

        // Árbol de expansión mínima (Prim): garantiza que el grafo sea conexo.
        $enArbol = array_fill(0, $n, false);
        $mejor   = array_fill(0, $n, INF);
        $desde   = array_fill(0, $n, 0);
        $mejor[0] = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $u = -1;
            for ($v = 0; $v < $n; $v++) {
                if (! $enArbol[$v] && ($u === -1 || $mejor[$v] < $mejor[$u])) {
                    $u = $v;
                }
            }
            $enArbol[$u] = true;
            if ($k > 0) {
                $conectar($desde[$u], $u);
            }
            for ($v = 0; $v < $n; $v++) {
                if (! $enArbol[$v] && $peso($u, $v) < $mejor[$v]) {
                    $mejor[$v] = $peso($u, $v);
                    $desde[$v] = $u;
                }
            }
        }

        // K vecinos más cercanos de cada nodo.
        for ($i = 0; $i < $n; $i++) {
            $cercanos = [];
            for ($j = 0; $j < $n; $j++) {
                if ($j !== $i) {
                    $cercanos[$j] = $peso($i, $j);
                }
            }
            asort($cercanos);
            foreach (array_slice(array_keys($cercanos), 0, self::VECINOS_K) as $j) {
                $conectar($i, $j);
            }
        }

        return $grafo;
    }

    /** Dijkstra clásico con cola de prioridad. @return array{dist: float[], prev: int[]} */
    public static function dijkstra(array $grafo, int $origen): array
    {
        $n    = count($grafo);
        $dist = array_fill(0, $n, INF);
        $prev = array_fill(0, $n, -1);
        $dist[$origen] = 0.0;

        $cola = new SplPriorityQueue();
        $cola->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $cola->insert($origen, 0.0);

        while (! $cola->isEmpty()) {
            $item = $cola->extract();
            $u    = $item['data'];
            $d    = -$item['priority'];
            if ($d > $dist[$u]) {
                continue; // entrada vieja
            }
            foreach ($grafo[$u] as $v => $w) {
                if ($dist[$u] + $w < $dist[$v]) {
                    $dist[$v] = $dist[$u] + $w;
                    $prev[$v] = $u;
                    $cola->insert($v, -$dist[$v]);
                }
            }
        }

        return ['dist' => $dist, 'prev' => $prev];
    }

    // ───────────────────────── Orden de visita ─────────────────────────

    /**
     * @param float[][] $D matriz de distancias mínimas (nodo 0 = origen)
     * @return array{0: int[], 1: float, 2: string} [orden de nodos, km totales, método]
     */
    private static function ordenarVisita(array $D, int $paradas): array
    {
        // Vecino más cercano: en cada paso se va a la parada pendiente más próxima.
        $pendientes = range(1, $paradas);
        $orden      = [];
        $actual     = 0;
        $total      = 0.0;
        while ($pendientes) {
            $mejorK = array_key_first($pendientes);
            foreach ($pendientes as $k => $j) {
                if ($D[$actual][$j] < $D[$actual][$pendientes[$mejorK]]) {
                    $mejorK = $k;
                }
            }
            $j        = $pendientes[$mejorK];
            $total   += $D[$actual][$j];
            $orden[]  = $j;
            $actual   = $j;
            unset($pendientes[$mejorK]);
        }

        if ($paradas > self::MAX_EXACTO) {
            return [$orden, $total, 'vecino_mas_cercano'];
        }

        // Pocas paradas: se prueba todo con poda (la ruta más corta real).
        $mejor      = $total + 1e-9;
        $mejorOrden = $orden;
        $buscar = function (int $actual, array $restantes, float $acum, array $camino) use (&$buscar, &$mejor, &$mejorOrden, $D): void {
            if ($acum >= $mejor) {
                return;
            }
            if (! $restantes) {
                $mejor      = $acum;
                $mejorOrden = $camino;

                return;
            }
            foreach ($restantes as $k => $j) {
                $resto = $restantes;
                unset($resto[$k]);
                $buscar($j, $resto, $acum + $D[$actual][$j], array_merge($camino, [$j]));
            }
        };
        $buscar(0, range(1, $paradas), 0.0, []);

        return [$mejorOrden, min($mejor, $total), 'exacto'];
    }

    // ───────────────────────── Google Maps ─────────────────────────

    /** Enlace que abre la ruta ya ordenada en Google Maps (no requiere API key). */
    private static function urlGoogleMaps(array $origen, array $paradas): string
    {
        $paradas = array_slice($paradas, 0, self::MAX_PARADAS_GOOGLE);
        $punto   = static fn (array $p): string => $p['lat'] . ',' . $p['lng'];
        $destino = array_pop($paradas);

        $query = [
            'api'         => 1,
            'origin'      => $origen['lat'] . ',' . $origen['lng'],
            'destination' => $punto($destino),
            'travelmode'  => 'driving',
        ];
        if ($paradas) {
            $query['waypoints'] = implode('|', array_map($punto, $paradas));
        }

        return 'https://www.google.com/maps/dir/?' . http_build_query($query);
    }
}
