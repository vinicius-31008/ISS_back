<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'sistema';

try {
    $con = mysqli_connect($servername, $username, $password, $dbname);
    mysqli_set_charset($con, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Falha na conexão com o banco de dados: ' . htmlspecialchars($e->getMessage()));
}
