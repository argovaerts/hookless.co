<?php
file_put_contents("logs/test_hook.log", '[ ' . date('c') . ' ] Run ' . $_SERVER['REQUEST_METHOD'] . PHP_EOL, FILE_APPEND);