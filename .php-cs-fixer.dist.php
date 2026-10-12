<?php

declare(strict_types=1);

// Règles reprises de barlito/utils, embarquées ici pour garder le dépôt autonome.
// https://cs.symfony.com/doc/rules/index.html

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        '@PHP8x4Migration' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_summary' => false,
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_order' => true,
        'single_line_throw' => false,
        'simplified_null_return' => false,
        'yoda_style' => true,
        'blank_line_after_opening_tag' => true,
        'phpdoc_to_comment' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'operator_linebreak' => ['position' => 'beginning', 'only_booleans' => true],
        'types_spaces' => ['space' => 'single'],
        'class_definition' => ['single_line' => true, 'space_before_parenthesis' => true],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
    ])
;
