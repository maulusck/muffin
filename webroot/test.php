<?php

echo "PHP OK\n\n";

echo "Version: " . phpversion() . "\n\n";

echo "Loaded extensions:\n";
print_r(get_loaded_extensions());

echo "\nServer info:\n";
print_r($_SERVER);
