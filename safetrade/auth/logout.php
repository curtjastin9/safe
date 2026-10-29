<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

logout_user();
flash_set('success', "You've been logged out.");
redirect('/auth/login.php');
