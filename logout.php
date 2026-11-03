<?php
require_once __DIR__ . '/lib/bootstrap.php';
start_session();
$_SESSION = [];
session_destroy();
header('Location: index.php');
