<?php
/**
 * Integra Sentinel — fonti esterne interrogate SOLO dal Master: info e checksum di WordPress.org,
 * acquisti Envato, richieste HTTP in parallelo. I Client non fanno nessuna chiamata in piu'.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Intel {

    const WPORG        = 'integra_sentinel_wporg_info';
    const CHECKSUMS    = 'integra_sentinel_checksums';
    const ENVATO       = 'integra_sentinel_envato';
    const ENVATO_ITEMS = 'integra_sentinel_envato_items';
    const CHANGELOGS   = 'integra_sentinel_changelogs';

    /** Stessi file del Client 1.7 (core_md5): solo quelli confrontati con i checksum ufficiali. */
    public static function core_files() {
        return array('wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php',
            'wp-includes/load.php', 'wp-includes/plugin.php', 'wp-includes/functions.php', 'wp-includes/class-wp-hook.php',
            'wp-admin/admin.php', 'wp-admin/includes/plugin.php');
    }

    public static function wporg_info() {
        $value = get_option(self::WPORG, array());
        return is_array($value) ? $value : array();
    }

    public static function checksums() {
        $value = get_option(self::CHECKSUMS, array());
        $value = is_array($value) ? $value : array();
        return array('plugins' => (array) ($value['plugins'] ?? array()), 'core' => (array) ($value['core'] ?? array()));
    }

    /** Lavoro periodico (audit orario o WP-CLI), con un budget di tempo per non appesantire il Master. */
    public static function tick($budget = 12.0) {
        $start = microtime(true);
        $sites = Integra_Sentinel_Master::sites();
        self::refresh_wporg($sites, 24);
        if (microtime(true) - $start < $budget) { self::refresh_plugin_checksums($sites, 4); }
        if (microtime(true) - $start < $budget) { self::refresh_core_checksums($sites, 2); }
        $cfg = self::envato_config();
        if (!empty($cfg['token']) && time() - (int) ($cfg['at'] ?? 0) > DAY_IN_SECONDS && microtime(true) - $start < $budget) { self::envato_refresh(); }
    }

    /* ---------- Richieste in parallelo ---------- */

    /**
     * @param array $requests key => url, timeout, headers, redirects, max_bytes
     * @return array key => code, body, error, ms
     */
    public static function parallel_get($requests) {
        $out = array();
        if (!$requests) { return $out; }
        $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        $batch = array();
        foreach ($requests as $key => $r) {
            $timeout = (float) ($r['timeout'] ?? 8);
            $batch[$key] = array(
                'url' => (string) $r['url'],
                'headers' => (array) ($r['headers'] ?? array()),
                'data' => array(),
                'type' => 'GET',
                'options' => array(
                    'timeout' => $timeout,
                    'connect_timeout' => min(10.0, $timeout),
                    'useragent' => 'IntegraSentinel/' . Integra_Sentinel_Master::VERSION . ' (+' . home_url('/') . ')',
                    'follow_redirects' => !empty($r['redirects']),
                    'redirects' => (int) ($r['redirects'] ?? 0),
                    'verify' => is_readable($ca) ? $ca : true,
                    'max_bytes' => (int) ($r['max_bytes'] ?? 2097152),
                ),
            );
        }
        $start = microtime(true);
        $done = array();
        $responses = array();
        if (class_exists('\WpOrg\Requests\Requests')) {
            try {
                $responses = \WpOrg\Requests\Requests::request_multiple($batch, array(
                    'complete' => function(&$response, $id) use (&$done) { $done[$id] = microtime(true); },
                ));
            } catch (\Throwable $e) {
                $responses = array();
            }
        }
        foreach ($requests as $key => $r) {
            $response = $responses[$key] ?? null;
            $ms = isset($done[$key]) ? (int) round(1000 * ($done[$key] - $start)) : 0;
            if (is_object($response) && isset($response->status_code)) {
                $out[$key] = array('code' => (int) $response->status_code, 'body' => (string) $response->body, 'error' => '', 'ms' => $ms);
            } elseif ($response instanceof \Throwable) {
                $out[$key] = array('code' => 0, 'body' => '', 'error' => substr(sanitize_text_field($response->getMessage()), 0, 180), 'ms' => $ms);
            } else {
                // Ripiego sequenziale (trasporto non disponibile).
                $t0 = microtime(true);
                $single = wp_safe_remote_get($r['url'], array('timeout' => (float) ($r['timeout'] ?? 8), 'redirection' => (int) ($r['redirects'] ?? 0),
                    'headers' => (array) ($r['headers'] ?? array()), 'limit_response_size' => (int) ($r['max_bytes'] ?? 2097152)));
                $out[$key] = is_wp_error($single)
                    ? array('code' => 0, 'body' => '', 'error' => substr($single->get_error_message(), 0, 180), 'ms' => (int) round(1000 * (microtime(true) - $t0)))
                    : array('code' => (int) wp_remote_retrieve_response_code($single), 'body' => (string) wp_remote_retrieve_body($single), 'error' => '', 'ms' => (int) round(1000 * (microtime(true) - $t0)));
            }
        }
        return $out;
    }

    /* ---------- WordPress.org: plugin chiusi, abbandonati, data di rilascio ---------- */

    private static function wporg_targets($sites) {
        $targets = array();
        foreach ((array) $sites as $site) {
            foreach ((array) ($site['report']['plugins'] ?? array()) as $p) {
                $slug = (string) ($p['slug'] ?? '');
                if ($slug === '' || in_array($slug, array('integra-sentinel-client', 'integra-sentinel-master'), true)) { continue; }
                if (in_array((string) ($p['channel']['type'] ?? ''), array('vendor', 'external', 'disabled'), true)) { continue; }
                $update = (string) ($p['update']['version'] ?? '');
                if (!isset($targets[$slug]) || ($update !== '' && version_compare($update, $targets[$slug], '>'))) { $targets[$slug] = $update; }
            }
        }
        return $targets;
    }

    public static function refresh_wporg($sites, $max = 24) {
        $info = self::wporg_info();
        $targets = self::wporg_targets($sites);
        $due = array();
        foreach ($targets as $slug => $update) {
            $e = $info[$slug] ?? null;
            $state = is_array($e) ? (string) ($e['state'] ?? '') : '';
            $ttl = array('ok' => 3 * DAY_IN_SECONDS, 'missing' => 30 * DAY_IN_SECONDS, 'closed' => 7 * DAY_IN_SECONDS)[$state] ?? HOUR_IN_SECONDS;
            $outdated = $state === 'ok' && $update !== '' && $update !== (string) ($e['version'] ?? '');
            if (!$e) { $due[$slug] = 0; }
            elseif ($outdated) { $due[$slug] = 1; }
            elseif (time() - (int) ($e['at'] ?? 0) > $ttl) { $due[$slug] = 2 + (int) ($e['at'] ?? 0); }
        }
        asort($due);
        $batch = array_slice(array_keys($due), 0, max(1, (int) $max));
        $requests = array();
        foreach ($batch as $slug) {
            $fields = array('sections' => 0, 'versions' => 0, 'reviews' => 0, 'banners' => 0, 'icons' => 0, 'contributors' => 0, 'screenshots' => 0,
                'tags' => 0, 'compatibility' => 0, 'ratings' => 0, 'description' => 0, 'short_description' => 0, 'donate_link' => 0, 'downloadlink' => 0,
                'homepage' => 0, 'active_installs' => 1, 'last_updated' => 1, 'added' => 0);
            $requests[$slug] = array('url' => 'https://api.wordpress.org/plugins/info/1.2/?' . http_build_query(array('action' => 'plugin_information',
                'request' => array('slug' => $slug, 'fields' => $fields))), 'timeout' => 8, 'max_bytes' => 262144);
        }
        $delta = array();
        foreach (self::parallel_get($requests) as $slug => $response) { $delta[$slug] = self::parse_wporg($response); }
        return Integra_Sentinel_Master::mutate_option(self::WPORG, function($current) use ($delta, $targets) {
            foreach ($delta as $slug => $entry) { $current[$slug] = $entry; }
            return array_intersect_key($current, $targets);
        });
    }

    /**
     * Changelog ufficiali dei plugin della libreria WordPress.org, letti dal Master e tenuti in archivio 3 giorni.
     * @param array $wanted slug => versione che deve comparire (se manca si rilegge, al massimo ogni 6 ore)
     * @return array slug => (versione => elenco di voci)
     */
    public static function changelogs($wanted) {
        $store = get_option(self::CHANGELOGS, array());
        $store = is_array($store) ? $store : array();
        $info = self::wporg_info();
        $requests = array();
        foreach ((array) $wanted as $slug => $version) {
            if (($info[$slug]['state'] ?? '') !== 'ok') { continue; }
            $e = $store[$slug] ?? null;
            $age = $e ? time() - (int) ($e['at'] ?? 0) : PHP_INT_MAX;
            if ($e && ($age < 3 * DAY_IN_SECONDS && (isset($e['ver'][$version]) || $age < 6 * HOUR_IN_SECONDS))) { continue; }
            if (count($requests) >= 8) { break; }
            $requests[$slug] = array('url' => 'https://api.wordpress.org/plugins/info/1.2/?' . http_build_query(array('action' => 'plugin_information',
                'request' => array('slug' => $slug, 'fields' => array('sections' => 1, 'versions' => 0, 'reviews' => 0, 'banners' => 0, 'icons' => 0,
                'contributors' => 0, 'screenshots' => 0, 'tags' => 0, 'ratings' => 0, 'description' => 0, 'short_description' => 0)))), 'timeout' => 8, 'max_bytes' => 1048576);
        }
        if ($requests) {
            $fresh = array();
            foreach (self::parallel_get($requests) as $slug => $response) {
                $json = (int) ($response['code'] ?? 0) === 200 ? json_decode((string) $response['body'], true) : null;
                $fresh[$slug] = array('at' => time(), 'ver' => is_array($json) ? self::parse_changelog((string) ($json['sections']['changelog'] ?? '')) : array());
            }
            Integra_Sentinel_Master::mutate_option(self::CHANGELOGS, function($current) use ($fresh) {
                foreach ($fresh as $slug => $entry) { $current[$slug] = $entry; }
                return array_slice($current, -400, null, true);
            });
            $store = array_merge($store, $fresh);
        }
        $out = array();
        foreach (array_keys((array) $wanted) as $slug) { if (!empty($store[$slug]['ver'])) { $out[$slug] = $store[$slug]['ver']; } }
        return $out;
    }

    /** HTML del changelog → versione => voci (testo semplice). Le intestazioni senza numero di versione si ignorano. */
    public static function parse_changelog($html) {
        $parts = preg_split('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = array();
        for ($i = 1; $i + 1 < count($parts) && count($out) < 20; $i += 2) {
            if (!preg_match('/(\d+(?:\.\d+){1,3})/', wp_strip_all_tags($parts[$i]), $m) || isset($out[$m[1]])) { continue; }
            $items = array();
            preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $parts[$i + 1], $li);
            $lines = $li[1] ?: preg_split('/<br\s*\/?>|<\/p>|\n/i', $parts[$i + 1]);
            foreach ($lines as $line) {
                $line = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($line), ENT_QUOTES, 'UTF-8')), " \t*-•");
                if ($line === '') { continue; }
                $items[] = function_exists('mb_substr') ? mb_substr($line, 0, 200) : substr($line, 0, 200);
                if (count($items) >= 10) { break; }
            }
            if ($items) { $out[$m[1]] = $items; }
        }
        return $out;
    }

    private static function parse_wporg($response) {
        $code = (int) ($response['code'] ?? 0);
        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (is_array($json) && !empty($json['error'])) {
            $error = strtolower((string) $json['error']);
            if (strpos($error, 'closed') !== false || !empty($json['closed'])) {
                $closed = !empty($json['closed_date']) ? strtotime((string) $json['closed_date']) : false;
                return array('state' => 'closed', 'at' => time(), 'name' => self::text($json['name'] ?? '', 160),
                    'closed_date' => $closed ? gmdate('Y-m-d', $closed) : '', 'reason' => self::text($json['reason_text'] ?? ($json['reason'] ?? ''), 80));
            }
            return array('state' => 'missing', 'at' => time());
        }
        if ($code === 404) { return array('state' => 'missing', 'at' => time()); }
        if ($code !== 200 || !is_array($json) || empty($json['slug'])) {
            return array('state' => 'error', 'at' => time(), 'error' => $code ? 'HTTP ' . $code : self::text($response['error'] ?? 'rete', 120));
        }
        $updated = !empty($json['last_updated']) ? strtotime((string) $json['last_updated']) : false;
        return array('state' => 'ok', 'at' => time(), 'name' => self::text($json['name'] ?? '', 160), 'version' => self::text($json['version'] ?? '', 40),
            'tested' => self::text($json['tested'] ?? '', 20), 'requires_php' => self::text($json['requires_php'] ?? '', 20),
            'last_updated' => $updated ? gmdate('Y-m-d H:i', $updated) : '', 'installs' => (int) ($json['active_installs'] ?? 0));
    }

    private static function text($value, $length) {
        if (!is_scalar($value)) { return ''; }
        $value = sanitize_text_field(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /* ---------- Checksum ufficiali: file principale dei plugin WordPress.org e file del core ---------- */

    public static function refresh_plugin_checksums($sites, $max = 4) {
        $store = self::checksums();
        $needed = array();
        foreach ((array) $sites as $site) {
            foreach ((array) ($site['report']['plugins'] ?? array()) as $p) {
                $file = (string) ($p['file'] ?? '');
                if (($p['channel']['type'] ?? '') !== 'wporg' || empty($p['hash']) || empty($p['version']) || strpos($file, '/') === false) { continue; }
                $key = $p['slug'] . '|' . $p['version'];
                $needed[$key] = array('slug' => (string) $p['slug'], 'version' => (string) $p['version'], 'rel' => substr($file, strpos($file, '/') + 1));
            }
        }
        $due = array();
        foreach ($needed as $key => $n) {
            $e = $store['plugins'][$key] ?? null;
            $state = is_array($e) ? (string) ($e['state'] ?? '') : '';
            $age = is_array($e) ? time() - (int) ($e['at'] ?? 0) : PHP_INT_MAX;
            if (!$e || ($state === 'error' && $age > 6 * HOUR_IN_SECONDS) || ($state === 'missing' && $age > 30 * DAY_IN_SECONDS)
                || ($state === 'ok' && !isset($e['files'][$n['rel']]))) { $due[$key] = $n; }
        }
        $batch = array_slice($due, 0, max(1, (int) $max), true);
        $requests = array();
        foreach ($batch as $key => $n) {
            $requests[$key] = array('url' => 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode($n['slug']) . '/' . rawurlencode($n['version']) . '.json',
                'timeout' => 15, 'max_bytes' => 16777216);
        }
        $delta = array();
        foreach (self::parallel_get($requests) as $key => $response) {
            $n = $batch[$key];
            $code = (int) $response['code'];
            if ($code === 404) { $delta[$key] = array('state' => 'missing', 'at' => time(), 'files' => array()); continue; }
            $json = $code === 200 ? json_decode((string) $response['body'], true) : null;
            if (!is_array($json) || !isset($json['files']) || !is_array($json['files'])) { $delta[$key] = array('state' => 'error', 'at' => time(), 'files' => array()); continue; }
            $entry = $json['files'][$n['rel']] ?? null;
            $hashes = array();
            if (is_array($entry) && isset($entry['sha256'])) {
                foreach ((array) $entry['sha256'] as $h) { if (is_string($h) && preg_match('/^[a-f0-9]{64}$/i', $h)) { $hashes[] = strtolower($h); } }
            }
            $delta[$key] = array('state' => 'ok', 'at' => time(), 'files' => array($n['rel'] => $hashes));
        }
        return Integra_Sentinel_Master::mutate_option(self::CHECKSUMS, function($current) use ($delta, $needed) {
            $plugins = (array) ($current['plugins'] ?? array());
            foreach ($delta as $key => $entry) {
                if (($entry['state'] ?? '') === 'ok' && isset($plugins[$key]['files']) && is_array($plugins[$key]['files'])) {
                    $entry['files'] = array_merge($plugins[$key]['files'], $entry['files']);
                }
                $plugins[$key] = $entry;
            }
            $current['plugins'] = array_intersect_key($plugins, $needed);
            return $current;
        });
    }

    public static function refresh_core_checksums($sites, $max = 2) {
        $store = self::checksums();
        $needed = array();
        foreach ((array) $sites as $site) {
            $version = (string) ($site['report']['wp'] ?? '');
            if (!empty($site['report']['core_md5']) && preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) { $needed[$version] = true; }
        }
        $due = array();
        foreach (array_keys($needed) as $version) {
            $e = $store['core'][$version] ?? null;
            if (!$e || (($e['state'] ?? '') !== 'ok' && time() - (int) ($e['at'] ?? 0) > 6 * HOUR_IN_SECONDS)) { $due[] = $version; }
        }
        $requests = array();
        foreach (array_slice($due, 0, max(1, (int) $max)) as $version) {
            // I file confrontati sono identici in tutte le lingue: si usa sempre en_US per riusare la cache.
            $requests[$version] = array('url' => 'https://api.wordpress.org/core/checksums/1.0/?' . http_build_query(array('version' => $version, 'locale' => 'en_US')),
                'timeout' => 15, 'max_bytes' => 4194304);
        }
        $delta = array();
        foreach (self::parallel_get($requests) as $version => $response) {
            $json = (int) $response['code'] === 200 ? json_decode((string) $response['body'], true) : null;
            $sums = is_array($json) ? ($json['checksums'] ?? null) : null;
            if (is_array($sums) && isset($sums[$version]) && is_array($sums[$version])) { $sums = $sums[$version]; }
            if (!is_array($sums)) { $delta[$version] = array('state' => is_array($json) ? 'missing' : 'error', 'at' => time(), 'files' => array()); continue; }
            $files = array();
            foreach (self::core_files() as $rel) {
                if (isset($sums[$rel]) && is_string($sums[$rel]) && preg_match('/^[a-f0-9]{32}$/i', $sums[$rel])) { $files[$rel] = strtolower($sums[$rel]); }
            }
            $delta[$version] = array('state' => 'ok', 'at' => time(), 'files' => $files);
        }
        return Integra_Sentinel_Master::mutate_option(self::CHECKSUMS, function($current) use ($delta, $needed) {
            $core = (array) ($current['core'] ?? array());
            foreach ($delta as $version => $entry) { $core[$version] = $entry; }
            $current['core'] = array_intersect_key($core, $needed);
            return $current;
        });
    }

    /* ---------- Envato: acquisti dell'agenzia come catalogo automatico delle ultime versioni ---------- */

    public static function envato_config() {
        $cfg = get_option(self::ENVATO, array());
        return is_array($cfg) ? $cfg : array();
    }

    public static function envato_items() {
        $data = get_option(self::ENVATO_ITEMS, array());
        return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    }

    public static function envato_set_token($token) {
        $cfg = self::envato_config();
        if ($token === '') {
            $cfg = array();
            delete_option(self::ENVATO_ITEMS);
        } else {
            $encrypted = Integra_Sentinel_Master::encrypt_secret($token);
            if ($encrypted === '') { return false; }
            $cfg = array('token' => $encrypted, 'at' => 0, 'error' => '', 'count' => 0);
        }
        return update_option(self::ENVATO, $cfg, false);
    }

    public static function envato_refresh() {
        $cfg = self::envato_config();
        $token = !empty($cfg['token']) ? Integra_Sentinel_Master::decrypt_secret($cfg['token']) : '';
        if ($token === '') { return false; }
        $items = array(); $error = '';
        foreach (array('wordpress-plugins' => 'plugin', 'wordpress-themes' => 'theme') as $filter => $kind) {
            $seen = array();
            for ($page = 1; $page <= 20; ++$page) {
                $response = wp_safe_remote_get('https://api.envato.com/v3/market/buyer/list-purchases?' . http_build_query(array('filter_by' => $filter, 'page' => $page)), array(
                    'timeout' => 20, 'redirection' => 0,
                    'headers' => array('Authorization' => 'Bearer ' . $token, 'User-Agent' => 'Integra Sentinel ' . Integra_Sentinel_Master::VERSION),
                    'limit_response_size' => 8388608,
                ));
                if (is_wp_error($response)) { $error = 'Errore di rete: ' . $response->get_error_code(); break 2; }
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code === 401 || $code === 403) { $error = 'Token non valido o senza il permesso “Visualizza e cerca i tuoi acquisti” (HTTP ' . $code . ').'; break 2; }
                if ($code === 429) { $error = 'Limite di richieste Envato raggiunto (HTTP 429): riprova più tardi.'; break 2; }
                if ($code !== 200) { $error = 'Risposta Envato HTTP ' . $code . '.'; break 2; }
                $json = json_decode((string) wp_remote_retrieve_body($response), true);
                $results = is_array($json) && isset($json['results']) && is_array($json['results']) ? $json['results'] : array();
                $new = 0;
                foreach ($results as $row) {
                    $item = is_array($row['item'] ?? null) ? $row['item'] : array();
                    $id = (int) ($item['id'] ?? 0);
                    if (!$id || isset($seen[$id])) { continue; }
                    $seen[$id] = true; ++$new;
                    $meta = (array) ($item[$kind === 'plugin' ? 'wordpress_plugin_metadata' : 'wordpress_theme_metadata'] ?? array());
                    $name = self::text($meta[$kind === 'plugin' ? 'plugin_name' : 'theme_name'] ?? ($item['name'] ?? ''), 160);
                    $version = self::text($meta['version'] ?? '', 40);
                    $supported = !empty($row['supported_until']) ? strtotime((string) $row['supported_until']) : false;
                    $items[$kind . ':' . $id] = array('id' => $id, 'kind' => $kind, 'name' => $name, 'item' => self::text($item['name'] ?? $name, 200),
                        'version' => Integra_Sentinel_Dependencies::valid_version($version) ? $version : '',
                        'url' => esc_url_raw((string) ($item['url'] ?? ''), array('https')),
                        'supported_until' => $supported ? gmdate('Y-m-d', $supported) : '', 'license' => self::text($row['license'] ?? '', 60));
                }
                if (!$new) { break; }
            }
        }
        $cfg['at'] = time();
        $cfg['error'] = $error;
        if ($error === '') {
            $cfg['count'] = count($items);
            update_option(self::ENVATO_ITEMS, array('at' => time(), 'items' => $items), false);
        }
        update_option(self::ENVATO, $cfg, false);
        return $error === '';
    }

    private static function norm($name) {
        $name = strtolower(remove_accents(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
        return (string) preg_replace('/[^a-z0-9]+/', '', $name);
    }

    /**
     * Abbina gli acquisti Envato ai plugin/temi installati nel parco tramite il nome del prodotto.
     * @return array slug => version, url, source, supported_until
     */
    public static function envato_catalog($sites, $kind = 'plugin') {
        $by_name = array();
        foreach (self::envato_items() as $item) {
            if (($item['kind'] ?? '') === $kind && !empty($item['version']) && !empty($item['name'])) { $by_name[self::norm($item['name'])] = $item; }
        }
        if (!$by_name) { return array(); }
        $out = array();
        foreach ((array) $sites as $site) {
            foreach ((array) ($site['report'][$kind === 'plugin' ? 'plugins' : 'themes'] ?? array()) as $p) {
                $slug = (string) ($p['slug'] ?? '');
                $key = self::norm($p['name'] ?? '');
                if ($slug === '' || $key === '' || isset($out[$slug]) || !isset($by_name[$key])) { continue; }
                $item = $by_name[$key];
                $out[$slug] = array('version' => (string) $item['version'], 'url' => (string) $item['url'], 'source' => 'acquisti Envato',
                    'supported_until' => (string) ($item['supported_until'] ?? ''), 'envato_id' => (int) $item['id']);
            }
        }
        return $out;
    }
}
