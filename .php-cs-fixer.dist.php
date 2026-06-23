<?php declare(strict_types=1);

/**
 * PHP CS Fixer rules applied across the project.
 */
const PHP_CS_FIXER_RULES = [
    // Enforce the PSR-12 coding style standard.
    '@PSR12' => true,

    // Require `declare(strict_types=1);` at the beginning of PHP files.
    'declare_strict_types' => true,

    // Use short array syntax (`[]`) instead of `array()`.
    'array_syntax' => ['syntax' => 'short'],

    // Remove unused `use` import statements.
    'no_unused_imports' => true,

    // Sort import statements into a consistent order.
    'ordered_imports' => true,

    // Prefer single quotes over double quotes when interpolation is not needed.
    'single_quote' => true,

    // Add trailing commas to multiline arrays, function calls, and similar constructs.
    'trailing_comma_in_multiline' => true,

    // Normalize spacing around binary operators (e.g. `=`, `+`, `=>`).
    'binary_operator_spaces' => true,

    // Ensure a blank line exists after the opening PHP tag.
    'blank_line_after_opening_tag' => true,
];

/**
 * Directories to be scanned and formatted by PHP CS Fixer.
 */
const PHP_CS_FIXER_PATHS = [
    __DIR__ . '/src',
    __DIR__ . '/tests',
];

/**
 * Build the finder and apply the configured rules.
 */
$finder = PhpCsFixer\Finder::create();

foreach (PHP_CS_FIXER_PATHS as $path) {
    $finder->in($path);
}

$config = new PhpCsFixer\Config();
$config->setRiskyAllowed(true);
$config->setRules(PHP_CS_FIXER_RULES);
$config->setFinder($finder);
return $config;
