<?php

namespace NoncePay\Whmcs;

/**
 * Customer-facing strings. Add a language by dropping lang/<whmcs language>.php next to english.php.
 */
final class Lang
{
    /** @var array<string,string> */
    private $strings;

    public function __construct(string $language)
    {
        $english = require __DIR__ . '/../lang/english.php';
        $language = strtolower(preg_replace('/[^A-Za-z_-]/', '', $language));
        $file = __DIR__ . '/../lang/' . $language . '.php';
        $this->strings = $language !== '' && $language !== 'english' && is_file($file)
            ? array_merge($english, require $file)
            : $english;
    }

    /** The client's language, else the visitor's session language, else the system default. */
    public static function forParams(array $params): self
    {
        $language = (string) ($params['clientdetails']['language'] ?? '');
        if ($language === '' && isset($_SESSION['Language'])) {
            $language = (string) $_SESSION['Language'];
        }
        if ($language === '' && isset($GLOBALS['CONFIG']['Language'])) {
            $language = (string) $GLOBALS['CONFIG']['Language'];
        }
        return new self($language);
    }

    public function get(string $key, string ...$args): string
    {
        $text = $this->strings[$key] ?? $key;
        return $args ? vsprintf($text, $args) : $text;
    }
}
