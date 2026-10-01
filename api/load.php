<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/database.php';

$sql = "SELECT drawing_data FROM drawings WHERE id = 1";
$result = mysqli_query($conn, $sql);

if ($result && $row = mysqli_fetch_assoc($result)) {
    echo $row['drawing_data'];
} else {
    echo '[]';
}

mysqli_close($conn);
