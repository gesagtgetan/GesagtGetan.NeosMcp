# Binaries live at the project root. This path works from DistributionPackages/.
root := '../..'
php-bin := if env('CI', 'false') == 'true' { 'php' } else { 'php8.4' }

[private]
default:
    just --list --unsorted

# ── Static Analysis ──────────────────────────────────────────────

[group('Static Analysis')]
[doc('Run all static analysis checks')]
check: phpcs cs-fixer phpstan

[group('Static Analysis')]
[doc('Fix code style')]
fix: phpcbf cs-fixer-fix

[group('Static Analysis')]
[doc('Run PHP Code Sniffer')]
phpcs:
	{{php-bin}} {{root}}/bin/phpcs --standard=phpcs.xml.dist

[group('Static Analysis')]
[doc('Run PHP Code Sniffer Fixer')]
phpcbf:
	{{php-bin}} {{root}}/bin/phpcbf --standard=phpcs.xml.dist

[group('Static Analysis')]
[doc('Run PHP CS Fixer (dry-run)')]
cs-fixer:
    {{php-bin}} {{root}}/bin/php-cs-fixer fix --dry-run --config=.php-cs-fixer.dist.php

[group('Static Analysis')]
[doc('Fix PHP CS Fixer issues')]
cs-fixer-fix:
    {{php-bin}} {{root}}/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

[group('Static Analysis')]
[doc('Run PHPStan')]
phpstan:
	{{php-bin}} {{root}}/bin/phpstan analyse

# ── Tests ────────────────────────────────────────────────────────

[group('Tests')]
[doc('Run unit tests')]
test-unit *args:
    {{php-bin}} {{root}}/bin/phpunit -c phpunit.xml.dist {{args}}

[group('Tests')]
[doc('Run functional tests')]
test-functional *args:
    {{php-bin}} {{root}}/bin/phpunit -c phpunit-functional.xml.dist {{args}}

[group('Tests')]
[doc('Run all tests (unit + functional)')]
test: test-unit test-functional
