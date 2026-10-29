<?php
declare(strict_types=1);

function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

/**
 * Password policy: 10+ chars, at least one letter and one number.
 * Kept deliberately simple/predictable so the registration form can mirror it exactly.
 */
function password_policy_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'Password must contain at least one letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (strlen($password) > 72) {
        $errors[] = 'Password is too long.';
    }
    return $errors;
}

function is_valid_name(string $name): bool
{
    return (bool) preg_match('/^[\p{L}\'\-\. ]{1,60}$/u', $name);
}

function is_valid_phone(string $phone): bool
{
    return $phone === '' || (bool) preg_match('/^[0-9+()\-\s]{7,30}$/', $phone);
}
