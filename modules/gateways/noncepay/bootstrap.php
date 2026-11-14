<?php

spl_autoload_register(function ($class) {
    $prefix = 'NoncePay\\Whmcs\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/lib/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
