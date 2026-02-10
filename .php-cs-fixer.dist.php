<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@DoctrineAnnotation' => true,
        '@Symfony' => true,
        'no_superfluous_phpdoc_tags' => false,
        'concat_space' => ['spacing' => 'one'],
        'yoda_style' => false,
        'phpdoc_align' => ['align' => 'left'],
        'types_spaces' => ['space_multiple_catch' => 'single'],
        'class_definition' => ['space_before_parenthesis' => true],
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
