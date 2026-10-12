<?php
/**
 * What setup/apply-environment.php writes, decided with no database (docs/apply-environment.md).
 *
 * The installation's own core/config/application.php says everything that differs between
 * environments, under 'settings' => ['system' => [key => value], 'contexts' => [ctx => [key => value]]].
 * settings() refuses a file that does not say it; plan() compares it with what the database holds.
 * Both are pure, so tests/apply-environment.php runs them in the deploy's --before-apply set.
 */
namespace Pustak\Setup;

final class ApplyEnvironment
{
    /** Keys whose values are never printed: a credential in a deploy log is a credential leaked. */
    public const WITHHELD = '/secret|token|password|passwd|key$/i';

    /**
     * The settings an application.php array declares, checked. Throws \RuntimeException naming
     * what is wrong; nothing is written from a file that fails here.
     */
    public static function settings($app): array
    {
        if (!is_array($app)) {
            throw new \RuntimeException('application.php does not return an array');
        }
        $env = $app['environment'] ?? null;
        if (!is_string($env) || trim($env) === '') {
            throw new \RuntimeException("application.php names no 'environment' — this installation does not say which it is");
        }
        $s = $app['settings'] ?? null;
        if (!is_array($s)) {
            throw new \RuntimeException("application.php has no 'settings' — nothing says what differs in '$env'");
        }
        $extra = array_diff(array_keys($s), ['system', 'contexts']);
        if ($extra) {
            throw new \RuntimeException("application.php 'settings' holds " . implode(', ', $extra) . " — only 'system' and 'contexts'");
        }
        $out = ['system' => self::pairs($s['system'] ?? [], 'system'), 'contexts' => []];
        $ctxs = $s['contexts'] ?? [];
        if (!is_array($ctxs)) {
            throw new \RuntimeException("application.php 'settings.contexts' is not a map of context => settings");
        }
        foreach ($ctxs as $ctx => $pairs) {
            if (!is_string($ctx) || $ctx === '' || $ctx === 'mgr') {
                throw new \RuntimeException("application.php 'settings.contexts' names " . var_export($ctx, true) . ', not a context');
            }
            $out['contexts'][$ctx] = self::pairs($pairs, "contexts.$ctx");
        }
        return $out;
    }

    private static function pairs($pairs, string $where): array
    {
        if (!is_array($pairs)) {
            throw new \RuntimeException("application.php 'settings.$where' is not a map of key => value");
        }
        foreach ($pairs as $k => $v) {
            if (!is_string($k) || $k === '') {
                throw new \RuntimeException("application.php 'settings.$where' has a key that is not a name");
            }
            if (!is_string($v)) {
                throw new \RuntimeException("application.php 'settings.$where.$k' is " . gettype($v) . ', not a string');
            }
        }
        return $pairs;
    }

    /**
     * Every value the database must change: [scope, key, from, to], scope 'system' or a context key.
     * $have has the same shape as settings(), a value null where the row does not exist.
     */
    public static function plan(array $want, array $have): array
    {
        $changes = [];
        foreach ($want['system'] as $k => $v) {
            $from = $have['system'][$k] ?? null;
            if ($from !== $v) {
                $changes[] = ['system', $k, $from, $v];
            }
        }
        foreach ($want['contexts'] as $ctx => $pairs) {
            foreach ($pairs as $k => $v) {
                $from = $have['contexts'][$ctx][$k] ?? null;
                if ($from !== $v) {
                    $changes[] = [$ctx, $k, $from, $v];
                }
            }
        }
        return $changes;
    }

    /** One line for a change, the value withheld where the key names a credential. */
    public static function line(array $c): string
    {
        [$scope, $k, $from, $to] = $c;
        if (preg_match(self::WITHHELD, $k)) {
            return sprintf('%s %s: %s', $scope, $k, $from === null ? 'created (value not printed)' : 'changed (value not printed)');
        }
        return sprintf('%s %s: %s -> %s', $scope, $k, $from === null ? '(no row)' : var_export($from, true), var_export($to, true));
    }
}
