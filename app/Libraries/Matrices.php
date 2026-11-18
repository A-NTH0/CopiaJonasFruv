<?php

namespace App\Libraries;

/**
 * Matrices de distancia (km) y tiempo (min) entre puntos, para elegir la ruta más RÁPIDA.
 *
 * Orden de preferencia:
 *  1. Google Distance Matrix con tráfico en vivo (departure_time=now), si existe  google.mapsApiKey
 *     en .env y hay <= 10 puntos (límite de 100 elementos por petición).
 *  2. OSRM (OpenStreetMap), gratis, tiempos sin tráfico, multiplicados por un factor en hora pico
 *     (fruver.factorHoraPico, 1.3 por defecto; L-V 7-9, 13-15 y 18-20 h) como estimación.
 *
 * Devuelve ['km' => [[..]], 'min' => [[..]], 'fuente' => 'google'|'osrm', 'factor' => float] o null.
 * Nunca lanza excepciones.
 */
class Matrices
{
    private const HORAS_PICO = [[7, 9], [13, 15], [18, 20]];

    public static function calcular(array $puntos): ?array
    {
        $apiKey = trim((string) env('google.mapsApiKey', ''));
        if ($apiKey !== '' && count($puntos) <= 10) {
            $g = self::google($puntos, $apiKey);
            if ($g !== null) {
                return $g + ['fuente' => 'google', 'factor' => 1.0];
            }
        }

        $o = Osrm::matrices($puntos);
        if ($o === null) {
            return null;
        }

        $factor = self::factorHoraPico();
        if ($factor !== 1.0) {
            foreach ($o['min'] as $i => $fila) {
                foreach ($fila as $j => $min) {
                    $o['min'][$i][$j] = $min * $factor;
                }
            }
        }

        return $o + ['fuente' => 'osrm', 'factor' => $factor];
    }

    private static function factorHoraPico(): float
    {
        $factor = (float) env('fruver.factorHoraPico', 1.3);
        if ($factor < 1.0) {
            return 1.0;
        }

        $ahora = new \DateTimeImmutable('now', RutaDijkstra::zona());
        if ((int) $ahora->format('N') > 5) {
            return 1.0; // fin de semana
        }
        $hora = (int) $ahora->format('G');
        foreach (self::HORAS_PICO as [$desde, $hasta]) {
            if ($hora >= $desde && $hora < $hasta) {
                return $factor;
            }
        }

        return 1.0;
    }

    /** @return array{km: array, min: array}|null */
    private static function google(array $puntos, string $apiKey): ?array
    {
        $lista = implode('|', array_map(static fn (array $p): string => sprintf('%.6F,%.6F', $p['lat'], $p['lng']), $puntos));

        try {
            $resp = \Config\Services::curlrequest([
                'timeout'         => 8,
                'connect_timeout' => 4,
                'http_errors'     => false,
                'verify'          => Geocoder::verificarSsl(),
            ])->request('GET', 'https://maps.googleapis.com/maps/api/distancematrix/json', [
                'query' => [
                    'origins'        => $lista,
                    'destinations'   => $lista,
                    'mode'           => 'driving',
                    'departure_time' => 'now',   // activa duration_in_traffic
                    'language'       => 'es',
                    'key'            => $apiKey,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Google Distance Matrix: ' . $e->getMessage());

            return null;
        }

        $json = $resp->getStatusCode() === 200 ? json_decode((string) $resp->getBody(), true) : null;
        if (! is_array($json) || ($json['status'] ?? '') !== 'OK') {
            log_message('error', 'Google Distance Matrix: ' . ($json['status'] ?? 'HTTP ' . $resp->getStatusCode()) . ' ' . ($json['error_message'] ?? ''));

            return null;
        }

        $n  = count($puntos);
        $km = [];
        $mn = [];
        foreach ($json['rows'] ?? [] as $i => $fila) {
            foreach ($fila['elements'] ?? [] as $j => $el) {
                if ((int) $i === (int) $j) {
                    $km[$i][$j] = 0.0;
                    $mn[$i][$j] = 0.0;
                    continue;
                }
                if (($el['status'] ?? '') !== 'OK') {
                    return null;
                }
                $seg = $el['duration_in_traffic']['value'] ?? $el['duration']['value'] ?? null;
                if ($seg === null || ! isset($el['distance']['value'])) {
                    return null;
                }
                $km[$i][$j] = (float) $el['distance']['value'] / 1000;
                $mn[$i][$j] = (float) $seg / 60;
            }
        }

        return (count($km) === $n && count($mn) === $n) ? ['km' => $km, 'min' => $mn] : null;
    }
}
