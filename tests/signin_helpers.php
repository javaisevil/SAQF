<?php
declare(strict_types=1);

/**
 * Helpers for tests that sign in through the real forms: solve the robot check the way a browser does,
 * and read the e-mailed code from the demo mailbox (demo mode shows the e-mail on the code screen).
 */

/** The robot-check fields for a page's form, solved. @return array<string,string> */
function bot_fields(string $html): array
{
    if (!preg_match('/name="bot_challenge" value="([^"]+)"/', $html, $m)) {
        return [];
    }
    $challenge = html_entity_decode($m[1], ENT_QUOTES);
    [, , $bits, $salt] = explode('.', $challenge);
    for ($n = 0; !\Saqf\Security\BotGuard::solves($salt, (string) $n, (int) $bits); $n++) {
    }
    return ['bot_challenge' => $challenge, 'bot_nonce' => (string) $n];
}

/** The 6-digit code shown in the demo mailbox on the two-step page, if any. */
function demo_mail_code(string $html): ?string
{
    return preg_match('/class="demo-mail-code"[^>]*>\s*(\d{3}) (\d{3})/', $html, $m) ? $m[1] . $m[2] : null;
}
