<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'sistema';

$con = mysqli_connect($servername, $username, $password, $dbname);
mysqli_set_charset($con, 'utf8mb4');
