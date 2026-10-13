<?php
declare(strict_types=1);
require __DIR__ . '/_comun.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
