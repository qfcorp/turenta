<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Mapa para la reserva en línea (migración 201): dónde queda la fiesta y cuánto se demora el equipo en llegar desde la base.
 * Mismo orden que las rutas de TuGuardería (qfcorp/tuguarderia, app/negocio.php):
 *  1. Google (Routes API con tráfico; Geocoding API) si el negocio puso su clave.
 *  2. Gratis: calles reales con OSRM; direcciones con el geocodificador del Censo de EE.UU. y OpenStreetMap (Nominatim).
 *  3. Último recurso del viaje: línea recta × 1,35 a 40 km/h (se marca "aproximado"). Nunca queda sin número.
 * Todo lo buscado se guarda en mapa_cache: cada dirección y cada viaje se paga una sola vez.
 */
final class Mapas
{
    public const GOOGLE_RUTAS = 'https://routes.googleapis.com/directions/v2:computeRoutes';
    public const GOOGLE_DIRECCIONES = 'https://maps.googleapis.com/maps/api/geocode/json';
    public const CENSO = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress';
    public const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
    public const OSRM = 'https://router.project-osrm.org';
    /** Días que sirve un viaje guardado (las calles cambian poco) y una dirección guardada. */
    public const DIAS_VIAJE = 30;
    public const DIAS_PUNTO = 180;

    /** @var callable fn(string $url, ?array $cuerpoJson, array $cabeceras): ?array */
    private $http;

    public function __construct(private PDO $db, ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'llamar'];
    }

    public static function claveValida(string $clave): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{30,120}$/', $clave);
    }

    /** ¿El negocio tiene el viaje con mapa encendido y su base marcada? */
    public static function activo(array $negocio): bool
    {
        return !empty($negocio['viaje_mapa']) && $negocio['base_lat'] !== null && $negocio['base_lng'] !== null
            && $negocio['base_lat'] !== '' && $negocio['base_lng'] !== '';
    }

    /**
     * Coordenadas de una dirección: ['lat' => , 'lng' => , 'metodo' => ] o null si no se encontró.
     */
    public function punto(array $negocio, string $direccion, string $zip = ''): ?array
    {
        $texto = trim(preg_replace('/\s+/', ' ', $direccion) ?? '');
        $zip = (string) preg_replace('/\D/', '', $zip);
        if (mb_strlen($texto) < 4) return null;
        $completa = $texto . ($zip !== '' && !str_contains($texto, $zip) ? ' ' . $zip : '');
        $clave = mb_substr(mb_strtolower($completa), 0, 300);
        $nid = (int) $negocio['id'];
        $guardado = $this->deCache($nid, 'punto', $clave, self::DIAS_PUNTO);
        if ($guardado && $guardado['lat'] !== null) {
            return ['lat' => (float) $guardado['lat'], 'lng' => (float) $guardado['lng'], 'metodo' => $guardado['metodo']];
        }
        $p = null;
        $key = trim((string) ($negocio['google_maps_key'] ?? ''));
        if ($key !== '') $p = $this->puntoGoogle($completa, $key);
        if (!$p && ($negocio['idioma_publico'] ?? 'es') === 'en') $p = $this->puntoCenso($completa);
        if (!$p) $p = $this->puntoNominatim($completa, ($negocio['idioma_publico'] ?? 'es') === 'en' ? 'us' : '');
        if (!$p) return null;
        $this->aCache($nid, 'punto', $clave, $p['lat'], $p['lng'], null, null, $p['metodo']);
        return $p;
    }

    /**
     * Viaje de UNA ida desde la base del negocio hasta el punto: ['min' => int, 'km' => float, 'metodo' => string].
     * $fecha (Y-m-d) se usa para el tráfico típico de ese día con Google. Null si el negocio no tiene base.
     */
    public function viaje(array $negocio, float $lat, float $lng, ?string $fecha = null, ?DateTimeImmutable $ahora = null): ?array
    {
        if ($negocio['base_lat'] === null || $negocio['base_lng'] === null || $negocio['base_lat'] === '') return null;
        $bLat = (float) $negocio['base_lat'];
        $bLng = (float) $negocio['base_lng'];
        $nid = (int) $negocio['id'];
        $clave = sprintf('%.5f,%.5f>%.5f,%.5f|%s', $bLat, $bLng, $lat, $lng, $fecha ?? '-');
        $guardado = $this->deCache($nid, 'viaje', $clave, self::DIAS_VIAJE);
        if ($guardado && $guardado['minutos'] !== null) {
            return ['min' => (int) $guardado['minutos'], 'km' => (float) $guardado['km'], 'metodo' => $guardado['metodo']];
        }
        $v = null;
        $key = trim((string) ($negocio['google_maps_key'] ?? ''));
        if ($key !== '') $v = $this->viajeGoogle($bLat, $bLng, $lat, $lng, $key, $this->salida($fecha, $ahora));
        if (!$v) $v = $this->viajeOsrm($bLat, $bLng, $lat, $lng);
        if (!$v) $v = self::viajeAproximado($bLat, $bLng, $lat, $lng);
        // El aproximado no se guarda: la próxima vez se intenta otra vez con el mapa de verdad
        if ($v['metodo'] !== 'Aproximado') $this->aCache($nid, 'viaje', $clave, $lat, $lng, $v['min'], $v['km'], $v['metodo']);
        return $v;
    }

    /** Hora de salida para el tráfico de Google: las 14:00 de ese día en la zona del negocio, si todavía no pasa. */
    private function salida(?string $fecha, ?DateTimeImmutable $ahora): ?string
    {
        if ($fecha === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return null;
        $zona = new DateTimeZone(getenv('TURENTA_ZONA') ?: date_default_timezone_get());
        $t = new DateTimeImmutable("$fecha 14:00", $zona);
        $ahora ??= new DateTimeImmutable('now', $zona);
        if ($t <= $ahora) return null;
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function puntoGoogle(string $direccion, string $key): ?array
    {
        $j = ($this->http)(self::GOOGLE_DIRECCIONES . '?' . http_build_query(['address' => $direccion, 'key' => $key]), null, []);
        $loc = $j['results'][0]['geometry']['location'] ?? null;
        if (($j['status'] ?? '') !== 'OK' || !isset($loc['lat'], $loc['lng'])) return null;
        return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'], 'metodo' => 'Google'];
    }

    private function puntoCenso(string $direccion): ?array
    {
        $j = ($this->http)(self::CENSO . '?' . http_build_query(['address' => $direccion, 'benchmark' => 'Public_AR_Current', 'format' => 'json']), null, []);
        $c = $j['result']['addressMatches'][0]['coordinates'] ?? null;
        if (!isset($c['x'], $c['y'])) return null;
        return ['lat' => (float) $c['y'], 'lng' => (float) $c['x'], 'metodo' => 'Censo EE.UU.'];
    }

    private function puntoNominatim(string $direccion, string $pais): ?array
    {
        $q = ['q' => $direccion, 'format' => 'jsonv2', 'limit' => 1];
        if ($pais !== '') $q['countrycodes'] = $pais;
        $j = ($this->http)(self::NOMINATIM . '?' . http_build_query($q), null, []);
        $r = is_array($j) && array_is_list($j) ? ($j[0] ?? null) : null;
        if (!isset($r['lat'], $r['lon'])) return null;
        return ['lat' => (float) $r['lat'], 'lng' => (float) $r['lon'], 'metodo' => 'OpenStreetMap'];
    }

    private function viajeGoogle(float $la1, float $lo1, float $la2, float $lo2, string $key, ?string $salida): ?array
    {
        $pt = fn($la, $lo) => ['location' => ['latLng' => ['latitude' => $la, 'longitude' => $lo]]];
        $cuerpo = ['origin' => $pt($la1, $lo1), 'destination' => $pt($la2, $lo2), 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_AWARE'];
        if ($salida) $cuerpo['departureTime'] = $salida;
        $j = ($this->http)(self::GOOGLE_RUTAS, $cuerpo,
            ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: routes.distanceMeters,routes.duration']);
        $r = $j['routes'][0] ?? null;
        if (!$r || !isset($r['duration'])) return null;
        $seg = (int) rtrim((string) $r['duration'], 's');
        return ['min' => (int) ceil($seg / 60), 'km' => round(((int) ($r['distanceMeters'] ?? 0)) / 1000, 2), 'metodo' => 'Google (tráfico)'];
    }

    private function viajeOsrm(float $la1, float $lo1, float $la2, float $lo2): ?array
    {
        $base = rtrim((string) (getenv('TURENTA_OSRM') ?: self::OSRM), '/');
        $j = ($this->http)(sprintf('%s/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false', $base, $lo1, $la1, $lo2, $la2), null, []);
        $r = $j['routes'][0] ?? null;
        if (($j['code'] ?? '') !== 'Ok' || !isset($r['duration'])) return null;
        return ['min' => (int) ceil(((float) $r['duration']) / 60), 'km' => round(((float) $r['distance']) / 1000, 2), 'metodo' => 'Calles reales'];
    }

    public static function viajeAproximado(float $la1, float $lo1, float $la2, float $lo2): array
    {
        $km = self::distanciaKm($la1, $lo1, $la2, $lo2) * 1.35;
        return ['min' => (int) ceil($km / 40 * 60), 'km' => round($km, 2), 'metodo' => 'Aproximado'];
    }

    public static function distanciaKm(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371;
        $dl = deg2rad($la2 - $la1);
        $dn = deg2rad($lo2 - $lo1);
        $a = sin($dl / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dn / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function deCache(int $nid, string $tipo, string $clave, int $dias): ?array
    {
        $st = $this->db->prepare("SELECT lat, lng, minutos, km, metodo FROM mapa_cache
                                   WHERE negocio_id = ? AND tipo = ? AND clave = ? AND creado_en > now() - make_interval(days => ?)");
        $st->execute([$nid, $tipo, $clave, $dias]);
        return $st->fetch() ?: null;
    }

    private function aCache(int $nid, string $tipo, string $clave, ?float $lat, ?float $lng, ?int $min, ?float $km, string $metodo): void
    {
        $this->db->prepare('INSERT INTO mapa_cache (negocio_id, tipo, clave, lat, lng, minutos, km, metodo) VALUES (?,?,?,?,?,?,?,?)
                            ON CONFLICT (negocio_id, tipo, clave) DO UPDATE SET lat = EXCLUDED.lat, lng = EXCLUDED.lng, minutos = EXCLUDED.minutos,
                                   km = EXCLUDED.km, metodo = EXCLUDED.metodo, creado_en = now()')
                 ->execute([$nid, $tipo, $clave, $lat, $lng, $min, $km, $metodo]);
    }

    /** Llamada real (GET, o POST con JSON si hay cuerpo). Devuelve el JSON o null si falló. */
    public static function llamar(string $url, ?array $cuerpo, array $cabeceras): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: TuRenta/1.0 (tukanec.com)'], $cabeceras)]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo));
        }
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log('TuRenta Mapas: ' . parse_url($url, PHP_URL_HOST) . " falló (HTTP $codigo)");
            return null;
        }
        $j = json_decode((string) $r, true);
        return is_array($j) ? $j : null;
    }
}
