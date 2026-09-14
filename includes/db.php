<?php
/**
 * Conexión a la base de datos (PDO, un solo objeto compartido por request).
 */

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(
                '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;' .
                'border:1px solid #e0b4a8;background:#fbe9e5;border-radius:12px;color:#7a2d1d;">' .
                '<h2 style="margin-top:0;">No se pudo conectar a la base de datos</h2>' .
                '<p>Revisa <code>config.php</code> (host, nombre de base, usuario y contraseña). ' .
                'Si estás en XAMPP conectando a una base remota, confirma que tu IP esté autorizada ' .
                'en el panel de tu hosting (Acceso remoto a MySQL).</p>' .
                '<p style="color:#9c4a38;font-size:.85em;">Detalle técnico: ' . htmlspecialchars($e->getMessage()) . '</p>' .
                '</div>'
            );
        }
    }

    return $pdo;
}
