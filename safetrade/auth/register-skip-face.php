<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

// FIX: Face verification is now a REQUIRED registration step — it can
// no longer be skipped. This route used to clear the pending face data
// and jump straight to the ID step; it now just sends the person back
// to actually complete face verification instead, whether or not a
// registration is still in progress.
if (empty($_SESSION['pending_registration_data'])) {
    flash_set('error', 'Your registration session expired. Please start over.');
    redirect('/auth/register.php');
}

flash_set('error', "Face verification can't be skipped — please complete it to continue.");
redirect('/auth/register.php?step=face');
