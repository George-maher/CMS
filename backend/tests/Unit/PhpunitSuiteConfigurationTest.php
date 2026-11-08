<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * F-10 — guard against duplicate test execution.
 *
 * The Feature suite is a directory glob and the Security suite is an explicit
 * file list, and every file in the Security list lives inside tests/Feature.
 * Without the matching `<exclude>` entries, each of those files is executed
 * twice per run: the count reported by PHPUnit is inflated and, on PostgreSQL,
 * the same concurrency/isolation tests run twice against the same database.
 *
 * This test fails as soon as the two lists drift apart, so the duplication
 * cannot return silently and a security test cannot drop out of the full run
 * unnoticed.
 */
class PhpunitSuiteConfigurationTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../phpunit.xml';

    public function test_security_suite_files_are_excluded_from_the_feature_suite(): void
    {
        $xml = $this->configuration();

        $securityFiles = $this->paths($xml, 'Security', 'file');
        $featureExcludes = $this->paths($xml, 'Feature', 'exclude');

        $this->assertNotEmpty($securityFiles, 'The Security suite list is empty.');

        foreach ($securityFiles as $path) {
            $this->assertContains(
                $path,
                $featureExcludes,
                "{$path} is in the Security suite but not excluded from the Feature suite, so it runs twice per suite run."
            );
        }
    }

    public function test_every_security_suite_file_exists(): void
    {
        $xml = $this->configuration();

        foreach ($this->paths($xml, 'Security', 'file') as $path) {
            $this->assertFileExists(
                dirname(self::CONFIG).'/'.$path,
                "{$path} is listed in the Security suite but does not exist."
            );
        }
    }

    public function test_every_feature_exclude_points_at_an_existing_security_file(): void
    {
        $xml = $this->configuration();

        $securityFiles = $this->paths($xml, 'Security', 'file');

        foreach ($this->paths($xml, 'Feature', 'exclude') as $path) {
            $this->assertContains(
                $path,
                $securityFiles,
                "{$path} is excluded from the Feature suite but is not in the Security suite, so it would not run at all."
            );

            $this->assertFileExists(
                dirname(self::CONFIG).'/'.$path,
                "{$path} is excluded from the Feature suite but does not exist."
            );
        }
    }

    public function test_security_suite_does_not_list_a_file_twice(): void
    {
        $xml = $this->configuration();

        $securityFiles = $this->paths($xml, 'Security', 'file');

        $this->assertSame(
            array_values(array_unique($securityFiles)),
            $securityFiles,
            'The Security suite lists the same file more than once.'
        );
    }

    private function configuration(): SimpleXMLElement
    {
        $xml = simplexml_load_file(self::CONFIG);

        if (! $xml instanceof SimpleXMLElement) {
            self::fail('phpunit.xml could not be parsed.');
        }

        return $xml;
    }

    /**
     * @return array<int, string>
     */
    private function paths(SimpleXMLElement $xml, string $suite, string $child): array
    {
        $nodes = $xml->xpath("/phpunit/testsuites/testsuite[@name='{$suite}']/{$child}");

        if (! is_array($nodes)) {
            return [];
        }

        $paths = [];
        foreach ($nodes as $node) {
            if ($node instanceof SimpleXMLElement) {
                $paths[] = (string) $node;
            }
        }

        return $paths;
    }
}
