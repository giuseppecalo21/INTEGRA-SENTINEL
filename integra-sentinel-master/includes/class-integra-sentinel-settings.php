<?php
/**
 * Integra Sentinel — impostazioni centralizzate del Master, eccezioni approvate e permessi.
 *
 * Tutti i dati aggiuntivi della 1.8 vivono qui, sul Master: i Client non ricevono e non salvano nulla.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Settings {

    const OPTION     = 'integra_sentinel_settings';
    const WAIVERS    = 'integra_sentinel_waivers';
    const CAP_VIEW   = 'integra_sentinel_view';
    const CAP_MANAGE = 'integra_sentinel_manage';

    private static $cache = null;

    public static function defaults() {
        return array(
            'policies' => array(
                'quarantine_days' => 5,          // attesa consigliata per le nuove versioni x.y.0 dei nuclei
                'quarantine_scope' => 'hubs',    // hubs | all | off
                'abandoned_years' => 2,          // plugin WordPress.org senza aggiornamenti da N anni
                'disk_min_mb' => 500,
                'backup_max_days' => 7,
                'probe_minutes' => 15,
                'rotation_grace_days' => 7,
                'license_reminders' => 1,
            ),
            'bad_versions' => array(),   // array(slug, intervallo, nota)
            'freezes' => array(),        // array(dal, al, etichetta, tag)
            'stack' => array(),          // slug approvati dall'agenzia
            'estimates' => array(        // minuti stimati per tipo di intervento
                'update' => 3, 'cluster' => 30, 'manual' => 15, 'license' => 15, 'theme' => 30, 'env' => 60,
                'server' => 30, 'postupdate' => 10, 'replace' => 120, 'modified' => 60, 'missing' => 10, 'cleanup' => 10,
            ),
            'branding' => array('name' => 'Integra', 'url' => 'https://integracorporate.it/', 'logo' => '', 'color' => '#0c8997', 'footer' => '', 'from_name' => ''),
            'roles' => array('view' => array(), 'manage' => array()),
            'webhooks' => array('slack' => '', 'generic' => '', 'telegram_token' => '', 'telegram_chat' => '',
                'events' => array('integrity', 'cve', 'down', 'license', 'emergency')),
            'scan' => array('batch' => 6),
            'ai' => array('provider' => 'gemini', 'model' => '', 'base_url' => '', 'key' => '', 'register' => 'voi', 'style' => ''),
        );
    }

    public static function all() {
        if (self::$cache !== null) { return self::$cache; }
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $out = array();
        foreach (self::defaults() as $section => $default) {
            $value = $stored[$section] ?? $default;
            $out[$section] = (is_array($default) && self::is_assoc($default) && is_array($value)) ? array_merge($default, $value) : (is_array($value) ? $value : $default);
        }
        return self::$cache = $out;
    }

    public static function get($section) {
        $all = self::all();
        return $all[$section] ?? array();
    }

    public static function save($section, $value) {
        if (!array_key_exists($section, self::defaults())) { return false; }
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $stored[$section] = $value;
        self::$cache = null;
        return update_option(self::OPTION, $stored, false);
    }

    private static function is_assoc($array) {
        return is_array($array) && $array !== array() && array_keys($array) !== range(0, count($array) - 1);
    }

    /* ---------- Parser dei campi testuali ---------- */

    public static function lines($raw, $max = 300) {
        $out = array();
        foreach (array_slice(preg_split('/\r\n|\r|\n/', (string) $raw), 0, $max) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $out[] = $line;
        }
        return $out;
    }

    /** "slug | intervallo | nota" — intervallo: 3.25.0, 3.25.*, >=9.4.0,<9.4.2 */
    public static function parse_bad_versions($raw) {
        $out = array();
        foreach (self::lines($raw, 200) as $line) {
            $parts = array_map('trim', explode('|', $line));
            $slug = sanitize_title($parts[0] ?? '');
            $range = preg_replace('/\s+/', '', (string) ($parts[1] ?? ''));
            if (!$slug || $range === '' || !preg_match('/^[0-9a-z.*<>=,!_+-]{1,80}$/i', $range)) { continue; }
            $out[] = array($slug, $range, substr(sanitize_text_field($parts[2] ?? ''), 0, 160));
        }
        return $out;
    }

    /** "2026-11-20 > 2026-12-02 | Black Friday | ecommerce" (tag facoltativo: vuoto = tutti i siti) */
    public static function parse_freezes($raw) {
        $out = array();
        foreach (self::lines($raw, 60) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s*(?:>|->|→)\s*(\d{4}-\d{2}-\d{2})$/u', $parts[0] ?? '', $m) || $m[2] < $m[1]) { continue; }
            $out[] = array($m[1], $m[2], substr(sanitize_text_field($parts[1] ?? 'Periodo di blocco'), 0, 80), sanitize_title($parts[2] ?? ''));
        }
        return $out;
    }

    public static function parse_stack($raw) {
        $out = array();
        foreach (self::lines($raw, 400) as $line) {
            $slug = sanitize_title(trim(explode('|', $line)[0]));
            if ($slug) { $out[$slug] = true; }
        }
        return array_keys($out);
    }

    /** true se la versione rientra nell'intervallo (tutte le condizioni separate da virgola devono valere). */
    public static function version_in_range($version, $spec) {
        $version = ltrim((string) $version, 'vV');
        if ($version === '' || $spec === '') { return false; }
        foreach (explode(',', (string) $spec) as $token) {
            $token = trim($token);
            if ($token === '') { continue; }
            if (preg_match('/^(<=|>=|<|>|==|=|!=)\s*v?([0-9][0-9a-z.+_-]*)$/i', $token, $m)) {
                $op = $m[1] === '=' ? '==' : $m[1];
                if (!version_compare($version, $m[2], $op)) { return false; }
            } elseif (strpos($token, '*') !== false) {
                $prefix = rtrim(str_replace('*', '', $token), '.');
                if ($prefix !== '' && $version !== $prefix && strpos($version, $prefix . '.') !== 0) { return false; }
            } elseif (!version_compare($version, ltrim($token, 'vV'), '==')) {
                return false;
            }
        }
        return true;
    }

    /** Regola "versione da evitare" che coincide con slug e versione, o null. */
    public static function bad_version($slug, $version) {
        if ($version === '' || $version === null) { return null; }
        foreach ((array) self::get('bad_versions') as $rule) {
            if (is_array($rule) && ($rule[0] ?? '') === $slug && self::version_in_range($version, (string) ($rule[1] ?? ''))) {
                return array('range' => (string) $rule[1], 'note' => (string) ($rule[2] ?? ''));
            }
        }
        return null;
    }

    /** Periodo di blocco attivo oggi per un sito (in base ai tag), o null. */
    public static function active_freeze($tags = array()) {
        $today = wp_date('Y-m-d');
        foreach ((array) self::get('freezes') as $f) {
            if (!is_array($f) || count($f) < 3) { continue; }
            if ($today < $f[0] || $today > $f[1]) { continue; }
            $tag = (string) ($f[3] ?? '');
            if ($tag === '' || in_array($tag, (array) $tags, true)) { return array('from' => $f[0], 'to' => $f[1], 'label' => $f[2], 'tag' => $tag); }
        }
        return null;
    }

    /* ---------- Eccezioni approvate ("non aggiornare / accetto il rischio") ---------- */

    public static function waivers() {
        $list = get_option(self::WAIVERS, array());
        return is_array($list) ? $list : array();
    }

    public static function waiver_add($site_id, $kind, $subject, $reason, $until, $fleet = false) {
        $kind = sanitize_key($kind);
        $subject = substr(sanitize_text_field($subject), 0, 120);
        if ($kind === '' || $subject === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $until)) { return false; }
        $list = self::waivers();
        $id = substr(md5(($fleet ? '*' : $site_id) . '|' . $kind . '|' . $subject), 0, 12);
        $user = wp_get_current_user();
        $list[$id] = array(
            'id' => $id, 'site_id' => $fleet ? '*' : substr((string) $site_id, 0, 36), 'kind' => $kind, 'subject' => $subject,
            'reason' => substr(sanitize_text_field($reason), 0, 240), 'until' => $until,
            'by' => (string) ($user->user_login ?? ''), 'at' => time(),
        );
        return update_option(self::WAIVERS, array_slice($list, -500, null, true), false);
    }

    public static function waiver_remove($id) {
        $list = self::waivers();
        if (!isset($list[$id])) { return false; }
        unset($list[$id]);
        return update_option(self::WAIVERS, $list, false);
    }

    public static function waiver_for($site_id, $kind, $subject) {
        static $today = null;
        if ($today === null) { $today = wp_date('Y-m-d'); }
        foreach (self::waivers() as $w) {
            if (($w['kind'] ?? '') !== $kind || ($w['subject'] ?? '') !== $subject) { continue; }
            if (($w['site_id'] ?? '') !== '*' && ($w['site_id'] ?? '') !== $site_id) { continue; }
            if (($w['until'] ?? '') >= $today) { return $w; }
        }
        return null;
    }

    /* ---------- Permessi: visione e gestione assegnabili ai ruoli, senza modificare i ruoli salvati ---------- */

    public static function grant_caps($allcaps, $caps, $args, $user) {
        if (!array_intersect((array) $caps, array(self::CAP_VIEW, self::CAP_MANAGE))) { return $allcaps; }
        $roles = is_object($user) ? (array) $user->roles : array();
        $cfg = self::get('roles');
        $manage = !empty($allcaps['manage_options']) || (bool) array_intersect($roles, (array) ($cfg['manage'] ?? array()));
        $view = $manage || (bool) array_intersect($roles, (array) ($cfg['view'] ?? array()));
        if ($manage) { $allcaps[self::CAP_MANAGE] = true; }
        if ($view) { $allcaps[self::CAP_VIEW] = true; }
        return $allcaps;
    }

    public static function can_view() { return current_user_can(self::CAP_VIEW); }
    public static function can_manage() { return current_user_can(self::CAP_MANAGE); }
}
