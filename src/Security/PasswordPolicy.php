<?php
declare(strict_types=1);

namespace Saqf\Security;

use Saqf\Core\Policy;

/**
 * Password rules for SAQF accounts that sign in with a password (administrators' break-glass
 * access, or every account where SSO is not in use). Beyond length and character mix, it refuses
 * passwords built on common words, keyboard runs, repeated characters, the person's own name or
 * username and the university's name — the passwords attackers try first. Letter substitutions
 * such as P@ssw0rd do not get around it.
 */
final class PasswordPolicy
{
    /** Words attackers try first (base forms; digits and symbols around them do not help). */
    private const COMMON = [
        'password', 'passwd', 'welcome', 'letmein', 'qwerty', 'admin', 'administrator', 'iloveyou', 'monkey', 'dragon', 'football', 'baseball',
        'soccer', 'master', 'shadow', 'sunshine', 'princess', 'superman', 'batman', 'trustno', 'starwars', 'whatever', 'freedom', 'computer',
        'internet', 'secret', 'summer', 'winter', 'spring', 'autumn', 'january', 'february', 'march', 'april', 'august', 'september',
        'october', 'november', 'december', 'monday', 'friday', 'sunday', 'hello', 'charlie', 'michael', 'jennifer', 'jordan', 'hunter',
        'ranger', 'killer', 'pepper', 'ginger', 'cheese', 'mustang', 'harley', 'thomas', 'robert', 'daniel', 'andrew', 'joshua', 'matthew',
        'ashley', 'jessica', 'michelle', 'amanda', 'nicole', 'chelsea', 'taylor', 'matrix', 'thunder', 'access', 'login', 'guest',
        'default', 'changeme', 'temp', 'temporary', 'test', 'testing', 'demo', 'system', 'server', 'oracle', 'mysql', 'root', 'toor',
        'ubuntu', 'linux', 'windows', 'microsoft', 'google', 'apple', 'samsung', 'facebook', 'twitter', 'instagram', 'github', 'student',
        'teacher', 'professor', 'doctor', 'university', 'college', 'school', 'academy', 'faculty', 'campus', 'library', 'saudi', 'arabia',
        'riyadh', 'jeddah', 'makkah', 'mecca', 'madinah', 'dammam', 'khobar', 'allah', 'mohammed', 'muhammad', 'mohamed', 'ahmed', 'ahmad',
        'abdullah', 'abdulaziz', 'abdulrahman', 'khalid', 'faisal', 'fahad', 'saud', 'sultan', 'salman', 'nasser', 'saleh', 'hassan',
        'hussein', 'ibrahim', 'yousef', 'youssef', 'majed', 'turki', 'bandar', 'fatimah', 'fatima', 'noura', 'sarah', 'aisha', 'maryam',
        'mariam', 'yamamah', 'alyamamah', 'saqf', 'aqms', 'quality', 'accreditation', 'ncaaa', 'kingdom', 'vision', 'ramadan', 'falcon',
        'hilal', 'alhilal', 'nassr', 'alnassr', 'ittihad', 'alahli', 'abcdef', 'asdfgh', 'zxcvbn', 'qazwsx', 'passpass', 'mypassword',
        'newpassword', 'love', 'lovely', 'angel', 'baby', 'family', 'friend', 'happy', 'money', 'secure', 'security', 'super', 'power',
    ];
    private const SEQUENCES = ['abcdefghijklmnopqrstuvwxyz', '01234567890', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1qaz2wsx3edc', 'qazwsxedc'];
    private const LEET = ['@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i', '0' => 'o', '$' => 's', '5' => 's', '7' => 't', '+' => 't', '8' => 'b', '9' => 'g'];

    /** @return string|null why the password is refused, or null when acceptable */
    public static function problem(string $password, array $person = []): ?string
    {
        $min = Policy::get('auth.min_password_length');
        if (mb_strlen($password) < $min) {
            return "Use at least $min characters.";
        }
        if (mb_strlen($password) > 200) {
            return 'Use at most 200 characters.';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            return 'Use both letters and numbers.';
        }
        $lower = mb_strtolower($password);
        if (preg_match('/(.)\1{3,}/u', $lower) || count(array_unique(mb_str_split($lower))) < 5) {
            return 'Avoid repeating the same characters.';
        }
        foreach (self::SEQUENCES as $seq) {
            for ($i = 0; $i + 6 <= strlen($seq); $i++) {
                $run = substr($seq, $i, 6);
                if (str_contains($lower, $run) || str_contains($lower, strrev($run))) {
                    return 'Avoid keyboard or alphabet sequences such as "' . $run . '".';
                }
            }
        }
        $plain = strtr($lower, self::LEET);
        $letters = (string) preg_replace('/[^a-z]/', '', $plain);
        foreach (self::personal($person) as $word) {
            if (strlen($word) >= 3 && str_contains($letters, $word)) {
                return 'The password must not contain your name or username.';
            }
        }
        foreach (self::COMMON as $word) {
            // Refuse when a common word makes up most of the password (e.g. "Welcome2026!", "P@ssw0rd123").
            if (str_contains($letters, $word) && strlen($word) >= max(4, (int) ceil(strlen($letters) * 0.6))) {
                return 'That password is too easy to guess: it is built on a common word ("' . $word . '"). Use a passphrase of several unrelated words.';
            }
        }
        return null;
    }

    /** Lower-case name parts, username parts and e-mail local part. @return list<string> */
    private static function personal(array $p): array
    {
        $parts = [];
        foreach ([(string) ($p['username'] ?? ''), (string) ($p['full_name'] ?? ''), (string) strstr((string) ($p['email'] ?? '') . '@', '@', true)] as $s) {
            foreach (preg_split('/[^a-z]+/', strtr(mb_strtolower($s), self::LEET)) ?: [] as $w) {
                if (strlen($w) >= 3 && !in_array($w, ['dr', 'prof', 'mr', 'mrs', 'al'], true)) {
                    $parts[] = $w;
                }
            }
        }
        return array_values(array_unique($parts));
    }
}
