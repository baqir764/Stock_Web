<?php
require __DIR__ . '/includes/functions.php';

logout_user();
flash_set('You have been signed out.');
header('Location: login');
exit;
