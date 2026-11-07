<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Alta de un negocio nuevo con su dueño: demo creada por Tukán (panel de Tukán o Panel Tukán Central, también desde un pedido de demo).
 * Se carga con datos de ejemplo para que pueda cotizar desde el primer minuto: espacios, catálogo,
 * paquetes, contrato, lista de tareas y mobiliario. Todo se puede cambiar.
 */
final class Altas
{
    public function __construct(private PDO $db) {}

    public function slugLibre(string $nombre): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) : $nombre;
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-') ?: 'local';
        $base = substr($base, 0, 50);
        $slug = $base;
        $n = 1;
        $chk = $this->db->prepare('SELECT 1 FROM negocios WHERE slug = ?');
        while (true) {
            $chk->execute([$slug]);
            if (!$chk->fetchColumn()) return $slug;
            $slug = substr($base, 0, 46) . '-' . (++$n);
        }
    }

    /** registro = se registró solo (ya no existe: solo cuentas viejas), demo_pedida = demo de un pedido de demo, super = panel de Tukán, central = Panel Tukán Central. */
    public const ORIGENES = ['registro', 'demo_pedida', 'super', 'central'];

    /**
     * $origen: de dónde vino el cliente (ORIGENES). Toda cuenta nueva empieza en demo (estado "prueba").
     * @return array{negocio_id:int, usuario_id:int, slug:string}
     */
    public function crearNegocioConDueno(string $negocio, string $nombre, string $telefono, string $email, string $clave, string $plan,
                                         string $ciudad = 'Quito', string $origen = 'super'): array
    {
        if (!in_array($origen, self::ORIGENES, true)) throw new RuntimeException('Origen no válido.');
        $negocio = trim($negocio);
        $nombre = trim($nombre);
        $email = strtolower(trim($email));
        if (mb_strlen($negocio) < 3) throw new RuntimeException('Escribe el nombre de tu salón, quinta o hacienda.');
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del dueño.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        $st = $this->db->prepare('SELECT 1 FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        if (!$st->fetchColumn()) throw new RuntimeException('Elige un plan.');
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe una cuenta con ese correo. Entra con tu contraseña.');

        $planes = new Planes($this->db);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $slug = $this->slugLibre($negocio);
            $negocioId = $planes->crearNegocio($negocio, $slug, $plan);
            $this->db->prepare('UPDATE negocios SET telefono = ?, whatsapp = ?, email = ?, ciudad = ?, condiciones = ?, origen = ? WHERE id = ?')
                     ->execute([trim($telefono) ?: null, trim($telefono) ?: null, $email, trim($ciudad) ?: 'Quito', self::CONDICIONES, $origen, $negocioId]);
            $st = $this->db->prepare("INSERT INTO usuarios (negocio_id, nombre, email, telefono, password_hash, rol) VALUES (?,?,?,?,?, 'dueno') RETURNING id");
            $st->execute([$negocioId, $nombre, $email, trim($telefono) ?: null, password_hash($clave, PASSWORD_DEFAULT)]);
            $usuarioId = (int) $st->fetchColumn();
            $this->datosDeEjemplo($negocioId);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['negocio_id' => $negocioId, 'usuario_id' => $usuarioId, 'slug' => $slug];
    }

    public const CONDICIONES = "• Precios en dólares, válidos hasta la fecha indicada.\n• La fecha se separa con el pago de la reserva; sin pago, la fecha no queda asegurada.\n• El número final de invitados se confirma 15 días antes del evento.\n• El saldo se cancela hasta 15 días antes del evento.\n• Se pide una garantía por daños que se devuelve después del evento.";

    /** Espacios, catálogo, paquetes, contrato, tareas y mobiliario de ejemplo. */
    public function datosDeEjemplo(int $negocioId): void
    {
        $esp = $this->db->prepare('INSERT INTO espacios (negocio_id, nombre, descripcion, capacidad_banquete, capacidad_coctel, precio_base, color, orden) VALUES (?,?,?,?,?,?,?,?)');
        $esp->execute([$negocioId, 'Salón principal', 'Salón cubierto con pista de baile y tarima.', 250, 350, 1500, '#6B2D3C', 1]);
        $esp->execute([$negocioId, 'Jardín', 'Jardín al aire libre para ceremonias y cóctel.', 200, 300, 1200, '#3E7C59', 2]);

        $prod = $this->db->prepare('INSERT INTO productos (negocio_id, categoria, nombre, descripcion, unidad, precio, costo, con_iva, orden) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
        $ids = [];
        foreach ([
            ['menu', 'Menú tres tiempos', 'Entrada, plato fuerte y postre', 'persona', 28, 14, true],
            ['menu', 'Menú buffet', 'Dos carnes, tres guarniciones, ensaladas y postre', 'persona', 24, 12, true],
            ['menu', 'Bocaditos de sal y dulce', '6 bocaditos por persona', 'persona', 6, 2.5, true],
            ['menu', 'Torta de bodas', 'Torta de 3 pisos decorada', 'evento', 180, 90, true],
            ['bebidas', 'Barra libre nacional (5 horas)', 'Whisky, ron, vodka nacional, cerveza y cocteles', 'persona', 18, 9, true],
            ['bebidas', 'Gaseosas, agua y hielo ilimitado', '', 'persona', 3.5, 1.2, true],
            ['bebidas', 'Brindis con espumante', 'Una copa por invitado', 'persona', 3, 1.5, true],
            ['decoracion', 'Decoración floral de mesas', 'Centros de mesa con flores naturales', 'unidad', 35, 15, true],
            ['decoracion', 'Decoración de ceremonia', 'Arco, pasillo y sillas decoradas', 'evento', 450, 200, true],
            ['musica', 'DJ con sonido e iluminación (6 horas)', '', 'evento', 650, 350, true],
            ['musica', 'Hora loca', 'Animadores, accesorios y zanqueros', 'evento', 280, 150, true],
            ['foto_video', 'Fotografía y video', 'Cobertura completa, álbum y video resumen', 'evento', 900, 500, true],
            ['mobiliario', 'Sillas Tiffany', 'Silla Tiffany con cojín', 'unidad', 2.5, 0, true],
            ['personal', 'Mesero adicional', 'Por evento de hasta 8 horas', 'unidad', 45, 30, true],
            ['personal', 'Coordinadora del evento', 'Coordinación integral el día del evento', 'evento', 250, 120, true],
            ['transmision', 'Boda en vivo (QF Producciones)', 'Transmisión privada en HD para la familia que no puede asistir, con enlace para ver desde cualquier país', 'evento', 450, 300, true],
            ['otros', 'Hora adicional', 'Por cada hora extra de evento', 'hora', 150, 60, true],
            ['otros', 'Parqueadero con guardianía', '', 'evento', 120, 60, true],
        ] as $i => [$cat, $nom, $desc, $uni, $precio, $costo, $iva]) {
            $prod->execute([$negocioId, $cat, $nom, $desc ?: null, $uni, $precio, $costo, $iva ? 'true' : 'false', $i]);
            $ids[$nom] = (int) $prod->fetchColumn();
        }

        $paq = $this->db->prepare('INSERT INTO paquetes (negocio_id, nombre, descripcion, tipo_evento, minimo_invitados) VALUES (?,?,?,?,?) RETURNING id');
        $item = $this->db->prepare('INSERT INTO paquete_items (paquete_id, producto_id, cantidad, precio) VALUES (?,?,?,?)');
        $paquetes = [
            ['Paquete Boda Plata', 'Salón, menú buffet, gaseosas, brindis, DJ y decoración de mesas.', 'boda', 80, [
                ['Menú buffet', 1, 22], ['Gaseosas, agua y hielo ilimitado', 1, 3], ['Brindis con espumante', 1, 3],
                ['DJ con sonido e iluminación (6 horas)', 1, 600], ['Decoración floral de mesas', 10, 30]]],
            ['Paquete Boda Oro', 'Todo lo del Plata + menú tres tiempos, barra libre, ceremonia, hora loca, foto y video y coordinadora.', 'boda', 100, [
                ['Menú tres tiempos', 1, 26], ['Barra libre nacional (5 horas)', 1, 16], ['Gaseosas, agua y hielo ilimitado', 1, 3],
                ['Brindis con espumante', 1, 3], ['Torta de bodas', 1, 160], ['Decoración de ceremonia', 1, 400],
                ['Decoración floral de mesas', 12, 30], ['DJ con sonido e iluminación (6 horas)', 1, 600], ['Hora loca', 1, 250],
                ['Fotografía y video', 1, 850], ['Coordinadora del evento', 1, 200]]],
            ['Paquete Quince Años', 'Menú buffet, bocaditos, gaseosas, DJ, hora loca y decoración.', 'quince', 80, [
                ['Menú buffet', 1, 22], ['Bocaditos de sal y dulce', 1, 5], ['Gaseosas, agua y hielo ilimitado', 1, 3],
                ['DJ con sonido e iluminación (6 horas)', 1, 600], ['Hora loca', 1, 250], ['Decoración floral de mesas', 10, 30]]],
        ];
        foreach ($paquetes as [$nom, $desc, $tipo, $min, $lista]) {
            $paq->execute([$negocioId, $nom, $desc, $tipo, $min]);
            $pid = (int) $paq->fetchColumn();
            foreach ($lista as [$prodNombre, $cant, $precio]) $item->execute([$pid, $ids[$prodNombre], $cant, $precio]);
        }

        $this->db->prepare('INSERT INTO plantillas_contrato (negocio_id, nombre, texto, predeterminada) VALUES (?,?,?, true)')
                 ->execute([$negocioId, 'Contrato general', Contratos::plantillaDefecto()]);

        $tar = $this->db->prepare('INSERT INTO plantilla_tareas (negocio_id, tipo_evento, titulo, dias_antes, orden) VALUES (?,?,?,?,?)');
        foreach ([
            [null, 'Firmar el contrato', 150], [null, 'Elegir el menú', 60], ['boda', 'Degustación del menú con los novios', 60],
            [null, 'Definir decoración y colores', 45], ['boda', 'Reunión con el DJ: canciones de entrada, vals y baile', 30],
            [null, 'Confirmar número final de invitados', 15], [null, 'Cobrar el saldo', 15], [null, 'Recibir lista de invitados y plano de mesas', 10],
            [null, 'Confirmar proveedores externos y horarios de llegada', 7], [null, 'Asignar meseros y personal', 7],
            [null, 'Cronograma del evento aprobado por el cliente', 5], [null, 'Recibir la garantía por daños', 3],
            [null, 'Revisión final del montaje', 0], [null, 'Devolver la garantía', -8],
        ] as $i => [$tipo, $titulo, $dias]) {
            $tar->execute([$negocioId, $tipo, $titulo, $dias, $i]);
        }

        $mob = $this->db->prepare('INSERT INTO mobiliario (negocio_id, nombre, categoria, cantidad_total, costo_reposicion) VALUES (?,?,?,?,?)');
        foreach ([['Silla Tiffany', 'mobiliario', 250, 45], ['Mesa redonda (10 personas)', 'mobiliario', 25, 120], ['Mesa imperial', 'mobiliario', 6, 180],
                  ['Mantel blanco redondo', 'manteleria', 40, 25], ['Plato base', 'vajilla', 300, 6], ['Copa de vino', 'cristaleria', 300, 4],
                  ['Copa de agua', 'cristaleria', 300, 4], ['Juego de cubiertos', 'vajilla', 300, 5]] as [$nom, $cat, $cant, $costo]) {
            $mob->execute([$negocioId, $nom, $cat, $cant, $costo]);
        }
    }
}
