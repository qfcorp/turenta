<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;

/**
 * Conexión a PostgreSQL. Los datos de acceso vienen de variables de entorno
 * (nunca escritos en el código):
 *   TURENTA_DB_HOST, TURENTA_DB_PORT, TURENTA_DB_NAME, TURENTA_DB_USER, TURENTA_DB_PASS
 */
final class Db
{
    public static function conectar(): PDO
    {
        $host = getenv('TURENTA_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TURENTA_DB_PORT') ?: '5432';
        $name = getenv('TURENTA_DB_NAME') ?: 'turenta';
        $user = getenv('TURENTA_DB_USER') ?: 'turenta';
        $pass = getenv('TURENTA_DB_PASS') ?: '';

        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET TIME ZONE 'America/Guayaquil'");
        return $pdo;
    }
}
