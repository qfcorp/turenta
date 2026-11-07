<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuenta para que el revisor de Google Play pruebe la app sin ver datos reales: un negocio de ejemplo APARTE
 * («Quinta Ejemplo Google Play», en demo por 1 año, sin pagos registrados: no cuenta en las finanzas de Tukán) con espacios,
 * catálogo, paquetes y 6 eventos de ejemplo en distintas etapas, y su dueño revisor.google@tukanec.com.
 * Los clientes de ejemplo solo tienen correos de example.com y teléfonos 0990000xxx. No toca ningún otro negocio.
 * Se usa con deploy/demo_google.php crear | borrar (la contraseña sale una sola vez y no se guarda en ningún lado).
 */
final class DemoGoogle
{
    public const CORREO = 'revisor.google@tukanec.com';
    public const NEGOCIO = 'Quinta Ejemplo Google Play';

    public function __construct(private PDO $db) {}

    /** Id del negocio de ejemplo (o null). Si el correo es de un negocio que NO es el de ejemplo, se niega. */
    public function negocioId(): ?int
    {
        $st = $this->db->prepare('SELECT n.id, n.nombre FROM usuarios u JOIN negocios n ON n.id = u.negocio_id WHERE lower(u.email) = ?');
        $st->execute([self::CORREO]);
        $f = $st->fetch();
        if (!$f) return null;
        if ($f['nombre'] !== self::NEGOCIO) {
            throw new RuntimeException('El correo ' . self::CORREO . ' es de otro negocio (' . $f['nombre'] . '), que NO es de ejemplo. No se toca nada.');
        }
        return (int) $f['id'];
    }

    /** ¿Este usuario es el revisor de Google? (no puede cambiar su contraseña: se le daría la clave a Google) */
    public static function esRevisor(?array $u): bool
    {
        return $u !== null && strtolower((string) ($u['email'] ?? '')) === self::CORREO;
    }

    /** Crea (o vuelve a crear con fechas de hoy y contraseña nueva) el negocio de ejemplo. @return array{clave:string, negocio_id:int, eventos:int} */
    public function crear(?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $clave = 'Demo-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->beginTransaction();
        try {
            $viejo = $this->negocioId();
            if ($viejo !== null) $this->borrarNegocio($viejo);
            $alta = (new Altas($this->db))->crearNegocioConDueno(self::NEGOCIO, 'Revisor Google Play', '0990000000', self::CORREO, $clave, 'quinta', 'Quito', 'super');
            $nid = (int) $alta['negocio_id'];
            // Demo por 1 año (no vence durante la revisión) y sin pagos: no entra en lo cobrado ni en el ingreso mensual de Tukán
            $this->db->prepare("UPDATE negocios SET estado = 'prueba', prueba_hasta = ?, direccion = 'Dirección de ejemplo, Quito', telefono = NULL, whatsapp = NULL
                                 WHERE id = ?")->execute([$hoy->modify('+365 days')->format('Y-m-d'), $nid]);
            $this->db->prepare('UPDATE usuarios SET clave_temporal = false WHERE id = ?')->execute([$alta['usuario_id']]);
            $eventos = $this->eventosDeEjemplo($nid, (int) $alta['usuario_id'], $hoy);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['clave' => $clave, 'negocio_id' => $nid, 'eventos' => $eventos];
    }

    /** Borra el negocio de ejemplo y todo lo suyo. Devuelve 1 si había uno, 0 si no. */
    public function borrar(): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->negocioId();
            if ($id !== null) $this->borrarNegocio($id);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $id === null ? 0 : 1;
    }

    private function borrarNegocio(int $id): void
    {
        $st = $this->db->prepare('SELECT nombre FROM negocios WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() !== self::NEGOCIO) throw new RuntimeException('Ese negocio no es el de ejemplo. No se toca nada.');
        foreach (array_reverse((new Papelera($this->db))->tablas(), true) as $t => $x) {   // de hija a madre (respeta los RESTRICT)
            if ($x['propia']) $this->db->exec('DELETE FROM "' . str_replace('"', '""', $t) . '" WHERE ' . sprintf($x['condicion'], $id));
        }
        $this->db->prepare('DELETE FROM tukan_ia_gasto WHERE negocio_id = ?')->execute([(string) $id]);
    }

    /** Clientes y eventos inventados: una boda confirmada, quince años separado, corporativo cotizado, consultas y un evento realizado. */
    private function eventosDeEjemplo(int $nid, int $duenoId, DateTimeImmutable $hoy): int
    {
        $d = fn(int $n) => $hoy->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
        $dueno = $this->db->query("SELECT * FROM usuarios WHERE id = $duenoId")->fetch();
        $ev = new Eventos($this->db); $cot = new Cotizaciones($this->db); $pag = new Pagos($this->db); $con = new Contratos($this->db);
        $esp = fn(string $n) => (int) $this->db->query("SELECT id FROM espacios WHERE negocio_id = $nid AND nombre = " . $this->db->quote($n))->fetchColumn();
        $paq = fn(string $n) => (int) $this->db->query("SELECT id FROM paquetes WHERE negocio_id = $nid AND nombre = " . $this->db->quote($n))->fetchColumn();
        $prod = fn(string $n) => (int) $this->db->query("SELECT id FROM productos WHERE negocio_id = $nid AND nombre = " . $this->db->quote($n))->fetchColumn();
        $salon = $esp('Salón principal'); $jardin = $esp('Jardín');
        $cli = fn(string $nombre, int $n) => ['nombre' => $nombre, 'telefono' => '0990000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                                              'email' => 'cliente' . $n . '@example.com'];
        $vender = function (int $id, ?int $paquete, string $hace) use ($cot, $nid, $dueno, $hoy): void {
            $c = $cot->crear($nid, $id, $paquete, (int) $dueno['id'], $hoy->modify($hace));
            $cot->enviar($nid, $c, $dueno);
            $cot->aceptar($nid, $c, 'Cliente', '', $dueno, $hoy->modify($hace));
        };
        $n = 0;
        // Boda confirmada con pagos y contrato enviado
        $boda = $ev->crear($nid, ['cliente' => $cli('Novios de Ejemplo', 1), 'nombre' => 'Boda de ejemplo', 'tipo' => 'boda', 'fecha' => $d(21),
            'hora_inicio' => '17:00', 'hora_fin' => '01:00', 'invitados' => 120, 'espacios' => [$salon], 'origen' => 'pagina'], $duenoId, $hoy->modify('-60 days'));
        $vender($boda, $paq('Paquete Boda Oro'), '-50 days'); $n++;
        $cuotas = $pag->cuotas($boda, $hoy);
        $pag->registrar($nid, $boda, ['monto' => $cuotas[0]['monto'], 'metodo' => 'transferencia', 'fecha' => $d(-48) . ' 11:00'], $dueno);
        $pag->registrar($nid, $boda, ['monto' => 1500, 'metodo' => 'efectivo', 'fecha' => $d(-10) . ' 16:00'], $dueno);
        $k = $con->generar($nid, $boda, null, $dueno, $hoy->modify('-45 days'));
        $con->enviar($nid, $k, $dueno);
        // Quince años separado con el pago de la reserva
        $quince = $ev->crear($nid, ['cliente' => $cli('Familia Ejemplo', 2), 'tipo' => 'quince', 'fecha' => $d(45), 'hora_inicio' => '19:00', 'hora_fin' => '02:00',
            'invitados' => 100, 'espacios' => [$salon], 'origen' => 'referido'], $duenoId, $hoy->modify('-20 days'));
        $vender($quince, $paq('Paquete Quince Años'), '-15 days'); $n++;
        $cq = $pag->cuotas($quince, $hoy);
        $pag->registrar($nid, $quince, ['monto' => $cq[0]['monto'], 'metodo' => 'transferencia', 'fecha' => $d(-14) . ' 10:00'], $dueno);
        // Corporativo cotizado (cotización enviada, sin aceptar)
        $corp = $ev->crear($nid, ['cliente' => $cli('Empresa de Ejemplo S.A.', 3), 'nombre' => 'Almuerzo de fin de año', 'tipo' => 'corporativo', 'fecha' => $d(35),
            'hora_inicio' => '12:00', 'hora_fin' => '17:00', 'invitados' => 60, 'espacios' => [$jardin], 'origen' => 'google'], $duenoId, $hoy->modify('-5 days'));
        $cc = $cot->crear($nid, $corp, null, $duenoId, $hoy->modify('-4 days'));
        $cot->agregarProducto($nid, $cc, $prod('Menú buffet'));
        $cot->agregarProducto($nid, $cc, $prod('Gaseosas, agua y hielo ilimitado'));
        $cot->enviar($nid, $cc, $dueno); $n++;
        // Consultas nuevas
        $ev->crear($nid, ['cliente' => $cli('Cliente Consulta Uno', 4), 'tipo' => 'cumpleanos', 'fecha' => $d(30), 'hora_inicio' => '20:00', 'hora_fin' => '01:00',
            'invitados' => 50, 'espacios' => [$jardin], 'origen' => 'instagram'], $duenoId, $hoy->modify('-2 days')); $n++;
        $ev->crear($nid, ['cliente' => $cli('Cliente Consulta Dos', 5), 'tipo' => 'boda', 'fecha' => $d(120), 'hora_inicio' => '18:00', 'hora_fin' => '02:00',
            'invitados' => 150, 'espacios' => [$salon], 'origen' => 'facebook'], $duenoId, $hoy->modify('-1 days')); $n++;
        // Evento ya realizado y pagado (para Reportes)
        $hecho = $ev->crear($nid, ['cliente' => $cli('Cliente Realizado', 6), 'nombre' => 'Aniversario de ejemplo', 'tipo' => 'aniversario', 'fecha' => $d(-20),
            'hora_inicio' => '18:00', 'hora_fin' => '23:00', 'invitados' => 60, 'espacios' => [$jardin], 'origen' => 'cliente_anterior'], $duenoId, $hoy->modify('-90 days'));
        $ch = $cot->crear($nid, $hecho, null, $duenoId, $hoy->modify('-80 days'));
        $cot->agregarProducto($nid, $ch, $prod('Menú tres tiempos'));
        $cot->agregarProducto($nid, $ch, $prod('DJ con sonido e iluminación (6 horas)'));
        $cot->enviar($nid, $ch, $dueno);
        $cot->aceptar($nid, $ch, 'Cliente', '', $dueno, $hoy->modify('-78 days'));
        $total = (float) $this->db->query("SELECT total FROM eventos WHERE id = $hecho")->fetchColumn();
        if ($total > 0) $pag->registrar($nid, $hecho, ['monto' => $total, 'metodo' => 'transferencia', 'fecha' => $d(-30) . ' 10:00'], $dueno);
        $ev->cambiarEstado($nid, $hecho, 'realizado', [], $dueno, $hoy); $n++;
        return $n;
    }
}
