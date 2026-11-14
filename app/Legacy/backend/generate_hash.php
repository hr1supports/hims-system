<?php
declare(strict_types=1);

$password = readline('Enter the password to hash: ');
if ($password === false || $password === '') {
    fwrite(STDERR, "A password is required.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
