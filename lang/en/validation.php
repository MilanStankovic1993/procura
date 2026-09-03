<?php

$messages = require base_path(
    'vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php',
);

$messages['attributes'] = require __DIR__.'/validation_attributes.php';

return $messages;
