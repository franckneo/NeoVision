<?php
require_once '/var/www/common/init.php';
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    exit("no");
}
shell_exec('nohup sudo /opt/supervision/venv/bin/python /opt/supervision/update_lstatus.py > /tmp/update.log 2>&1 &');
echo json_encode(["success" => true]);
?>
