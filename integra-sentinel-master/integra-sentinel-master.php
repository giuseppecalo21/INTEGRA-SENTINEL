<?php
/**
 * Plugin Name: Integra Sentinel Master
 * Plugin URI: https://integracorporate.it/
 * Description: Centrale privata in sola lettura per aggiornamenti, colli di bottiglia, licenze, dipendenze, vulnerabilita CVE e integrita anti-intrusione dei siti WordPress gestiti da Integra.
 * Version: 1.8.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Integra
 * Author URI: https://integracorporate.it/
 * Text Domain: integra-sentinel-master
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/includes/class-integra-sentinel-dependencies.php';
require_once __DIR__ . '/includes/class-integra-sentinel-settings.php';
require_once __DIR__ . '/includes/class-integra-sentinel-store.php';
require_once __DIR__ . '/includes/class-integra-sentinel-eol.php';
require_once __DIR__ . '/includes/class-integra-sentinel-environment.php';
require_once __DIR__ . '/includes/class-integra-sentinel-themes.php';
require_once __DIR__ . '/includes/class-integra-sentinel-intel.php';
require_once __DIR__ . '/includes/class-integra-sentinel-licenses.php';
require_once __DIR__ . '/includes/class-integra-sentinel-analysis.php';
require_once __DIR__ . '/includes/class-integra-sentinel-fleet.php';
require_once __DIR__ . '/includes/class-integra-sentinel-notify.php';
require_once __DIR__ . '/includes/class-integra-sentinel-probe.php';
require_once __DIR__ . '/includes/class-integra-sentinel-jobs.php';
require_once __DIR__ . '/includes/class-integra-sentinel-reports.php';
require_once __DIR__ . '/includes/class-integra-sentinel-admin.php';
require_once __DIR__ . '/includes/class-integra-sentinel-admin-pages.php';
require_once __DIR__ . '/includes/class-integra-sentinel-cli.php';

final class Integra_Sentinel_Master {
    const VERSION = '1.8.0';
    const SITES = 'integra_sentinel_master_sites';
    const FEEDS = 'integra_sentinel_master_feeds';
    const MAIL = 'integra_sentinel_master_email';
    const HOOK = 'integra_sentinel_master_audit';
    const WPSCAN_CONFIG = 'integra_sentinel_wpscan_config';
    const WPSCAN_FEEDS  = 'integra_sentinel_wpscan_feeds';
    const WPSCAN_USAGE  = 'integra_sentinel_wpscan_usage';
    const WPSCAN_LOCK   = 'integra_sentinel_wpscan_lock';
    const WPSCAN_LIMIT = 25; // Tetto prudenziale globale per tutte le chiamate.
    const CACHE_TTL = 21600; // 6 ore.
    const HOOK_DIGEST = 'integra_sentinel_master_daily_digest';
    const HOOK_ALERTS = 'integra_sentinel_master_mail_alerts';
    const HOOK_RETRY = 'integra_sentinel_master_daily_retry';
    const MAIL_SETTINGS = 'integra_sentinel_master_mail_settings';
    const MAIL_STATUS = 'integra_sentinel_master_mail_status';
    const HOOK_CLEANUP = 'integra_sentinel_master_nonce_cleanup';
    const MANUAL_DEPS = 'integra_sentinel_manual_deps';
    const HOOK_PROBE = 'integra_sentinel_master_probe';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'rest'));
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_integra_sentinel_master_action', array(__CLASS__, 'post_action'));
        add_action('admin_post_integra_sentinel_master_preview', array(__CLASS__, 'preview_digest'));
        add_action(self::HOOK, array(__CLASS__, 'audit'));
        add_action('wp_ajax_integra_sentinel_scan_site', array(__CLASS__, 'ajax_scan_site'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_init', array(__CLASS__, 'ensure_digest_schedule'));
        add_action('admin_init', array(__CLASS__, 'ensure_cleanup_schedule'));
        add_action('admin_init', array(__CLASS__, 'ensure_probe_schedule'));
        add_action('admin_init', array('Integra_Sentinel_Store', 'maybe_upgrade'));
        add_action(self::HOOK_CLEANUP, array(__CLASS__, 'clean_nonces'));
        add_action(self::HOOK_DIGEST, array(__CLASS__, 'send_daily_digest'));
        add_action(self::HOOK_RETRY, array(__CLASS__, 'send_daily_digest'));
        add_action(self::HOOK_ALERTS, array(__CLASS__, 'dispatch_alerts'));
        add_action(self::HOOK_PROBE, array(__CLASS__, 'run_probes'));
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_filter('user_has_cap', array('Integra_Sentinel_Settings', 'grant_caps'), 10, 4);
        Integra_Sentinel_Admin::init();
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) { Integra_Sentinel_CLI::register(); }
    }

    public static function activate() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
        self::ensure_digest_schedule();
        self::ensure_cleanup_schedule();
        self::ensure_probe_schedule();
        Integra_Sentinel_Store::install();
        if (false === get_option(self::MAIL, false)) {
            add_option(self::MAIL, get_option('admin_email'), '', false);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::HOOK_DIGEST);
        wp_clear_scheduled_hook(self::HOOK_ALERTS);
        wp_clear_scheduled_hook(self::HOOK_RETRY);
        wp_clear_scheduled_hook(self::HOOK_CLEANUP);
        wp_clear_scheduled_hook(self::HOOK_PROBE);
        wp_clear_scheduled_hook('integra_sentinel_master_audit_soon');
    }

    public static function cron_schedules($schedules) {
        $schedules['integra_sentinel_5min'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Integra Sentinel · ogni 5 minuti');
        return $schedules;
    }

    public static function ensure_probe_schedule() {
        if (!wp_next_scheduled(self::HOOK_PROBE)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'integra_sentinel_5min', self::HOOK_PROBE);
        }
    }

    /** Sonde di raggiungibilita' (sola lettura): il cron gira ogni 5 minuti, ogni sito si controlla secondo le politiche. */
    public static function run_probes() {
        Integra_Sentinel_Store::maybe_upgrade();
        Integra_Sentinel_Probe::run();
    }

    /** WP time zone, inclusive of daylight-saving transitions. WP-Cron requires a server trigger for punctuality. */
    public static function next_digest_timestamp() {
        $now = new DateTimeImmutable('now', wp_timezone());
        $next = $now->setTime(8, 30, 0);
        if ($next->getTimestamp() <= time()) { $next = $next->modify('+1 day'); }
        return $next->getTimestamp();
    }

    public static function ensure_digest_schedule() {
        if (!self::acquire_lock('digest_schedule', 0)) { return; }
        try {
            if (!wp_next_scheduled(self::HOOK_DIGEST)) {
                wp_schedule_single_event(self::next_digest_timestamp(), self::HOOK_DIGEST);
            }
        } finally { self::release_lock('digest_schedule'); }
    }

    public static function ensure_cleanup_schedule() {
        if (!wp_next_scheduled(self::HOOK_CLEANUP)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_CLEANUP);
        }
    }

    public static function preview_digest() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.', '', array('response' => 403)); }
        check_admin_referer('integra_sentinel_master_preview');
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo self::digest_html(true);
        exit;
    }

    public static function mail_config() {
        return wp_parse_args((array) get_option(self::MAIL_SETTINGS, array()), array('daily' => true, 'alerts' => true));
    }

    public static function recipients() {
        $value = (string) get_option(self::MAIL, get_option('admin_email'));
        $raw = preg_split('/[;,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $emails = array();
        foreach (array_slice((array) $raw, 0, 12) as $candidate) {
            $address = sanitize_email($candidate);
            if (is_email($address)) { $emails[strtolower($address)] = $address; }
        }
        return array_values($emails);
    }

    public static function sites() {
        $sites = get_option(self::SITES, array());
        return is_array($sites) ? $sites : array();
    }

    /**
     * MySQL/MariaDB named locks are connection-scoped and atomic across PHP workers.
     * An unsupported or unavailable lock FAILS CLOSED (never writes without locking).
     */
    private static function lock_name($purpose) {
        global $wpdb;
        return 'sn_' . substr(hash('sha256', $wpdb->options . ':' . $purpose), 0, 49);
    }

    public static function acquire_lock($purpose, $wait = 5) {
        global $wpdb;
        $name = self::lock_name($purpose);
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, (int) $wait)) === '1';
    }

    public static function release_lock($purpose) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($purpose)));
    }

    /** Read from SQL after the lock: WP's local/persistent object cache can be stale. */
    public static function fresh_option($name, $default = array()) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        return $raw === null ? $default : maybe_unserialize($raw);
    }

    private static function persist_locked_option($name, $value) {
        global $wpdb;
        $serialized = maybe_serialize($value);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        if ($exists === null) {
            $ok = $wpdb->insert($wpdb->options, array('option_name' => $name, 'option_value' => $serialized, 'autoload' => 'no')) !== false;
        } else {
            $ok = $wpdb->update($wpdb->options, array('option_value' => $serialized), array('option_name' => $name)) !== false;
        }
        if ($ok) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        return $ok;
    }

    /** Short critical section; NEVER perform remote HTTP or send email inside it. */
    public static function mutate_option($name, $callback) {
        if (!self::acquire_lock($name, 7)) { error_log('Integra Sentinel: DB lock unavailable for ' . $name); return false; }
        try {
            $current = self::fresh_option($name, array());
            if (!is_array($current)) { $current = array(); }
            $new = $callback($current);
            if ($new === null) { return true; }
            if (!is_array($new)) { return false; }
            return self::persist_locked_option($name, $new);
        } finally { self::release_lock($name); }
    }

    public static function mutate_sites($callback) { return self::mutate_option(self::SITES, $callback); }

    /** Merge only the fetched feed entries; never overwrite fresh results from another scan. */
    private static function store_feed_delta($option, $delta) {
        if (!$delta) { return true; }
        return self::mutate_option($option, function($current) use ($delta) {
            foreach ($delta as $key => $entry) {
                if (empty($current[$key]['at']) || (int) $current[$key]['at'] <= (int) ($entry['at'] ?? 0)) {
                    $current[$key] = $entry;
                }
            }
            return $current;
        });
    }

    private static function encryption_key() { return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true); }

    public static function encrypt_secret($secret) {
        if (!function_exists('openssl_encrypt')) { return ''; }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return false === $cipher ? '' : base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt_secret($encrypted) {
        if (!function_exists('openssl_decrypt')) { return ''; }
        $raw = base64_decode((string) $encrypted, true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    public static function url($url) {
        $url = untrailingslashit(esc_url_raw(trim((string) $url)));
        $parts = wp_parse_url($url);
        return $parts && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']) && empty($parts['query']) && empty($parts['fragment']) ? $url : '';
    }

    /** Verifica la scadenza del certificato SSL del sito cliente (ritorna i giorni rimanenti o null). */
    private static function probe_ssl_days($url) {
        if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) { return null; }
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) { return null; }
        $ctx = stream_context_create(array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ),
        ));
        $errno = 0; $errstr = '';
        $client = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) { return null; }
        $params = stream_context_get_params($client);
        @fclose($client);
        if (empty($params['options']['ssl']['peer_certificate'])) { return null; }
        $cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!is_array($cert) || empty($cert['validTo_time_t'])) { return null; }
        return (int) floor(((int) $cert['validTo_time_t'] - time()) / DAY_IN_SECONDS);
    }

    public static function menu() {
        add_menu_page('Integra Sentinel', 'Integra Sentinel', Integra_Sentinel_Settings::CAP_VIEW, 'integra-sentinel', array(__CLASS__, 'page'), 'dashicons-shield-alt', 3);
    }

    private static function once($id, $secret, $url) {
        set_transient('integra_sentinel_master_once_' . get_current_user_id(), array('id' => $id, 'secret' => $secret, 'url' => $url), 10 * MINUTE_IN_SECONDS);
    }

    /** Torna alla pagina da cui e' partito il comando (Impostazioni, scheda sito) oppure alla panoramica. */
    private static function redirect($notice = '') {
        $page = isset($_POST['_sn_return']) ? sanitize_key(wp_unslash($_POST['_sn_return'])) : 'integra-sentinel';
        if (!isset(Integra_Sentinel_Admin::pages()[$page])) { $page = 'integra-sentinel'; }
        $args = array('sentinel_msg' => $notice);
        if (!empty($_POST['_sn_site'])) { $args['site'] = sanitize_text_field(wp_unslash($_POST['_sn_site'])); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . $page)));
        exit;
    }

    public static function post_action() {
        if (!Integra_Sentinel_Settings::can_manage()) { wp_die('Operazione non autorizzata.', '', array('response' => 403)); }
        check_admin_referer('integra_sentinel_master_action');
        $cmd = isset($_POST['cmd']) ? sanitize_key(wp_unslash($_POST['cmd'])) : '';
        $id = isset($_POST['site_id']) ? sanitize_text_field(wp_unslash($_POST['site_id'])) : '';
        // Siti, chiavi e token restano riservati agli amministratori anche quando la gestione e' delegata ad altri ruoli.
        if (in_array($cmd, array('add', 'delete', 'rotate', 'revoke', 'wpscan'), true) && !current_user_can('manage_options')) { self::redirect('denied'); }
        if ($cmd === 'add') {
            $name = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';
            $url = self::url(isset($_POST['site_url']) ? wp_unslash($_POST['site_url']) : '');
            if (!$name || !$url) { self::redirect('invalid'); }
            $new_id = wp_generate_uuid4();
            $secret = bin2hex(random_bytes(32));
            $encrypted = self::encrypt_secret($secret);
            if (!$encrypted) { self::redirect('crypto'); }
            $result = 'busy';
            $ok = self::mutate_sites(function($sites) use ($url, $name, $new_id, $encrypted, &$result) {
                foreach ($sites as $site) {
                    if (($site['url'] ?? '') === $url) { $result = 'duplicate'; return null; }
                }
                $sites[$new_id] = array(
                    'id' => $new_id, 'label' => $name, 'url' => $url, 'secret' => $encrypted,
                    'report' => array(), 'last_seen' => 0, 'findings' => array(), 'alert_ids' => array(),
                    'integrity_findings' => array(), 'integrity_alert_ids' => array(), 'integrity_pending_ids' => array(),
                    'offline_alerted' => false, 'feed_pending' => true, 'feed_review' => 0,
                );
                $result = 'added'; return $sites;
            });
            if (!$ok) { self::redirect('busy'); }
            if ($result === 'added') {
                self::once($new_id, $secret, $url);
                Integra_Sentinel_Store::log('site_add', $name . ' · ' . $url, $new_id);
                $_POST['_sn_site'] = $new_id;
            }
            self::redirect($result);
        }
        if ($cmd === 'delete' || $cmd === 'rotate' || $cmd === 'revoke') {
            $secret = $cmd !== 'delete' ? bin2hex(random_bytes(32)) : '';
            $encrypted = $cmd !== 'delete' ? self::encrypt_secret($secret) : '';
            if ($cmd !== 'delete' && !$encrypted) { self::redirect('crypto'); }
            // Rigenerazione: la chiave precedente resta valida per qualche giorno, cosi' il Client non resta scollegato.
            // Revoca: la chiave precedente smette subito di funzionare (chiave compromessa).
            $grace = $cmd === 'rotate' ? max(0, (int) (Integra_Sentinel_Settings::get('policies')['rotation_grace_days'] ?? 7)) : 0;
            $result = 'invalid'; $url = ''; $label = '';
            $ok = self::mutate_sites(function($sites) use ($cmd, $id, $encrypted, $grace, &$result, &$url, &$label) {
                if (!isset($sites[$id])) { return null; }
                $label = (string) ($sites[$id]['label'] ?? '');
                if ($cmd === 'delete') {
                    unset($sites[$id]); $result = 'removed';
                } else {
                    $url = $sites[$id]['url'];
                    if ($grace > 0) {
                        $sites[$id]['secret_prev'] = $sites[$id]['secret'];
                        $sites[$id]['secret_prev_until'] = time() + $grace * DAY_IN_SECONDS;
                    } else {
                        unset($sites[$id]['secret_prev'], $sites[$id]['secret_prev_until']);
                    }
                    unset($sites[$id]['uses_prev_secret']);
                    $sites[$id]['secret'] = $encrypted;
                    $result = $cmd === 'revoke' ? 'revoked' : 'rotated';
                }
                return $sites;
            });
            if (!$ok) { self::redirect('busy'); }
            if ($result === 'rotated' || $result === 'revoked') { self::once($id, $secret, $url); $_POST['_sn_site'] = $id; }
            if ($result === 'removed') { Integra_Sentinel_Store::forget_site($id); }
            if ($result !== 'invalid') { Integra_Sentinel_Store::log('site_' . $cmd, $label, $id); }
            self::redirect($result);
        }
        if ($cmd === 'wpscan') {
            $enabled = !empty($_POST['wpscan_enabled']);
            $licensed = !empty($_POST['wpscan_licensed']);
            $prior = (array) get_option(self::WPSCAN_CONFIG, array());
            $new_token = isset($_POST['wpscan_token']) ? trim((string) wp_unslash($_POST['wpscan_token'])) : '';
            $encrypted_token = $prior['token'] ?? '';
            if (!empty($_POST['wpscan_remove_token'])) { $encrypted_token = ''; }
            elseif ($new_token !== '') {
                if (strlen($new_token) < 15 || strlen($new_token) > 256 || preg_match('/\s/', $new_token)) { self::redirect('token'); }
                $encrypted_token = self::encrypt_secret($new_token);
                if (!$encrypted_token) { self::redirect('crypto'); }
            }
            if ($enabled && (!$licensed || !$encrypted_token)) { self::redirect('license'); }
            update_option(self::WPSCAN_CONFIG, array('enabled' => $enabled, 'licensed' => $licensed, 'token' => $encrypted_token), false);
            Integra_Sentinel_Store::log('wpscan', $enabled ? 'abilitato' : 'disabilitato');
            self::redirect('wpscan');
        }
        if ($cmd === 'email') {
            $raw = isset($_POST['notification_email']) ? (string) wp_unslash($_POST['notification_email']) : '';
            $parts = preg_split('/[;,\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
            if (!$parts || count($parts) > 12) { self::redirect('invalid'); }
            $emails = array();
            foreach ($parts as $part) {
                $address = sanitize_email($part);
                if (!is_email($address)) { self::redirect('invalid'); }
                $emails[strtolower($address)] = $address;
            }
            update_option(self::MAIL, implode(', ', $emails), false);
            update_option(self::MAIL_SETTINGS, array('daily' => !empty($_POST['daily_enabled']), 'alerts' => !empty($_POST['alerts_enabled'])), false);
            self::ensure_digest_schedule();
            Integra_Sentinel_Store::log('email', count($emails) . ' destinatari');
            self::redirect('email');
        }
        if ($cmd === 'test_digest') {
            $sent = self::send_report_email(true);
            self::redirect($sent ? 'test_sent' : 'test_failed');
        }
        if ($cmd === 'refresh') {
            if (!self::mutate_option(self::FEEDS, function($cache) { return array(); })) { self::redirect('busy'); }
            if (!wp_next_scheduled('integra_sentinel_master_audit_soon')) {
                wp_schedule_single_event(time() + 5, 'integra_sentinel_master_audit_soon');
            }
            self::redirect('scan');
        }
        if ($cmd === 'deps') {
            $rules = isset($_POST['dep_rules']) ? (string) wp_unslash($_POST['dep_rules']) : '';
            $catalog = isset($_POST['dep_catalog']) ? (string) wp_unslash($_POST['dep_catalog']) : '';
            update_option(self::MANUAL_DEPS, self::parse_dependency_settings($rules, $catalog), false);
            Integra_Sentinel_Store::log('deps', 'regole e catalogo premium salvati');
            self::redirect('deps');
        }
        self::redirect('invalid');
    }

    /** Scheda cliente salvata sul Master (mai inviata al Client). */
    public static function save_site_meta($id, $input) {
        $field = function($key, $length) use ($input) {
            return isset($input[$key]) ? substr(sanitize_text_field(wp_unslash($input[$key])), 0, $length) : '';
        };
        $tags = array();
        foreach (preg_split('/[,;]+/', $field('tags', 300), -1, PREG_SPLIT_NO_EMPTY) as $tag) {
            $tag = sanitize_title($tag);
            if ($tag !== '') { $tags[$tag] = true; }
        }
        $email = sanitize_email($field('contact_email', 120));
        $pages = array();
        $urls = isset($input['page_url']) ? (array) wp_unslash($input['page_url']) : array();
        $labels = isset($input['page_label']) ? (array) wp_unslash($input['page_label']) : array();
        $expects = isset($input['page_expect']) ? (array) wp_unslash($input['page_expect']) : array();
        $sites = self::sites();
        $host = isset($sites[$id]) ? strtolower((string) wp_parse_url($sites[$id]['url'], PHP_URL_HOST)) : '';
        foreach (array_slice($urls, 0, 3, true) as $i => $raw) {
            $url = esc_url_raw(trim((string) $raw), array('https'));
            if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) { continue; }
            $pages[] = array('url' => substr($url, 0, 250), 'label' => substr(sanitize_text_field((string) ($labels[$i] ?? '')), 0, 40), 'expect' => substr(sanitize_text_field((string) ($expects[$i] ?? '')), 0, 120));
        }
        $meta = array(
            'contact_name' => $field('contact_name', 80),
            'contact_email' => is_email($email) ? $email : '',
            'ring' => max(0, min(3, (int) ($input['ring'] ?? 2))),
            'tags' => array_slice(array_keys($tags), 0, 12),
            'contract' => $field('contract', 60),
            'hosting' => $field('hosting', 80),
            'panel_url' => self::safe_link(isset($input['panel_url']) ? (string) wp_unslash($input['panel_url']) : ''),
            'window' => $field('window', 80),
            'hosting_backup' => !empty($input['hosting_backup']),
            'probe_off' => !empty($input['probe_off']),
            'home_expect' => $field('home_expect', 120),
            'pages' => $pages,
            'notes' => isset($input['notes']) ? substr(sanitize_textarea_field(wp_unslash($input['notes'])), 0, 2000) : '',
        );
        return self::mutate_sites(function($current) use ($id, $meta) {
            if (!isset($current[$id])) { return null; }
            $current[$id]['meta'] = $meta;
            return $current;
        });
    }

    /** Codice di abbinamento monouso (12 caratteri, 30 minuti): sul Master se ne conserva solo l'impronta. */
    public static function pair_code($id) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; ++$i) { $code .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $hash = hash_hmac('sha256', $code, wp_salt('auth'));
        $ok = self::mutate_sites(function($current) use ($id, $hash) {
            if (!isset($current[$id])) { return null; }
            $current[$id]['pair'] = array('hash' => $hash, 'until' => time() + 30 * MINUTE_IN_SECONDS);
            return $current;
        });
        return $ok ? $code : '';
    }

    /**
     * Il Client chiede le proprie credenziali con il codice: il Master le consegna una sola volta, in HTTPS,
     * solo se il codice e' valido e l'URL dichiarato coincide con quello registrato. Il Master non scrive nulla sul sito.
     */
    public static function pair_receive($request) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $bucket = 'integra_sentinel_pair_rl_' . md5($ip);
        $tries = (int) get_transient($bucket);
        if ($tries >= 10) { return new WP_Error('integra_sentinel_pair_rate', 'Troppi tentativi: riprova più tardi.', array('status' => 429)); }
        set_transient($bucket, $tries + 1, 15 * MINUTE_IN_SECONDS);
        $data = json_decode((string) $request->get_body(), true);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (is_array($data) ? ($data['code'] ?? '') : '')));
        $url = self::url(is_array($data) ? (string) ($data['url'] ?? '') : '');
        if (!preg_match('/^[A-Z0-9]{12}$/', $code) || !$url) { return new WP_Error('integra_sentinel_pair_invalid', 'Codice o indirizzo non validi.', array('status' => 400)); }
        $hash = hash_hmac('sha256', $code, wp_salt('auth'));
        $found = ''; $mismatch = false;
        $ok = self::mutate_sites(function($current) use ($hash, $url, &$found, &$mismatch) {
            foreach ($current as $id => $site) {
                if (empty($site['pair']['hash']) || (int) ($site['pair']['until'] ?? 0) < time() || !hash_equals((string) $site['pair']['hash'], $hash)) { continue; }
                if (($site['url'] ?? '') !== $url) { $mismatch = true; return null; }
                unset($current[$id]['pair']);
                $found = (string) $id;
                return $current;
            }
            return null;
        });
        if (!$ok) { return new WP_Error('integra_sentinel_pair_busy', 'Master occupato: riprova.', array('status' => 503)); }
        if ($found === '') {
            return new WP_Error($mismatch ? 'integra_sentinel_pair_url' : 'integra_sentinel_pair_code', $mismatch ? 'Il codice è stato generato per un altro indirizzo.' : 'Codice scaduto, già usato o inesistente.', array('status' => 403));
        }
        $sites = self::sites();
        $secret = self::decrypt_secret($sites[$found]['secret'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) { return new WP_Error('integra_sentinel_pair_key', 'Chiave del sito non leggibile: rigenerala nel Master.', array('status' => 500)); }
        Integra_Sentinel_Store::log('pair', 'sito collegato con codice da ' . $ip, $found);
        delete_transient($bucket);
        return new WP_REST_Response(array('site_id' => $found, 'secret' => $secret), 200);
    }

    /** "mario <mario.rossi@cliente.it>" → "mario <m•••@cliente.it> #a1b2c3d4": riconoscibile, non in chiaro, confrontabile. */
    public static function mask_admin($entry) {
        $entry = (string) $entry;
        if (!preg_match('/^(.*?)\s*<([^<>@\s]+)@([^<>\s]+)>(\s*#[a-f0-9]{8})?$/u', $entry, $m)) { return $entry; }
        if (strpos($m[2], '•') !== false) { return $entry; }
        $tag = substr(hash_hmac('sha256', strtolower($m[2] . '@' . $m[3]), wp_salt('auth')), 0, 8);
        $first = function_exists('mb_substr') ? mb_substr($m[2], 0, 1) : substr($m[2], 0, 1);
        return trim($m[1]) . ' <' . $first . '•••@' . $m[3] . '> #' . $tag;
    }

    /** Maschera tutte le email in un testo (storico importato dalla 1.7). */
    public static function mask_emails($text) {
        return (string) preg_replace_callback('/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function($m) { return $m[1] . '•••@' . $m[2]; }, (string) $text);
    }

    /**
     * Regole manuali "addon > plugin-principale" e catalogo premium "slug | versione | URL fornitore".
     * Righe non valide vengono scartate; limiti fissi per evitare opzioni gonfiate.
     */
    private static function parse_dependency_settings($rules_raw, $catalog_raw) {
        $rules = array(); $catalog = array();
        foreach (array_slice(preg_split('/\r\n|\r|\n/', (string) $rules_raw), 0, 300) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            if (!preg_match('/^([A-Za-z0-9_.-]{1,100})\s*(?:>|->|→)\s*([A-Za-z0-9_.-]{1,100})$/u', $line, $m)) { continue; }
            $from = sanitize_title($m[1]); $to = sanitize_title($m[2]);
            if ($from && $to && $from !== $to) { $rules[$from . '>' . $to] = array($from, $to); }
        }
        foreach (array_slice(preg_split('/\r\n|\r|\n/', (string) $catalog_raw), 0, 300) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = array_map('trim', explode('|', $line));
            $slug = sanitize_title($parts[0] ?? '');
            $version = (string) ($parts[1] ?? '');
            if (!$slug || !Integra_Sentinel_Dependencies::valid_version($version)) { continue; }
            $catalog[$slug] = array('version' => self::field($version, 40), 'url' => self::safe_link($parts[2] ?? ''));
        }
        return array('rules' => array_values($rules), 'catalog' => $catalog);
    }

    public static function dependency_settings() {
        $cfg = get_option(self::MANUAL_DEPS, array());
        $cfg = is_array($cfg) ? $cfg : array();
        return array('rules' => (array) ($cfg['rules'] ?? array()), 'catalog' => (array) ($cfg['catalog'] ?? array()));
    }

    public static function rest() {
        register_rest_route('integra-sentinel/v1', '/report', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receive'),
            'permission_callback' => '__return_true', // Autenticazione HMAC nel callback, senza utente WP.
        ));
        register_rest_route('integra-sentinel/v1', '/pair', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'pair_receive'),
            'permission_callback' => '__return_true', // Codice monouso legato all'URL, con limite di tentativi.
        ));
    }

    private static function fail($reason, $status = 401) {
        return new WP_Error('integra_sentinel_' . $reason, 'Report non accettato.', array('status' => $status));
    }

    /** Costruisce l'elenco di anomalie d'integrità e intrusione zero-noise per il sito. */
    private static function build_integrity_findings($security, $rogue_admins = array()) {
        if (!is_array($security)) { return array(); }
        $findings = array();
        foreach ((array) ($security['uploads_php'] ?? array()) as $item) {
            $findings[] = array(
                'id'       => 'int:up:' . sha1((string) $item),
                'category' => 'File eseguibile in uploads',
                'detail'   => (string) $item,
                'severity' => 'critical',
            );
        }
        foreach ((array) ($security['db_injections'] ?? array()) as $item) {
            $findings[] = array(
                'id'       => 'int:db:' . sha1((string) $item),
                'category' => 'Iniezione codice nel database',
                'detail'   => (string) $item,
                'severity' => 'critical',
            );
        }
        foreach ((array) ($security['root_unexpected'] ?? array()) as $item) {
            $findings[] = array(
                'id'       => 'int:rt:' . sha1((string) $item),
                'category' => 'File PHP estraneo nella root',
                'detail'   => (string) $item,
                'severity' => 'high',
            );
        }
        foreach ((array) ($security['modified_critical'] ?? array()) as $item) {
            $findings[] = array(
                'id'       => 'int:md:' . sha1((string) $item),
                'category' => 'File critico modificato fuori dagli update',
                'detail'   => (string) $item,
                'severity' => 'high',
            );
        }
        foreach ((array) $rogue_admins as $admin_entry) {
            $findings[] = array(
                'id'       => 'int:adm:' . sha1((string) $admin_entry),
                'category' => 'Nuovo utente Amministratore rilevato',
                'detail'   => (string) $admin_entry,
                'severity' => 'high',
            );
        }
        return array_slice($findings, 0, 60);
    }

    /** File del core diversi dai checksum ufficiali WordPress.org (scaricati dal Master): anomalia di integrita' verificata. */
    private static function core_integrity_findings($report) {
        $core = Integra_Sentinel_Analysis::core_integrity((array) $report);
        $out = array();
        foreach ($core['mismatch'] as $file) {
            $out[] = array('id' => 'int:core:' . sha1($file), 'category' => 'File del core diverso dall’originale WordPress.org', 'detail' => $file, 'severity' => 'critical');
        }
        return $out;
    }

    public static function receive($request) {
        $body = $request->get_body();
        if (!is_string($body) || strlen($body) > 512000 || strlen($body) < 10) { return self::fail('size', 413); }
        $id = (string) $request->get_header('x-integra-site');
        $stamp = (string) $request->get_header('x-integra-timestamp');
        $nonce = (string) $request->get_header('x-integra-nonce');
        $signature = (string) $request->get_header('x-integra-signature');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id) || !preg_match('/^[0-9]{10,11}$/', $stamp) || !preg_match('/^[a-f0-9]{32}$/i', $nonce) || !preg_match('/^[a-f0-9]{64}$/i', $signature) || abs(time() - (int) $stamp) > 300) {
            return self::fail('signature');
        }
        Integra_Sentinel_Store::maybe_upgrade();
        $sites = self::sites();
        if (!isset($sites[$id])) { return self::fail('unknown'); }
        $secret = self::decrypt_secret($sites[$id]['secret']);
        if (!$secret) { return self::fail('key'); }
        $message = "POST\nintegra-sentinel/v1/report\n{$stamp}\n{$nonce}\n" . hash('sha256', $body);
        $correct = hash_hmac('sha256', $message, $secret);
        $used_prev = false;
        if (!hash_equals($correct, strtolower($signature))) {
            // Dopo una rigenerazione la chiave precedente resta valida per i giorni di tolleranza configurati.
            $prev = (!empty($sites[$id]['secret_prev']) && (int) ($sites[$id]['secret_prev_until'] ?? 0) > time()) ? self::decrypt_secret($sites[$id]['secret_prev']) : '';
            if (!$prev || !hash_equals(hash_hmac('sha256', $message, $prev), strtolower($signature))) { return self::fail('signature'); }
            $used_prev = true;
        }

        // Rate-limiting per sito PRIMA di scrivere il nonce su wp_options e prima del lock DB (anti-DoS).
        if (!empty($sites[$id]['last_seen']) && (time() - (int) $sites[$id]['last_seen']) < 45) {
            return self::fail('rate_limit', 429);
        }

        // add_option esegue un inserimento unico a livello DB: due richieste con lo stesso nonce non passano.
        $nonce_key = 'integra_sentinel_nonce_' . hash('sha256', $id . ':' . $nonce);
        if (!add_option($nonce_key, time(), '', false)) { return self::fail('replay'); }

        $report = json_decode($body, true);
        if (!is_array($report) || !isset($report['url'], $report['plugins'], $report['themes']) || self::url($report['url']) !== $sites[$id]['url'] || !is_array($report['plugins']) || !is_array($report['themes']) || count($report['plugins']) > 700 || count($report['themes']) > 100) {
            return self::fail('payload', 400);
        }

        // Sanificazione rigorosa della telemetria di sicurezza (v1.5.0+, opzionale per retrocompatibilità Client v1.1.0).
        $raw_sec = isset($report['security']) && is_array($report['security']) ? $report['security'] : array();
        $clean_list = function($arr, $max_items = 25, $max_len = 220) {
            $out = array();
            foreach (array_slice((array) $arr, 0, $max_items) as $v) {
                $s = self::field($v, $max_len);
                if ($s !== '') { $out[] = $s; }
            }
            return $out;
        };
        $safe_security = array(
            'uploads_php'       => $clean_list($raw_sec['uploads_php'] ?? array(), 25, 220),
            'root_unexpected'   => $clean_list($raw_sec['root_unexpected'] ?? array(), 20, 140),
            'modified_critical' => $clean_list($raw_sec['modified_critical'] ?? array(), 30, 180),
            'db_injections'     => $clean_list($raw_sec['db_injections'] ?? array(), 15, 220),
            // Il Client invia solo i nomi utente; eventuali email (Client modificati) vengono comunque mascherate, restando confrontabili.
            'admins'            => array_map(array(__CLASS__, 'mask_admin'), $clean_list($raw_sec['admins'] ?? array(), 50, 160)),
            'blog_public'       => isset($raw_sec['blog_public']) ? (bool) $raw_sec['blog_public'] : true,
            'file_edit_allowed' => isset($raw_sec['file_edit_allowed']) ? (bool) $raw_sec['file_edit_allowed'] : false,
            'debug_log_kb'      => absint($raw_sec['debug_log_kb'] ?? 0),
            'last_fatal'        => self::field($raw_sec['last_fatal'] ?? '', 240),
            'baseline_at'       => absint($raw_sec['baseline_at'] ?? 0),
        );

        $safe_mu = array();
        foreach (array_slice((array) ($report['mu_plugins'] ?? array()), 0, 60) as $mu) {
            if (!is_array($mu)) { continue; }
            $mu_file = self::field($mu['file'] ?? '', 150);
            if (!$mu_file) { continue; }
            $safe_mu[] = array(
                'file'    => $mu_file,
                'name'    => self::field($mu['name'] ?? $mu_file, 160),
                'version' => self::field($mu['version'] ?? '', 45),
            );
        }

        $safe = array(
            'url' => $sites[$id]['url'],
            'name' => self::field($report['name'] ?? '', 150),
            'wp' => self::field($report['wp'] ?? '', 40),
            'php' => self::field($report['php'] ?? '', 40),
            'core_update' => self::field($report['core_update'] ?? '', 40),
            'client_version' => self::field($report['client_version'] ?? '1.1.0', 20),
            'plugin_updates_checked' => absint($report['plugin_updates_checked'] ?? 0),
            'theme_updates_checked' => absint($report['theme_updates_checked'] ?? 0),
            'reported_at' => time(),
            'plugins' => array(),
            'mu_plugins' => $safe_mu,
            'themes' => array(),
            'bundled' => array(),
            'security' => $safe_security,
            'env' => self::sanitize_env($report['env'] ?? null),
            'core_md5' => array(),
        );
        foreach (array_slice((array) ($report['core_md5'] ?? array()), 0, 20, true) as $rel => $md5) {
            if (in_array((string) $rel, Integra_Sentinel_Intel::core_files(), true) && is_string($md5) && preg_match('/^[a-f0-9]{32}$/i', $md5)) { $safe['core_md5'][(string) $rel] = strtolower($md5); }
        }
        $ecosystem_keys = array_keys(Integra_Sentinel_Dependencies::ecosystems());

        foreach ($report['plugins'] as $item) {
            if (!is_array($item)) { continue; }
            $slug = sanitize_title($item['slug'] ?? '');
            if (!$slug) { continue; }
            $out = array();
            foreach (array('name','file','version','requires_wp','requires_php','update_uri','text_domain','elementor_tested','elementor_pro_tested','elementor_requires','wc_tested','wc_requires') as $key) {
                $out[$key] = self::field($item[$key] ?? '', 180);
            }
            $out['slug'] = $slug;
            $out['active'] = !empty($item['active']);
            $out['requires_plugins'] = array();
            foreach (array_slice((array) ($item['requires_plugins'] ?? array()), 0, 30) as $dependency) {
                $d = sanitize_title((string) $dependency);
                if ($d) { $out['requires_plugins'][] = $d; }
            }
            $update = isset($item['update']) && is_array($item['update']) ? $item['update'] : array();
            $out['update'] = array();
            foreach (array('version','requires_wp','requires_php','tested') as $key) { $out['update'][$key] = self::field($update[$key] ?? '', 45); }
            // Client >= 1.6: origine, canale di aggiornamento e accoppiamenti (valori in whitelist).
            $out['author'] = self::field($item['author'] ?? '', 120);
            foreach (array('plugin_uri', 'author_uri') as $key) { $out[$key] = self::safe_link($item[$key] ?? ''); }
            if (isset($item['channel']) && is_array($item['channel'])) {
                $type = self::field($item['channel']['type'] ?? '', 20);
                $host = strtolower(self::field($item['channel']['host'] ?? '', 120));
                $out['channel'] = array(
                    'type' => in_array($type, array('wporg','vendor','external','disabled','none','unknown'), true) ? $type : 'unknown',
                    'host' => preg_match('/^[a-z0-9.-]{1,120}$/', $host) ? $host : '',
                );
            }
            $out['updaters'] = array_values(array_intersect(array('freemius','edd','puc'), array_filter(array_slice((array) ($item['updaters'] ?? array()), 0, 5), 'is_string')));
            $out['couplings'] = array_values(array_intersect($ecosystem_keys, array_filter(array_slice((array) ($item['couplings'] ?? array()), 0, 25), 'is_string')));
            // Client >= 1.7: pacchetto scaricabile (1/0, -1 = sconosciuto), impronta del file principale, stato licenza (mai la chiave).
            $out['update']['pkg'] = array_key_exists('pkg', $update) ? (int) !empty($update['pkg']) : -1;
            $hash = strtolower((string) ($item['hash'] ?? ''));
            $out['hash'] = preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
            $license = self::sanitize_license($item['license'] ?? null);
            if ($license) { $out['license'] = $license; }
            $safe['plugins'][] = $out;
        }
        foreach ($report['themes'] as $item) {
            if (!is_array($item)) { continue; }
            $slug = sanitize_title($item['slug'] ?? '');
            if (!$slug) { continue; }
            $theme = array('slug' => $slug, 'name' => self::field($item['name'] ?? '', 160), 'version' => self::field($item['version'] ?? '', 45), 'active' => !empty($item['active']), 'update' => self::field($item['update'] ?? '', 45));
            if (array_key_exists('role', $item)) {
                $role = (string) $item['role'];
                $type = self::field($item['channel']['type'] ?? '', 20);
                $host = strtolower(self::field($item['channel']['host'] ?? '', 120));
                $info = is_array($item['update_info'] ?? null) ? $item['update_info'] : null;
                $hash = strtolower((string) ($item['hash'] ?? ''));
                $theme += array(
                    'role' => in_array($role, array('active', 'parent'), true) ? $role : '',
                    'parent' => sanitize_title((string) ($item['parent'] ?? '')),
                    'author' => self::field($item['author'] ?? '', 120),
                    'theme_uri' => self::safe_link($item['theme_uri'] ?? ''),
                    'author_uri' => self::safe_link($item['author_uri'] ?? ''),
                    'requires_wp' => self::field($item['requires_wp'] ?? '', 20),
                    'requires_php' => self::field($item['requires_php'] ?? '', 20),
                    'channel' => array('type' => in_array($type, array('wporg', 'vendor', 'none', 'unknown'), true) ? $type : 'unknown', 'host' => preg_match('/^[a-z0-9.-]{1,120}$/', $host) ? $host : ''),
                    'update_info' => $info ? array('pkg' => (int) !empty($info['pkg']), 'requires_wp' => self::field($info['requires_wp'] ?? '', 20), 'requires_php' => self::field($info['requires_php'] ?? '', 20)) : null,
                    'hash' => preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '',
                );
                $license = self::sanitize_license($item['license'] ?? null);
                if ($license) { $theme['license'] = $license; }
            }
            $safe['themes'][] = $theme;
        }
        foreach (array_slice((array) ($report['bundled'] ?? array()), 0, 60) as $item) {
            if (!is_array($item)) { continue; }
            $slug = sanitize_title($item['slug'] ?? '');
            if (!$slug) { continue; }
            $source = (string) ($item['source'] ?? '');
            $safe['bundled'][] = array('slug' => $slug, 'version' => self::field($item['version'] ?? '', 40),
                'source' => in_array($source, array('repo','external','bundled'), true) ? $source : 'unknown', 'required' => !empty($item['required']));
        }

        $accepted = false;
        $schedule_alerts = false;
        $events = array();
        $store_ready = get_option(Integra_Sentinel_Store::DB_OPTION) === Integra_Sentinel_Store::DB_VERSION;
        $ok = self::mutate_sites(function($current) use ($id, $sites, $safe, $used_prev, $store_ready, &$accepted, &$schedule_alerts, &$events) {
            if (!isset($current[$id]) || !hash_equals((string) ($sites[$id]['secret'] ?? ''), (string) ($current[$id]['secret'] ?? ''))) {
                return null;
            }
            $site = $current[$id];
            $old_report = $site['report'] ?? array();
            if (!empty($old_report['security']['admins'])) {
                $old_report['security']['admins'] = array_map(array(__CLASS__, 'mask_admin'), (array) $old_report['security']['admins']);
            }
            $changes = self::diff_versions($old_report, $safe);
            if ($store_ready) {
                // Lo storico vive nella tabella eventi (scritta dopo il lock): nessun limite di 100 voci.
                foreach ($changes as $change) { $events[] = array('at' => time(), 'type' => Integra_Sentinel_Store::event_type($change), 'message' => $change); }
            } else {
                $history = (array) ($site['history'] ?? array());
                foreach ($changes as $change) { $history[] = array('at' => time(), 'event' => $change); }
                $site['history'] = array_slice($history, -100);
            }
            $site['uses_prev_secret'] = $used_prev;

            // Rilevamento nuovi amministratori rispetto alla baseline nota del sito.
            $old_admins = (array) ($old_report['security']['admins'] ?? array());
            $new_admins = (array) ($safe['security']['admins'] ?? array());
            $rogue_admins = (!empty($old_admins) && !empty($new_admins)) ? array_values(array_diff($new_admins, $old_admins)) : array();
            $prev_rogue = array_map(array(__CLASS__, 'mask_admin'), (array) ($site['rogue_admins'] ?? array()));
            if ($rogue_admins) {
                $prev_rogue = array_values(array_unique(array_merge($prev_rogue, $rogue_admins)));
            }
            if (!empty($new_admins)) {
                $prev_rogue = array_values(array_intersect($prev_rogue, $new_admins));
            }
            $site['rogue_admins'] = $prev_rogue;

            // Calcolo anomalie di integrità e accodamento immediato degli alert.
            $int_findings = array_merge(self::build_integrity_findings($safe['security'], $prev_rogue), self::core_integrity_findings($safe));
            $site['integrity_findings'] = $int_findings;
            $int_current = array_values(array_unique(array_column($int_findings, 'id')));
            $int_old     = array_values(array_intersect((array) ($site['integrity_alert_ids'] ?? array()), $int_current));
            $int_queue   = (array) ($site['integrity_pending_ids'] ?? array());
            $int_fresh   = array_values(array_diff($int_current, $int_old, $int_queue));
            if ($int_fresh) {
                $int_queue = array_values(array_unique(array_merge($int_queue, $int_fresh)));
                $schedule_alerts = true;
            }
            $site['integrity_alert_ids']   = $int_old;
            $site['integrity_pending_ids'] = $int_queue;

            $site['report'] = $safe;
            $site['last_seen'] = time();
            $site['offline_alerted'] = false;
            $site['feed_pending'] = true;
            $current[$id] = $site;
            $accepted = true;
            return $current;
        });
        if (!$ok) { return self::fail('busy', 503); }
        if (!$accepted) { return self::fail('rotated'); }
        if ($events) { Integra_Sentinel_Store::add_events($id, $events); }
        if ($schedule_alerts && !wp_next_scheduled(self::HOOK_ALERTS)) {
            wp_schedule_single_event(time() + 60, self::HOOK_ALERTS);
        }
        if (!wp_next_scheduled('integra_sentinel_master_audit_soon')) {
            wp_schedule_single_event(time() + 20, 'integra_sentinel_master_audit_soon');
        }
        return new WP_REST_Response(array('ok' => true, 'received' => time()), 200);
    }

    private static function diff_versions($old, $new) {
        if (empty($old)) { return array('Primo inventario ricevuto.'); }
        $changes = array();
        if (($old['wp'] ?? '') !== $new['wp']) { $changes[] = 'WordPress: ' . $old['wp'] . ' → ' . $new['wp']; }
        foreach (array('plugins' => 'file', 'themes' => 'slug', 'mu_plugins' => 'file') as $group => $key) {
            $before = array(); $after = array();
            foreach ((array) ($old[$group] ?? array()) as $v) { if (!empty($v[$key])) { $before[$v[$key]] = $v; } }
            foreach ((array) ($new[$group] ?? array()) as $v) { if (!empty($v[$key])) { $after[$v[$key]] = $v; } }
            $prefix = $group === 'mu_plugins' ? '[MU] ' : '';
            foreach ($after as $slug => $v) {
                if (!isset($before[$slug])) { $changes[] = 'Installato ' . $prefix . $v['name'] . ' ' . ($v['version'] ?? ''); }
                elseif (($before[$slug]['version'] ?? '') !== ($v['version'] ?? '')) { $changes[] = $prefix . $v['name'] . ': ' . ($before[$slug]['version'] ?? '') . ' → ' . ($v['version'] ?? ''); }
            }
            foreach ($before as $slug => $v) {
                if (!isset($after[$slug])) { $changes[] = 'Rimosso ' . $prefix . $v['name'] . ' ' . ($v['version'] ?? ''); }
            }
        }
        $old_admins = (array) ($old['security']['admins'] ?? array());
        $new_admins = (array) ($new['security']['admins'] ?? array());
        if (!empty($old_admins) && !empty($new_admins)) {
            foreach (array_diff($new_admins, $old_admins) as $added_admin) {
                $changes[] = 'SECURITY: Nuovo amministratore ' . $added_admin;
            }
            foreach (array_diff($old_admins, $new_admins) as $removed_admin) {
                $changes[] = 'Rimosso amministratore ' . $removed_admin;
            }
        }
        return array_slice($changes, 0, 120);
    }

    /** Solo link http/https, senza credenziali: provengono dal Client e finiscono in href. */
    public static function safe_link($value) {
        if (!is_string($value) || $value === '' || strlen($value) > 300) { return ''; }
        $url = esc_url_raw(trim($value), array('http', 'https'));
        $parts = wp_parse_url($url);
        return ($url && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass'])) ? substr($url, 0, 250) : '';
    }

    public static function field($value, $length) {
        if (!is_scalar($value)) { return ''; }
        $value = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /** Stato licenza dal Client 1.7: solo valori in whitelist, nessuna chiave. */
    private static function sanitize_license($license) {
        if (!is_array($license)) { return null; }
        $status = (string) ($license['status'] ?? '');
        $expires = (string) ($license['expires'] ?? '');
        return array('status' => in_array($status, array('valid', 'expired', 'invalid', 'missing', 'present'), true) ? $status : 'present',
            'expires' => ($expires === 'lifetime' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) ? $expires : '');
    }

    /** Blocco "env" del Client 1.7 (aggiornabilita' del server), validato campo per campo. */
    private static function sanitize_env($env) {
        if (!is_array($env)) { return array(); }
        $db = is_array($env['db'] ?? null) ? $env['db'] : array();
        $writable = is_array($env['writable'] ?? null) ? $env['writable'] : array();
        $cron = is_array($env['cron'] ?? null) ? $env['cron'] : array();
        $auto = is_array($env['auto_updates'] ?? null) ? $env['auto_updates'] : array();
        $pending = is_array($env['pending'] ?? null) ? $env['pending'] : array();
        $out = array(
            'db' => array('type' => ($db['type'] ?? '') === 'mariadb' ? 'mariadb' : 'mysql',
                'version' => preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($db['version'] ?? '')) ? (string) $db['version'] : ''),
            'locale' => preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}$/', (string) ($env['locale'] ?? '')) ? (string) $env['locale'] : '',
            'file_mods' => array_key_exists('file_mods', $env) ? !empty($env['file_mods']) : true,
            'fs_method' => in_array($env['fs_method'] ?? '', array('direct', 'ssh2', 'ftpext', 'ftpsockets'), true) ? (string) $env['fs_method'] : '',
            'fs_credentials' => !empty($env['fs_credentials']),
            'writable' => array(),
            'disk_free_mb' => isset($env['disk_free_mb']) && is_numeric($env['disk_free_mb']) ? max(0, (int) $env['disk_free_mb']) : null,
            'memory_limit' => self::field($env['memory_limit'] ?? '', 12),
            'wp_memory_limit' => self::field($env['wp_memory_limit'] ?? '', 12),
            'max_execution_time' => absint($env['max_execution_time'] ?? 0),
            'cron' => array('disabled' => !empty($cron['disabled']), 'alternate' => !empty($cron['alternate']),
                'overdue' => min(100000, absint($cron['overdue'] ?? 0)), 'oldest' => absint($cron['oldest'] ?? 0)),
            'object_cache' => !empty($env['object_cache']),
            'health' => null,
            'auto_updates' => array('plugins' => array(),
                'core' => in_array($auto['core'] ?? '', array('all', 'minor', 'off', 'beta', 'rc', 'development', 'branch-development'), true) ? (string) $auto['core'] : ''),
            'backup' => null,
            'pending' => array(),
        );
        foreach (array('plugins', 'themes', 'content') as $key) {
            if (array_key_exists($key, $writable)) { $out['writable'][$key] = !empty($writable[$key]); }
        }
        if (is_array($env['health'] ?? null)) {
            $out['health'] = array('good' => absint($env['health']['good'] ?? 0), 'recommended' => absint($env['health']['recommended'] ?? 0), 'critical' => absint($env['health']['critical'] ?? 0));
        }
        foreach (array_slice((array) ($auto['plugins'] ?? array()), 0, 300) as $slug) {
            $slug = sanitize_title((string) $slug);
            if ($slug !== '') { $out['auto_updates']['plugins'][] = $slug; }
        }
        if (is_array($env['backup'] ?? null) && !empty($env['backup']['last'])) {
            $out['backup'] = array('plugin' => sanitize_key((string) ($env['backup']['plugin'] ?? '')), 'last' => absint($env['backup']['last']), 'ok' => !array_key_exists('ok', $env['backup']) || !empty($env['backup']['ok']));
        }
        foreach (array('wc_db', 'elementor_db') as $key) {
            if (is_array($pending[$key] ?? null)) { $out['pending'][$key] = array('db' => self::field($pending[$key]['db'] ?? '', 30), 'code' => self::field($pending[$key]['code'] ?? '', 30)); }
        }
        foreach (array_slice((array) ($pending['wc_templates'] ?? array()), 0, 20) as $template) {
            if (!is_array($template)) { continue; }
            $out['pending']['wc_templates'][] = array('file' => self::field($template['file'] ?? '', 120), 'theme' => self::field($template['theme'] ?? '', 20), 'core' => self::field($template['core'] ?? '', 20));
        }
        if (is_array($env['envato_market'] ?? null)) { $out['envato_market'] = array('token' => !empty($env['envato_market']['token'])); }
        return $out;
    }

    /**
     * Interroga WPVulnerability per piu' componenti in parallelo (dal Master; i siti non fanno chiamate).
     * @param array $targets key => array(type, slug)
     */
    private static function fetch_many($targets) {
        $requests = array();
        foreach ($targets as $key => $spec) {
            $requests[$key] = array('url' => 'https://www.wpvulnerability.net/' . $spec[0] . '/' . rawurlencode($spec[1]) . '/', 'timeout' => 8,
                'headers' => array('Accept' => 'application/json'), 'max_bytes' => 2097152);
        }
        $out = array();
        foreach (Integra_Sentinel_Intel::parallel_get($requests) as $key => $response) {
            $out[$key] = self::parse_feed((int) $response['code'], (string) $response['body'], (string) $response['error']);
        }
        return $out;
    }

    /** I dati WPVulnerability possono arrivare come data.vulnerability (singolare), data.vulnerabilities o elenco diretto. */
    private static function parse_feed($code, $body, $error = '') {
        if ($code === 0) { return array('state' => 'error', 'message' => $error !== '' ? $error : 'Errore di rete', 'advisories' => array(), 'at' => time()); }
        if ($code === 404) { return array('state' => 'missing', 'message' => 'Non censito dalla fonte', 'advisories' => array(), 'at' => time()); }
        if ($code !== 200) { return array('state' => 'error', 'message' => 'HTTP ' . $code, 'advisories' => array(), 'at' => time()); }
        $json = json_decode($body, true);
        if (!is_array($json)) { return array('state' => 'error', 'message' => 'JSON non valido', 'advisories' => array(), 'at' => time()); }
        if (isset($json['error']) && (int) $json['error'] !== 0) {
            return array('state' => 'error', 'message' => self::field($json['message'] ?? 'Errore API', 180), 'advisories' => array(), 'at' => time());
        }
        $root = array_key_exists('data', $json) ? $json['data'] : $json;
        $advisories = self::unpack_advisories($root);
        if ($advisories === null) { return array('state' => 'error', 'message' => 'Schema WPVulnerability non riconosciuto', 'advisories' => array(), 'at' => time()); }
        return array('state' => 'ok', 'message' => '', 'advisories' => array_slice($advisories, 0, 500), 'at' => time());
    }

    public static function unpack_advisories($root) {
        if (!is_array($root)) { return null; }
        if ($root === array()) { return array(); }
        foreach (array('vulnerability','vulnerabilities') as $key) {
            if (!array_key_exists($key, $root)) { continue; }
            $v = $root[$key];
            if ($v === null) { return array(); }
            if (!is_array($v)) { return null; }
            if (array_values($v) === $v) { return $v; }
            if (!isset($v['operator']) && !isset($v['name']) && !isset($v['title']) && !isset($v['versions'])) {
                $all_records = true;
                foreach ($v as $entry) { if (!is_array($entry)) { $all_records = false; break; } }
                if ($all_records) { return array_values($v); }
            }
            return array($v);
        }
        if (array_values($root) === $root) { return $root; }
        foreach (array('plugin','theme','core') as $key) {
            if (isset($root[$key])) { return self::unpack_advisories($root[$key]); }
        }
        return null;
    }

    private static function op_normalize($op) {
        $ops = array('lt'=>'<','lte'=>'<=','le'=>'<=','gt'=>'>','gte'=>'>=','ge'=>'>=','eq'=>'==','='=>'==','=='=>'==','<'=>'<','<='=>'<=','>'=>'>','>='=>'>=');
        $op = strtolower(trim((string) $op));
        return isset($ops[$op]) ? $ops[$op] : '';
    }

    private static function range_text($v) {
        if (!empty($v['operator']) && is_array($v['operator'])) {
            $r = $v['operator']; $terms = array();
            foreach (array('min','max') as $part) {
                $value = $r[$part . '_version'] ?? null;
                $operator = self::op_normalize($r[$part . '_operator'] ?? '');
                if ($value !== null && $value !== '' && $operator) { $terms[] = $operator . ' ' . $value; }
            }
            return $terms ? implode(', ', $terms) : 'Intervallo non disponibile';
        }
        foreach (array('versions','affected','affected_versions') as $key) {
            if (!empty($v[$key]) && is_scalar($v[$key])) { return (string) $v[$key]; }
        }
        return 'Intervallo non disponibile';
    }

    /** null = sconosciuto; true = versione colpita; false = fuori intervallo. */
    public static function matches($installed, $vulnerability) {
        if (!$installed || !is_array($vulnerability)) { return null; }
        if (array_key_exists('operator', $vulnerability) && is_array($vulnerability['operator'])) {
            $range = $vulnerability['operator']; $seen = 0;
            foreach (array('min','max') as $part) {
                $value = $range[$part . '_version'] ?? null;
                if ($value === null || $value === '') { continue; }
                $operator = self::op_normalize($range[$part . '_operator'] ?? '');
                if (!$operator || !preg_match('/^[0-9v][0-9A-Za-z.+_-]*$/', (string) $value)) { return null; }
                ++$seen;
                if (!version_compare($installed, ltrim((string) $value, 'v'), $operator)) { return false; }
            }
            return $seen ? true : null;
        }
        $raw = $vulnerability['versions'] ?? ($vulnerability['affected'] ?? ($vulnerability['affected_versions'] ?? null));
        if (is_array($raw)) {
            if (isset($raw['from_version']) || isset($raw['to_version'])) {
                $parts = array();
                if (!empty($raw['from_version'])) { $parts[] = ($raw['from_inclusive'] ?? true ? '>=' : '>') . $raw['from_version']; }
                if (!empty($raw['to_version'])) { $parts[] = ($raw['to_inclusive'] ?? true ? '<=' : '<') . $raw['to_version']; }
                $raw = implode(', ', $parts);
            } elseif (isset($raw['from'], $raw['to'])) { $raw = '>=' . $raw['from'] . ', <=' . $raw['to']; }
            else { return null; }
        }
        if (!is_scalar($raw)) { return null; }
        $tokens = preg_split('/\s*(?:,|\band\b|&&)\s*/i', trim(html_entity_decode((string) $raw, ENT_QUOTES)));
        if (!$tokens) { return null; }
        $tested = 0;
        foreach ($tokens as $token) {
            if (preg_match('/^(<=|>=|<|>|=|==)\s*v?([0-9][a-z0-9.+_-]*)$/i', trim($token), $m)) {
                ++$tested;
                if (!version_compare($installed, $m[2], $m[1] === '=' ? '==' : $m[1])) { return false; }
            } elseif (preg_match('/^v?([0-9][a-z0-9.+_-]*)\s*[-–]\s*v?([0-9][a-z0-9.+_-]*)$/i', trim($token), $m)) {
                ++$tested;
                if (version_compare($installed, $m[1], '<') || version_compare($installed, $m[2], '>')) { return false; }
            } else { return null; }
        }
        return $tested ? true : null;
    }

    private static function signature($type, $slug, $v) {
        $id = (string) ($v['id'] ?? ($v['uuid'] ?? ($v['name'] ?? ($v['title'] ?? ''))));
        return sha1($type . ':' . $slug . ':' . $id . ':' . wp_json_encode($v['operator'] ?? ($v['versions'] ?? ($v['affected'] ?? null))));
    }

    private static function advisories_for($cache, $type, $slug, $version) {
        $entry = $cache[$type . ':' . $slug] ?? null;
        if (!$entry || ($entry['state'] ?? '') !== 'ok') {
            return array('status' => !$entry ? 'pending' : (($entry['state'] ?? '') === 'missing' ? 'missing' : 'error'), 'findings' => array(), 'uncertain' => 0);
        }
        $findings = array(); $uncertain = 0;
        foreach ((array) ($entry['advisories'] ?? array()) as $v) {
            if (!is_array($v)) { continue; }
            $matched = self::matches($version, $v);
            if ($matched === null) { ++$uncertain; continue; }
            if (!$matched) { continue; }
            $cvss = $v['impact']['cvss3']['score'] ?? ($v['impact']['cvss4']['score'] ?? ($v['impact']['cvss']['score'] ?? ($v['score'] ?? null)));
            $source = '';
            foreach (array('source','references') as $field) {
                if (empty($v[$field])) { continue; }
                if (is_string($v[$field])) { $source = esc_url_raw($v[$field]); break; }
                if (is_array($v[$field])) {
                    foreach ($v[$field] as $link) {
                        if (is_array($link)) { $link = $link['link'] ?? ($link['url'] ?? ''); }
                        if (is_string($link) && strpos($link, 'https://') === 0) { $source = esc_url_raw($link); break 2; }
                    }
                }
            }
            $score = is_numeric($cvss) ? (float) $cvss : null;
            $findings[] = array('id' => self::signature($type, $slug, $v), 'type' => $type, 'slug' => $slug,
                'version' => $version, 'title' => self::field($v['name'] ?? ($v['title'] ?? 'Vulnerabilità nota'), 200),
                'reference' => self::field($v['cve'] ?? ($v['cve_id'] ?? ($v['id'] ?? '')), 100),
                'fixed' => !empty($v['operator']['max_version']) && in_array($v['operator']['max_operator'] ?? '', array('lt','<'), true) ? self::field($v['operator']['max_version'], 45) : '',
                'range' => self::field(self::range_text($v), 100), 'score' => $score,
                'severity' => self::field($v['impact']['cvss3']['severity'] ?? ($v['severity'] ?? ''), 30),
                'source' => $source, 'unfixed' => !empty($v['operator']['unfixed']) && $v['operator']['unfixed'] !== '0',
                'closed' => !empty($v['operator']['closed']) && $v['operator']['closed'] !== '0');
        }
        return array('status' => $uncertain ? 'review' : 'ok', 'findings' => $findings, 'uncertain' => $uncertain);
    }

    private static function skip_plugin_lookup($p) {
        return in_array((string) ($p['slug'] ?? ''), array('integra-sentinel-client', 'integra-sentinel-master'), true);
    }

    public static function plugin_identity($p) {
        if (self::skip_plugin_lookup($p)) { return 'internal'; }
        if (empty($p['update_uri'])) { return 'standard'; }
        $slug = (string) ($p['slug'] ?? '');
        $name = (string) ($p['name'] ?? '');
        $known = array(
            'elementor-pro' => '~\belementor\s+pro\b~i',
            'advanced-custom-fields-pro' => '~\badvanced\s+custom\s+fields\s+pro\b|\bacf\s+pro\b~i',
            'wp-rocket' => '~\bwp\s+rocket\b~i',
            'gravityforms' => '~\bgravity\s+forms\b~i',
            'rank-math-pro' => '~\brank\s+math\b.*\bpro\b~i',
            'seo-by-rank-math-pro' => '~\brank\s+math\b.*\bpro\b~i',
            'wordpress-seo-premium' => '~\byoast\s+seo\s+premium\b~i',
            'jet-engine' => '~\bjetengine\b|\bjet\s+engine\b~i',
            'theplus_elementor_addon' => '~\bplus\s+addons?\b.*\belementor\b~i',
            'translatepress-business' => '~\btranslatepress\b.*\bbusiness\b~i',
        );
        return isset($known[$slug]) && preg_match($known[$slug], $name) === 1 ? 'recognized' : 'unconfirmed';
    }

    private static function scan_target_list($sites) {
        $items = array();
        foreach ($sites as $site) {
            $report = $site['report'] ?? array();
            if (empty($report)) { continue; }
            if (!empty($report['wp'])) { $items['core:' . $report['wp']] = array('core', $report['wp']); }
            foreach ((array) ($report['plugins'] ?? array()) as $p) {
                if (!empty($p['slug']) && !self::skip_plugin_lookup($p)) { $items['plugin:' . $p['slug']] = array('plugin', $p['slug']); }
            }
            foreach ((array) ($report['themes'] ?? array()) as $t) {
                if (!empty($t['slug'])) { $items['theme:' . $t['slug']] = array('theme', $t['slug']); }
            }
        }
        return $items;
    }

    public static function audit() {
        try {
            Integra_Sentinel_Store::maybe_upgrade();
            $sites = self::sites();
            $cache = (array) get_option(self::FEEDS, array());
            $targets = self::scan_target_list($sites);
            $batch = max(2, min(12, (int) (Integra_Sentinel_Settings::get('scan')['batch'] ?? 6)));
            $due = array(); $remaining = false;
            foreach ($targets as $key => $params) {
                $entry = $cache[$key] ?? array();
                $ttl = ($entry['state'] ?? '') === 'error' ? 900 : self::CACHE_TTL;
                if (!empty($entry['at']) && time() - (int) $entry['at'] < $ttl) { continue; }
                if (count($due) >= $batch) { $remaining = true; break; }
                $due[$key] = $params;
            }
            $delta = self::fetch_many($due);
            if (!self::store_feed_delta(self::FEEDS, $delta)) { return; }

            // Controllo periodico certificato SSL (max 1 sito per run fuori dal DB lock).
            $ssl_updates = array();
            foreach ($sites as $sid => $sdata) {
                if (!empty($sdata['url']) && (time() - (int) ($sdata['ssl_checked_at'] ?? 0)) > 12 * HOUR_IN_SECONDS) {
                    $days = self::probe_ssl_days($sdata['url']);
                    $ssl_updates[$sid] = array('days' => $days, 'at' => time());
                    break;
                }
            }
            if ($ssl_updates) {
                self::mutate_sites(function($current) use ($ssl_updates) {
                    foreach ($ssl_updates as $sid => $info) {
                        if (isset($current[$sid])) {
                            $current[$sid]['ssl_days_left']  = $info['days'];
                            $current[$sid]['ssl_checked_at'] = $info['at'];
                        }
                    }
                    return $current;
                });
            }

            self::recalculate();
            self::wpscan_audit();
            // Fonti esterne (WordPress.org, checksum, Envato) e lavori giornalieri: tutto sul Master, a lotti.
            Integra_Sentinel_Intel::tick(10.0);
            Integra_Sentinel_Jobs::maybe_daily();
            if ($remaining && !wp_next_scheduled('integra_sentinel_master_audit_soon')) {
                wp_schedule_single_event(time() + 70, 'integra_sentinel_master_audit_soon');
            }
        } finally { self::clean_nonces(); }
    }

    /** Scansione immediata a piccoli lotti AJAX, non dipendente dal traffico WP-Cron. */
    public static function ajax_scan_site() {
        if (!Integra_Sentinel_Settings::can_manage()) { wp_send_json_error('Non autorizzato', 403); }
        check_ajax_referer('integra_sentinel_scan_site', 'nonce');
        $id = isset($_POST['site']) ? sanitize_text_field(wp_unslash($_POST['site'])) : '';
        $sites = self::sites();
        if (!isset($sites[$id]) || empty($sites[$id]['report'])) { wp_send_json_error('Sito non collegato', 404); }
        $targets = self::scan_target_list(array($id => $sites[$id]));
        if (!empty($_POST['reset'])) {
            $ssl_days = self::probe_ssl_days($sites[$id]['url']);
            self::mutate_sites(function($current) use ($id, $ssl_days) {
                if (isset($current[$id])) {
                    $current[$id]['ssl_days_left']  = $ssl_days;
                    $current[$id]['ssl_checked_at'] = time();
                }
                return $current;
            });
            if (!self::mutate_option(self::FEEDS, function($current) use ($targets) {
                foreach ($targets as $key => $_) { unset($current[$key]); }
                return $current;
            })) { wp_send_json_error('Cache occupata: riprova.', 503); }
        }
        $cache = (array) self::fresh_option(self::FEEDS, array());
        $due = array();
        foreach ($targets as $key => $spec) {
            if (isset($cache[$key])) { continue; }
            if (count($due) >= 8) { break; }
            $due[$key] = $spec;
        }
        $delta = self::fetch_many($due);
        if (!self::store_feed_delta(self::FEEDS, $delta)) { wp_send_json_error('Scrittura cache occupata: riprova.', 503); }
        $cache = (array) self::fresh_option(self::FEEDS, array());
        if (!self::recalculate()) { wp_send_json_error('Archivio momentaneamente occupato. Riprova.', 503); }
        $done = 0; $errors = 0;
        foreach ($targets as $key => $spec) {
            if (!isset($cache[$key])) { continue; }
            ++$done;
            if (($cache[$key]['state'] ?? '') !== 'ok') { ++$errors; }
        }
        $latest = self::sites();
        $positives = count($latest[$id]['findings'] ?? array());
        wp_send_json_success(array('done' => $done, 'total' => count($targets), 'errors' => $errors,
            'vulnerabilities' => $positives, 'complete' => $done >= count($targets)));
    }

    public static function clean_nonces() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like('integra_sentinel_nonce_') . '%', time() - 900));
    }

    private static function recalculate($sites = null, $cache = null) {
        return self::mutate_sites(function($sites) {
            $cache = (array) self::fresh_option(self::FEEDS, array());
            foreach ($sites as $id => &$site) {
                if (empty($site['report'])) { continue; }
                $r = $site['report'];
                // I checksum ufficiali del core arrivano dopo il report: le nuove anomalie verificate entrano negli alert.
                $known = array_column((array) ($site['integrity_findings'] ?? array()), 'id');
                $new_core = array();
                foreach (self::core_integrity_findings($r) as $finding) {
                    if (in_array($finding['id'], $known, true)) { continue; }
                    $site['integrity_findings'][] = $finding;
                    $new_core[] = $finding['id'];
                }
                if ($new_core) {
                    $site['integrity_pending_ids'] = array_values(array_unique(array_merge((array) ($site['integrity_pending_ids'] ?? array()),
                        array_diff($new_core, (array) ($site['integrity_alert_ids'] ?? array())))));
                    if (!wp_next_scheduled(self::HOOK_ALERTS)) { wp_schedule_single_event(time() + 60, self::HOOK_ALERTS); }
                }
                $all = array(); $possible = array(); $pending = false; $review = 0;
                $targets = array();
                $targets[] = array('core', (string) $r['wp']);
                foreach ($r['plugins'] as $p) { if (!self::skip_plugin_lookup($p)) { $targets[] = array('plugin', $p['slug'], $p['version'], $p['name'], self::plugin_identity($p)); } }
                foreach ($r['themes'] as $t) { $targets[] = array('theme', $t['slug'], $t['version'], $t['name']); }
                foreach ($targets as $target) {
                    $type = $target[0]; $slug = $target[1];
                    $version = $type === 'core' ? $slug : $target[2];
                    $result = self::advisories_for($cache, $type, $slug, $version);
                    if ($result['status'] !== 'ok' && $result['status'] !== 'review') { $pending = true; }
                    $review += $result['uncertain'];
                    foreach ($result['findings'] as $finding) {
                        $finding['component'] = $type === 'core' ? 'WordPress' : $target[3];
                        if ($type === 'plugin' && ($target[4] ?? '') === 'unconfirmed') {
                            $finding['identity_unconfirmed'] = true;
                            $possible[] = $finding;
                        } else { $all[] = $finding; }
                    }
                }
                $site['findings'] = $all;
                $site['possible_findings'] = $possible;
                $possible_current = array_values(array_unique(array_column($possible, 'id')));
                $possible_old = (array) ($site['possible_alert_ids'] ?? array());
                $possible_queue = (array) ($site['possible_pending_ids'] ?? array());
                if (!$pending) { $possible_old = array_values(array_intersect($possible_old, $possible_current)); }
                $possible_new = array_values(array_diff($possible_current, $possible_old, $possible_queue));
                if ($possible_new) {
                    $possible_queue = array_values(array_unique(array_merge($possible_queue, $possible_new)));
                    if (!wp_next_scheduled(self::HOOK_ALERTS)) { wp_schedule_single_event(time() + 180, self::HOOK_ALERTS); }
                }
                $site['possible_alert_ids'] = $possible_old;
                $site['possible_pending_ids'] = $possible_queue;
                $site['feed_pending'] = $pending;
                $site['feed_review'] = $review;
                $current = array_values(array_unique(array_column($all, 'id')));
                $old = (array) ($site['alert_ids'] ?? array());
                $queued = (array) ($site['pending_alert_ids'] ?? array());
                if (!$pending) { $old = array_values(array_intersect($old, $current)); }
                $fresh = array_values(array_diff($current, $old, $queued));
                if ($fresh) {
                    $queued = array_values(array_unique(array_merge($queued, $fresh)));
                    if (!wp_next_scheduled(self::HOOK_ALERTS)) {
                        wp_schedule_single_event(time() + 180, self::HOOK_ALERTS);
                    }
                }
                $site['pending_alert_ids'] = $queued;
                $site['alert_ids'] = $old;
                if (!empty($site['last_seen']) && time() - (int) $site['last_seen'] > 36 * HOUR_IN_SECONDS
                    && empty($site['offline_alerted']) && (!empty(self::mail_config()['alerts']) || Integra_Sentinel_Notify::configured())
                    && !wp_next_scheduled(self::HOOK_ALERTS)) {
                    wp_schedule_single_event(time() + 180, self::HOOK_ALERTS);
                }
            }
            unset($site);
            return $sites;
        });
    }

    private static function update_assessment($plugin, $report) {
        $update = $plugin['update']['version'] ?? '';
        if (!$update) {
            $checked = (int) ($report['plugin_updates_checked'] ?? 0);
            if (!$checked || time() - $checked > DAY_IN_SECONDS) {
                return array('label' => 'Da verificare', 'class' => 'yellow', 'notes' => array('I controlli WordPress sugli aggiornamenti sono assenti o risalgono a più di 24 ore fa.'));
            }
            return array('label' => 'Nessun update segnalato', 'class' => 'green', 'notes' => array());
        }
        $slug = $plugin['slug'];
        $requires = (array) ($plugin['requires_plugins'] ?? array());
        $parents = array();
        foreach ($report['plugins'] as $p) { $parents[$p['slug']] = $p; }
        $notes = array(); $flag = 'green';
        $target_wp = $plugin['update']['requires_wp'] ?: $plugin['requires_wp'];
        $target_php = $plugin['update']['requires_php'] ?: $plugin['requires_php'];
        if ($target_wp && version_compare($report['wp'], $target_wp, '<')) { $notes[] = 'La nuova versione richiede WordPress ' . $target_wp . ' o superiore.'; $flag = 'red'; }
        if ($target_php && version_compare($report['php'], $target_php, '<')) { $notes[] = 'La nuova versione richiede PHP ' . $target_php . ' o superiore.'; $flag = 'red'; }
        foreach ($requires as $req) {
            if (!isset($parents[$req])) { $notes[] = 'Dipendenza assente: ' . $req . '.'; $flag = 'red'; }
            elseif (!$parents[$req]['active']) { $notes[] = 'Dipendenza non attiva: ' . $req . '.'; $flag = 'red'; }
            else { $notes[] = 'Dipende da ' . $req . ' (' . $parents[$req]['version'] . ').'; if ($flag !== 'red') { $flag = 'yellow'; } }
        }
        foreach (array('woocommerce' => 'wc_requires', 'elementor' => 'elementor_requires') as $parent_slug => $header) {
            $minimum = $plugin[$header] ?? '';
            if (!$minimum) { continue; }
            if (!isset($parents[$parent_slug])) {
                if (in_array($parent_slug, $requires, true)) {
                    $notes[] = 'Dipendenza obbligatoria assente: ' . $parent_slug . '.';
                    $flag = 'red';
                }
            } elseif (version_compare($parents[$parent_slug]['version'], $minimum, '<')) {
                $parent_target = $parents[$parent_slug]['update']['version'] ?? '';
                $notes[] = 'Richiede ' . $parent_slug . ' >= ' . $minimum . '; presente ' . $parents[$parent_slug]['version'] . '.' . ($parent_target && version_compare($parent_target, $minimum, '>=') ? ' Aggiornare prima ' . $parent_slug . ' alla ' . $parent_target . '.' : ' Aggiornamento del plugin principale da verificare.');
                $flag = 'red';
            }
        }
        foreach (array('elementor' => array('elementor_tested','elementor_requires'), 'woocommerce' => array('wc_tested','wc_requires')) as $parent => $fields) {
            if ($slug === $parent || empty($parents[$parent])) { continue; }
            $declared = in_array($parent, $requires, true);
            $probable = stripos($plugin['name'] . ' ' . $slug, $parent) !== false || !empty($plugin[$fields[0]]) || !empty($plugin[$fields[1]]);
            if (!$declared && !$probable) { continue; }
            $notes[] = ($declared ? 'Dipendenza dichiarata' : 'Integrazione identificata') . ': ' . $parent . ' ' . $parents[$parent]['version'] . '.';
            $parent_update = $parents[$parent]['update']['version'] ?? '';
            if ($parent_update && !empty($plugin[$fields[0]]) && version_compare($parent_update, $plugin[$fields[0]], '>')) {
                $notes[] = 'Per ' . $parent . ' ' . $parent_update . ', l’addon risulta testato solo fino alla ' . $plugin[$fields[0]] . '.';
                if ($flag !== 'red') { $flag = 'yellow'; }
            } elseif ($parent_update && empty($plugin[$fields[0]])) {
                $notes[] = 'Compatibilità con ' . $parent . ' ' . $parent_update . ' non dichiarata.';
                if ($flag !== 'red') { $flag = 'yellow'; }
            }
        }
        $dependent = array();
        foreach ($report['plugins'] as $p) {
            if ($p['slug'] === $slug) { continue; }
            $declared = in_array($slug, (array) ($p['requires_plugins'] ?? array()), true);
            $likely = ($slug === 'elementor' || $slug === 'woocommerce') && stripos($p['name'] . ' ' . $p['slug'], $slug) !== false;
            $pro = $slug === 'elementor' && $p['slug'] === 'elementor-pro';
            if (!$declared && !$likely && !$pro) { continue; }
            $tested = $slug === 'elementor' ? ($p['elementor_tested'] ?? '') : ($p['wc_tested'] ?? '');
            $detail = $p['name'];
            if ($tested && version_compare($update, $tested, '>')) {
                $detail .= ' (testato fino a ' . $tested . ')';
            } elseif (!$tested) {
                $detail .= ' (compatibilità con nuova versione non dichiarata)';
            }
            $dependent[] = $detail;
        }
        if ($dependent) {
            $notes[] = 'Addon collegati: ' . implode('; ', array_slice($dependent, 0, 15)) . '.';
            if ($flag !== 'red') { $flag = 'yellow'; }
        }
        if (($plugin['update_uri'] ?? '') !== '' && $flag === 'green') {
            $flag = 'yellow'; $notes[] = 'Canale di aggiornamento esterno: verificare istruzioni del fornitore.';
        }
        if (!$notes) { $notes[] = 'Nessun vincolo dichiarato individuato. Non equivale a garanzia di compatibilità.'; }
        $labels = array('green' => 'Nessun blocco noto', 'yellow' => 'Verifica / coordinamento', 'red' => 'Prerequisito non soddisfatto');
        return array('label' => $labels[$flag], 'class' => $flag, 'notes' => $notes);
    }

    public static function wpscan_per_site_limit($site_count) {
        return $site_count < 13 ? 2 : 1;
    }

    private static function wpscan_usage() {
        $logs = (array) get_option(self::WPSCAN_USAGE, array());
        $cutoff = time() - DAY_IN_SECONDS;
        return array_values(array_filter($logs, function($x) use ($cutoff) {
            return is_array($x) && (int) ($x['at'] ?? 0) > $cutoff;
        }));
    }

    private static function wpscan_targets($report) {
        $targets = array();
        if (!empty($report['wp']) && preg_match('/^\d+(?:\.\d+){1,3}$/', (string) $report['wp'])) {
            $targets['core:' . $report['wp']] = array('type' => 'core', 'slug' => $report['wp'], 'version' => $report['wp'], 'name' => 'WordPress');
        }
        foreach ((array) ($report['plugins'] ?? array()) as $p) {
            if (self::skip_plugin_lookup($p) || empty($p['slug']) || empty($p['version'])) { continue; }
            $targets['plugin:' . $p['slug']] = array('type' => 'plugin', 'slug' => $p['slug'], 'version' => $p['version'], 'name' => $p['name']);
        }
        foreach ((array) ($report['themes'] ?? array()) as $t) {
            if (empty($t['slug']) || empty($t['version'])) { continue; }
            $targets['theme:' . $t['slug']] = array('type' => 'theme', 'slug' => $t['slug'], 'version' => $t['version'], 'name' => $t['name']);
        }
        return $targets;
    }

    private static function wpscan_fetch($type, $slug, $token) {
        $part = $type === 'core' ? 'wordpresses/' . preg_replace('/\D/', '', $slug) : ($type === 'plugin' ? 'plugins/' : 'themes/') . rawurlencode($slug);
        $response = wp_safe_remote_get('https://wpscan.com/api/v3/' . $part, array(
            'timeout' => 6, 'redirection' => 0, 'sslverify' => true,
            'headers' => array('Accept' => 'application/json', 'Authorization' => 'Token token=' . $token),
            'limit_response_size' => 1048576,
        ));
        $result = array('state' => 'error', 'message' => '', 'at' => time(), 'advisories' => array());
        if (is_wp_error($response)) { $result['message'] = 'Errore rete: ' . $response->get_error_code(); return $result; }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) { $result['state'] = 'missing'; $result['message'] = 'Componente non censito da WPScan'; return $result; }
        if ($code === 401 || $code === 403) { $result['message'] = 'Token/licenza non autorizzati (' . $code . ')'; return $result; }
        if ($code === 429) { $result['message'] = 'Quota WPScan esaurita dal fornitore (HTTP 429)'; return $result; }
        if ($code !== 200) { $result['message'] = 'HTTP ' . $code; return $result; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) { $result['message'] = 'Risposta WPScan non leggibile'; return $result; }
        $key = $type === 'core' ? preg_replace('/\D/', '', $slug) : $slug;
        $block = $data[$key] ?? ($data[$slug] ?? $data);
        if (!is_array($block) || !isset($block['vulnerabilities']) || !is_array($block['vulnerabilities'])) {
            $result['message'] = 'Schema WPScan non riconosciuto'; return $result;
        }
        $result['state'] = 'ok';
        $result['advisories'] = array_slice($block['vulnerabilities'], 0, 250);
        return $result;
    }

    public static function wpscan_match($version, $advisory) {
        if (!is_array($advisory) || !$version) { return null; }
        $fixed = (string) ($advisory['fixed_in'] ?? '');
        $introduced = (string) ($advisory['introduced_in'] ?? '');
        if ($introduced && version_compare($version, $introduced, '<')) { return false; }
        if ($fixed && version_compare($version, $fixed, '>=')) { return false; }
        return $fixed !== '' ? true : null;
    }

    private static function wpscan_site_findings($site, $cache) {
        $findings = array(); $checked = 0; $missing = 0;
        foreach (self::wpscan_targets($site['report'] ?? array()) as $key => $component) {
            $entry = $cache[$key] ?? array();
            if (($entry['state'] ?? '') === 'missing') { ++$missing; continue; }
            if (($entry['state'] ?? '') !== 'ok') { continue; }
            ++$checked;
            foreach ((array) ($entry['advisories'] ?? array()) as $v) {
                if (!is_array($v)) { continue; }
                $m = self::wpscan_match($component['version'], $v);
                if ($m === false) { continue; }
                $vid = self::field($v['id'] ?? ($v['title'] ?? 'non specificato'), 120);
                $links = (array) ($v['references']['url'] ?? array());
                $href = '';
                foreach ($links as $link) { if (is_string($link) && preg_match('~^https://~', $link)) { $href = esc_url_raw($link); break; } }
                $findings[] = array(
                    'id' => 'wpscan:' . sha1($key . ':' . $vid),
                    'component' => $component['name'], 'version' => $component['version'],
                    'title' => self::field($v['title'] ?? 'Advisory WPScan', 200),
                    'fixed' => self::field($v['fixed_in'] ?? '', 45),
                    'source' => $href, 'confidence' => $m === true ? 'possible' : 'review',
                );
            }
        }
        return array('findings' => array_slice($findings, 0, 100), 'checked' => $checked, 'missing' => $missing, 'total' => count(self::wpscan_targets($site['report'] ?? array())));
    }

    public static function wpscan_audit() {
        $cfg = (array) get_option(self::WPSCAN_CONFIG, array());
        if (empty($cfg['enabled']) || empty($cfg['licensed']) || empty($cfg['token'])) { return; }
        $token = self::decrypt_secret($cfg['token']);
        if (!$token) { return; }
        if (!self::acquire_lock('wpscan_run', 0)) { return; }
        try {
            $sites = self::sites();
            $cache = (array) get_option(self::WPSCAN_FEEDS, array());
            $usage = self::wpscan_usage();
            $limit = self::wpscan_per_site_limit(count($sites));
            $total = count($usage);
            $site_calls = array(); $last = array();
            foreach ($usage as $u) { $id = $u['id'] ?? ''; $site_calls[$id] = ($site_calls[$id] ?? 0) + 1; }
            foreach ($sites as $id => $site) { $last[$id] = (int) ($site['wpscan_last_call'] ?? 0); }
            $ids = array_keys($sites);
            usort($ids, function($a, $b) use ($last, $site_calls) {
                $diff = ($site_calls[$a] ?? 0) <=> ($site_calls[$b] ?? 0);
                return $diff ?: ($last[$a] <=> $last[$b]);
            });
            $made = 0;
            foreach ($ids as $id) {
                if ($total >= self::WPSCAN_LIMIT || $made >= 2) { break; }
                if (($site_calls[$id] ?? 0) >= $limit || empty($sites[$id]['report'])) { continue; }
                $candidates = self::wpscan_targets($sites[$id]['report']);
                uasort($candidates, function($a, $b) use ($cache) {
                    $ka = $a['type'] . ':' . $a['slug'];
                    $kb = $b['type'] . ':' . $b['slug'];
                    return ((int) ($cache[$ka]['at'] ?? 0)) <=> ((int) ($cache[$kb]['at'] ?? 0));
                });
                foreach ($candidates as $target) {
                    $key = $target['type'] . ':' . $target['slug'];
                    $entry = $cache[$key] ?? array();
                    $ttl = ($entry['state'] ?? '') === 'error' ? HOUR_IN_SECONDS : 3 * DAY_IN_SECONDS;
                    if (!empty($entry['at']) && time() - (int) $entry['at'] < $ttl) { continue; }
                    $usage[] = array('at' => time(), 'id' => $id);
                    update_option(self::WPSCAN_USAGE, $usage, false);
                    ++$total; ++$made;
                    $site_calls[$id] = ($site_calls[$id] ?? 0) + 1;
                    $sites[$id]['wpscan_last_call'] = time();
                    $cache[$key] = self::wpscan_fetch($target['type'], $target['slug'], $token);
                    break;
                }
            }
            $keys = array();
            foreach ($sites as $site) { foreach (self::wpscan_targets($site['report'] ?? array()) as $k => $target) { $keys[$k] = true; } }
            $cache = array_intersect_key($cache, $keys);
            if (!self::store_feed_delta(self::WPSCAN_FEEDS, $cache)) { return; }
            $site_patches = array();
            $latest_reports = (array) self::fresh_option(self::SITES, array());
            foreach ($sites as $id => &$site) {
                if (empty($site['report'])) { continue; }
                if (!isset($latest_reports[$id]) || ($latest_reports[$id]['report'] ?? array()) !== $site['report']) {
                    continue;
                }
                $summary = self::wpscan_site_findings($site, $cache);
                $site['wpscan_findings'] = $summary['findings'];
                $old = (array) ($site['wpscan_alert_ids'] ?? array());
                $current = array_column($summary['findings'], 'id');
                $fresh = array_values(array_diff($current, $old));
                if ($fresh && self::mail_config()['alerts'] && self::recipients()) {
                    $groups = array();
                    foreach ($summary['findings'] as $f) {
                        if (!in_array($f['id'], $fresh, true)) { continue; }
                        $groups[] = $f;
                    }
                    $body = self::mail_notice('DA VERIFICARE', 'WPScan ha individuato nuove segnalazioni. La corrispondenza esatta con la versione installata non è confermata: verificare prima di intervenire.', 'warn');
                    foreach (array_slice($groups, 0, 12) as $f) {
                        $body .= self::mail_component_card($f['component'], $f['version'], '', array($f), true);
                    }
                    if (count($groups) > 12) { $body .= self::mail_p('Altre ' . (count($groups) - 12) . ' segnalazioni: consulta Sentinel.'); }
                    $body .= self::mail_cta('Apri la scheda sito', self::sn_admin_url($id));
                    if (self::send_html('[Integra Sentinel] WPScan: nuove segnalazioni da verificare · ' . $site['label'],
                        self::mail_shell('Segnalazioni supplementari', $site['label'] . ' · ' . count($fresh) . ' nuove evidenze', $body, 'warn'))) {
                        $site['wpscan_alert_ids'] = array_values(array_unique(array_merge($old, $current)));
                    }
                } else {
                    $site['wpscan_alert_ids'] = array_values(array_unique(array_merge($old, $current)));
                }
            }
            unset($site);
            foreach ($sites as $id => $snapshot) {
                if (!empty($snapshot['report'])) {
                    $site_patches[$id] = array(
                        'report' => $snapshot['report'],
                        'wpscan_last_call' => $snapshot['wpscan_last_call'] ?? 0,
                        'wpscan_findings' => $snapshot['wpscan_findings'] ?? array(),
                        'wpscan_alert_ids' => $snapshot['wpscan_alert_ids'] ?? array(),
                    );
                }
            }
            if (!self::mutate_sites(function($current) use ($site_patches) {
                foreach ($site_patches as $id => $patch) {
                    if (!isset($current[$id])) { continue; }
                    $current[$id]['wpscan_last_call'] = max((int) ($current[$id]['wpscan_last_call'] ?? 0), (int) $patch['wpscan_last_call']);
                    if (($current[$id]['report'] ?? array()) !== $patch['report']) { continue; }
                    $current[$id]['wpscan_findings'] = $patch['wpscan_findings'];
                    $current[$id]['wpscan_alert_ids'] = array_values(array_unique(array_merge(
                        (array) ($current[$id]['wpscan_alert_ids'] ?? array()), (array) $patch['wpscan_alert_ids'])));
                }
                return $current;
            })) { error_log('Integra Sentinel: cannot commit WPScan result'); }
        } finally {
            self::release_lock('wpscan_run');
        }
    }

    public static function wpscan_settings($sites, $return = '') {
        $cfg = (array) get_option(self::WPSCAN_CONFIG, array());
        $usage = self::wpscan_usage();
        $site_limit = self::wpscan_per_site_limit(count($sites));
        if (!current_user_can('manage_options')) {
            echo '<div class="is-panel sn-wpscan"><h4>WPScan · controllo supplementare</h4><p class="sn-hint">Stato: <strong>' . (!empty($cfg['enabled']) ? 'Abilitato' : 'Disabilitato') . '</strong>. Modificabile dagli amministratori.</p></div>';
            return;
        }
        echo '<div class="is-panel sn-wpscan"><h4>WPScan · controllo supplementare opzionale</h4>';
        echo '<p>WPVulnerability resta sempre attivo e controlla i componenti senza quote WPScan. Il piano WPScan gratuito e destinato a usi non commerciali: per l\'assistenza ai clienti occorre una licenza Enterprise/autorizzazione adeguata. Il token viene cifrato e non viene mai mostrato nuovamente.</p>';
        echo '<p><strong>Budget WPScan:</strong> ' . count($usage) . '/' . self::WPSCAN_LIMIT . ' richieste nelle ultime 24 ore · <strong>' . $site_limit . '</strong> richiesta/e al giorno per sito (meno di 13 siti: 2; da 13: 1), entro il limite globale. Con oltre 25 siti Sentinel ruota le verifiche nei giorni successivi. Ogni richiesta WPScan controlla un componente, non un intero sito.</p>';
        self::post_form_start($return);
        echo '<input type="hidden" name="cmd" value="wpscan">';
        echo '<p><label><input type="checkbox" name="wpscan_enabled" value="1" ' . checked(!empty($cfg['enabled']), true, false) . '> Abilita il secondo controllo WPScan</label></p>';
        echo '<p><label>Token WPScan (lasciare vuoto per conservare quello gia salvato)<br><input type="password" class="regular-text" name="wpscan_token" value="" autocomplete="new-password" placeholder="' . (!empty($cfg['token']) ? 'Token già configurato' : 'Inserisci token Enterprise') . '"></label></p>';
        echo '<p><label><input type="checkbox" name="wpscan_licensed" value="1" ' . checked(!empty($cfg['licensed']), true, false) . '> Confermo che la mia azienda dispone di un diritto d\'uso commerciale/Enterprise appropriato per questa integrazione.</label></p>';
        echo '<p><label><input type="checkbox" name="wpscan_remove_token" value="1"> Elimina il token salvato</label></p>';
        submit_button('Salva impostazioni WPScan', 'secondary', 'submit', false);
        echo '</form><p class="is-small">Stato: <strong>' . (!empty($cfg['enabled']) ? 'Abilitato' : 'Disabilitato') . '</strong>. La cache dei risultati e condivisa tra tutti i siti. La finestra mobile di 24 ore evita sforamenti anche con controlli manuali e cron.</p></div>';
    }

    public static function dependency_settings_form($return = '') {
        $cfg = self::dependency_settings();
        $rules = array(); $catalog = array();
        foreach ($cfg['rules'] as $rule) { if (is_array($rule) && isset($rule[0], $rule[1])) { $rules[] = $rule[0] . ' > ' . $rule[1]; } }
        foreach ($cfg['catalog'] as $slug => $entry) { $catalog[] = $slug . ' | ' . ($entry['version'] ?? '') . (!empty($entry['url']) ? ' | ' . $entry['url'] : ''); }
        echo '<div class="sn-panel is-panel sn-deps-settings" id="sn-deps-settings"><h3>Dipendenze e catalogo premium</h3>';
        echo '<p>Sentinel riconosce da solo i nuclei principali (WooCommerce, Elementor, ACF, WPML, Gravity Forms e altri) tramite header dichiarati, codice e nome. Qui puoi aggiungere collegamenti che solo voi conoscete e le ultime versioni dei plugin e temi premium senza aggiornamento automatico. Con il collegamento Envato le versioni dei prodotti ThemeForest/CodeCanyon arrivano da sole; il catalogo manuale prevale.</p>';
        self::post_form_start($return);
        echo '<input type="hidden" name="cmd" value="deps"><div class="sn-two">';
        echo '<div><label for="sn-dep-rules">Regole di dipendenza (una per riga: <code>addon-slug &gt; plugin-principale-slug</code>)</label><textarea id="sn-dep-rules" class="large-text code" rows="7" name="dep_rules" spellcheck="false" placeholder="# esempio&#10;mio-addon-checkout &gt; woocommerce&#10;widget-personalizzati &gt; elementor-pro">' . esc_textarea(implode("\n", $rules)) . '</textarea></div>';
        echo '<div><label for="sn-dep-catalog">Catalogo premium (una per riga: <code>slug | ultima versione | URL fornitore</code>)</label><textarea id="sn-dep-catalog" class="large-text code" rows="7" name="dep_catalog" spellcheck="false" placeholder="# esempio&#10;revslider | 6.7.25 | https://www.sliderrevolution.com/changelog/&#10;js_composer | 8.1">' . esc_textarea(implode("\n", $catalog)) . '</textarea></div>';
        echo '</div>';
        submit_button('Salva dipendenze e catalogo', 'secondary', 'submit', false);
        echo '</form><p class="is-small">Lo slug è il nome della cartella del plugin. Le versioni viste su altri siti del parco vengono già usate in automatico per i plugin fuori libreria.</p></div>';
    }

    private static function wpscan_detail($site) {
        $cfg = (array) get_option(self::WPSCAN_CONFIG, array());
        $cache = (array) get_option(self::WPSCAN_FEEDS, array());
        $summary = self::wpscan_site_findings($site, $cache);
        echo '<div class="is-panel"><h2>Secondo controllo · WPScan</h2>';
        if (empty($cfg['enabled'])) { echo '<p>Disabilitato. Si puo attivare nella dashboard del Master con licenza commerciale appropriata.</p></div>'; return; }
        $errors = array();
        foreach (self::wpscan_targets($site['report'] ?? array()) as $key => $item) {
            if (($cache[$key]['state'] ?? '') === 'error') { $errors[] = $item['name'] . ': ' . ($cache[$key]['message'] ?? 'Errore API'); }
        }
        echo '<p><strong>Componenti interrogati:</strong> ' . $summary['checked'] . '/' . $summary['total'] . ' · Non censiti: ' . $summary['missing'] . ' · Errori API: ' . count($errors) . '. Le interrogazioni sono distribuite nel tempo per rispettare il budget e i risultati non coprono ancora necessariamente tutti i componenti.</p>';
        $wp_version = (string) ($site['report']['wp'] ?? '');
        if ($wp_version !== '' && !preg_match('/^\d+(?:\.\d+){1,3}$/', $wp_version)) {
            echo '<p class="is-pill is-yellow">WordPress ' . self::e($wp_version) . ': versione prerelease o non standard, non interrogabile automaticamente nell’API WPScan. Controllare manualmente il Core; questo risultato non è una verifica di sicurezza.</p>';
        }
        if ($errors) { echo '<p class="is-pill is-yellow">Errori di collegamento: ' . self::e(implode('; ', array_slice($errors, 0, 4))) . '</p>'; }
        if (!$summary['findings']) { echo '<p>Nessuna segnalazione WPScan nelle verifiche effettuate. Non significa assenza di vulnerabilita.</p></div>'; return; }
        echo '<table class="is-table"><thead><tr><th>Componente</th><th>Segnalazione</th><th>Intervento</th><th>Fonte</th></tr></thead><tbody>';
        foreach ($summary['findings'] as $f) {
            echo '<tr><td>' . self::e($f['component']) . ' ' . self::e($f['version']) . '</td><td>' . self::e($f['title']) . '</td><td>';
            echo ($f['confidence'] === 'possible' ? '<span class="is-pill is-yellow">Possibile versione interessata</span>' : '<span class="is-pill is-grey">Intervallo non verificabile</span>');
            if ($f['fixed']) { echo '<div class="is-muted">Versione corretta indicata: ' . self::e($f['fixed']) . '</div>'; }
            echo '</td><td>' . ($f['source'] ? '<a href="' . esc_url($f['source']) . '" target="_blank" rel="noopener noreferrer">Advisory ↗</a>' : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Rich HTML emails: table-based and inline styles for Gmail, Outlook and mobile. */
    public static function send_html($subject, $html) {
        $to = self::recipients();
        if (!$to) { return false; }
        return (bool) wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
    }

    public static function mail_p($text) {
        return '<p style="margin:10px 0;color:#344354;font-size:14px;line-height:1.65">' . self::e($text) . '</p>';
    }

    private static function mail_color($tone) {
        $palette = array('danger'=>'#c73648','critical'=>'#c73648','risk'=>'#dc7936','warn'=>'#b7791f',
            'review'=>'#bd8227','offline'=>'#677587','clear'=>'#138364','success'=>'#138364','brand'=>'#0c8997',
            'high'=>'#db6c40','medium'=>'#b7791f','low'=>'#138364','unknown'=>'#64748b');
        return $palette[$tone] ?? '#18344e';
    }

    public static function mail_shell($headline, $subtitle, $body, $tone = 'brand') {
        $accent = self::mail_color($tone);
        return '<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
            '<body style="margin:0;background:#eef3f8;color:#1a3045;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#eef3f8"><tr><td align="center" style="padding:28px 12px">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" width="640" style="max-width:640px;width:100%;border-collapse:separate;border-spacing:0;background:#ffffff;border:1px solid #dae5ee;border-radius:12px;overflow:hidden">' .
            '<tr><td style="background:#12283f;padding:22px 26px;color:#ffffff"><table role="presentation" width="100%"><tr><td style="font-size:20px;font-weight:bold;letter-spacing:-.35px;color:white">' .
            '<span style="display:inline-block;border-radius:7px;padding:5px 8px;background:#168b9b;margin-right:8px">◆</span> INTEGRA <span style="color:#8cdeeb">SENTINEL</span></td>' .
            '<td align="right" style="font-size:10px;color:#c8d6e2;letter-spacing:1.2px">SECURITY &amp; INTEGRITY</td></tr></table></td></tr>' .
            '<tr><td style="height:5px;background:' . $accent . ';font-size:0">&nbsp;</td></tr>' .
            '<tr><td style="padding:28px 27px 14px"><p style="font-size:11px;color:#73879a;letter-spacing:1.3px;font-weight:bold;margin:0 0 9px">REPORT · ' . self::e(wp_date('d/m/Y · H:i')) . '</p>' .
            '<h1 style="font-size:26px;line-height:1.17;letter-spacing:-.6px;margin:0 0 10px;color:#18334b">' . self::e($headline) . '</h1>' .
            '<p style="font-size:14px;line-height:1.55;color:#587083;margin:0 0 4px">' . self::e($subtitle) . '</p></td></tr>' .
            '<tr><td style="padding:0 27px 30px">' . $body . '</td></tr>' .
            '<tr><td style="background:#f5f8fb;border-top:1px solid #e3eaf1;padding:19px 27px;color:#60778b;font-size:11px;line-height:1.6">' .
            '<strong style="color:#294659">Integra Sentinel v' . self::e(self::VERSION) . '</strong> · Monitoraggio dei siti WordPress gestiti da Integra<br>' .
            'Le segnalazioni riguardano vulnerabilità note nei database consultati e telemetria di integrità sui punti critici. ' .
            'Gli orari sono espressi nel fuso configurato su WordPress: ' . self::e(wp_timezone_string()) . '.<br>' .
            '<a href="https://integracorporate.it/" style="color:#0c8997;text-decoration:none">integracorporate.it</a>' .
            '</td></tr></table></td></tr></table></body></html>';
    }

    public static function mail_section($title, $sub = '') {
        return '<div style="margin-top:24px;border-bottom:1px solid #e4ecf2;padding-bottom:9px">' .
            '<strong style="font-size:17px;color:#19364e">' . self::e($title) . '</strong>' .
            ($sub ? '<p style="margin:5px 0 0;color:#718294;font-size:12px;line-height:1.5">' . self::e($sub) . '</p>' : '') . '</div>';
    }

    public static function mail_notice($label, $message, $tone = 'brand') {
        $accent = self::mail_color($tone);
        $background = $tone === 'clear' || $tone === 'success' ? '#ecf9f3' : ($tone === 'danger' || $tone === 'critical' ? '#fff0f1' : '#fffaee');
        return '<table role="presentation" width="100%" style="border-collapse:collapse;margin:16px 0"><tr><td style="border-left:4px solid ' . $accent . ';background:' . $background . ';padding:16px 17px">' .
            '<div style="font-size:11px;letter-spacing:1px;font-weight:bold;color:' . $accent . '">' . self::e($label) . '</div>' .
            '<div style="font-size:14px;line-height:1.6;color:#294258;margin-top:7px">' . self::e($message) . '</div></td></tr></table>';
    }

    public static function mail_cta($label, $url) {
        return '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0 8px"><tr><td bgcolor="#16889a" style="border-radius:6px;padding:12px 19px">' .
            '<a style="font-size:13px;text-decoration:none;color:#fff;font-weight:bold" href="' . esc_url($url) . '">' . self::e($label) . ' &rarr;</a>' .
            '</td></tr></table>';
    }

    private static function mail_metrics($blocks) {
        $html = '<table role="presentation" width="100%" cellspacing="6" cellpadding="0" style="margin:12px 0 18px"><tr>';
        foreach ($blocks as $x) {
            $html .= '<td width="25%" valign="top" style="border:1px solid #e2eaf1;background:#f7fafc;border-radius:8px;padding:13px 9px;vertical-align:top">' .
                '<div style="font-size:27px;font-weight:bold;color:' . self::mail_color($x['tone'] ?? 'brand') . ';line-height:1.2">' . self::e($x['value']) . '</div>' .
                '<div style="font-size:11px;line-height:1.45;color:#50677b;margin-top:5px">' . self::e($x['label']) . '</div></td>';
        }
        return $html . '</tr></table>';
    }

    private static function mail_distribution($statuses) {
        $labels = array('critical'=>'Critici','risk'=>'Con vulnerabilità','review'=>'Da verificare','clear'=>'Senza note','offline'=>'Offline');
        $total = array_sum($statuses);
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:15px"><tr>';
        if ($total) {
            foreach ($labels as $key => $label) {
                $n = (int) ($statuses[$key] ?? 0);
                if (!$n) { continue; }
                $html .= '<td bgcolor="' . self::mail_color($key) . '" width="' . round(100 * $n / $total, 2) . '%' . '" style="height:14px;font-size:0">&nbsp;</td>';
            }
        } else { $html .= '<td bgcolor="#d8e3eb" style="height:14px;font-size:0">&nbsp;</td>'; }
        $html .= '</tr></table><div style="margin-top:10px;font-size:11px;color:#53697c;line-height:2">';
        foreach ($labels as $key => $label) {
            $html .= '<span style="white-space:nowrap;display:inline-block;margin-right:12px"><span style="color:' . self::mail_color($key) . '">&#9632;</span> ' . self::e($label) . ' <strong>' . (int) ($statuses[$key] ?? 0) . '</strong></span>';
        }
        return $html . '</div>';
    }

    private static function mail_component_card($name, $version, $update, $items, $secondary = false, $compatibility = '') {
        $max = null; $unknown = 0; $fixed = array();
        foreach ($items as $f) {
            if (isset($f['score']) && is_numeric($f['score'])) { $score = (float) $f['score']; $max = $max === null ? $score : max($max, $score); }
            else { ++$unknown; }
            if (!empty($f['fixed'])) { $fixed[$f['fixed']] = true; }
        }
        $tier = self::sn_tier($max); $color = self::mail_color($tier);
        $html = '<table role="presentation" width="100%" style="border-collapse:collapse;margin:12px 0;border:1px solid #e1eaf0;border-left:4px solid ' . $color . ';border-radius:6px"><tr><td style="padding:15px 16px">';
        $html .= '<table role="presentation" width="100%"><tr><td valign="top"><div style="font-size:15px;font-weight:bold;color:#1b3b53">' . self::e($name) . '</div><div style="color:#697e90;font-size:12px;margin-top:5px">' .
            'Installata: <strong>' . self::e($version ?: 'non disponibile') . '</strong>' . ($update ? ' &nbsp;&rarr;&nbsp; Proposta: <strong>' . self::e($update) . '</strong>' : '') . '</div></td>' .
            '<td align="right" valign="top" style="font-size:12px;color:' . $color . ';font-weight:bold;white-space:nowrap">' . count($items) . ' ' . (count($items) === 1 ? 'avviso' : 'avvisi') . '<br>' .
            ($max !== null ? number_format($max, 1, ',', '') . ' CVSS' : 'CVSS n.d.') . '</td></tr></table>';
        $limit = $secondary ? 3 : 4;
        foreach (array_slice($items, 0, $limit) as $f) {
            $html .= '<div style="margin-top:10px;padding-top:9px;border-top:1px solid #eef2f6">' .
                '<div style="font-size:12px;color:#28485f;font-weight:bold">' . self::e($f['title'] ?? 'Vulnerabilità nota') . '</div>' .
                '<div style="font-size:11px;line-height:1.6;color:#61778a;margin-top:3px">' .
                (!empty($f['reference']) ? self::e($f['reference']) . ' · ' : '') .
                'Intervallo: ' . self::e($f['range'] ?? 'non disponibile') .
                (!empty($f['fixed']) ? ' · Soglia corretta indicata: ' . self::e($f['fixed']) : (!empty($f['unfixed']) ? ' · Nessuna patch indicata' : '')) .
                '</div>' . (!empty($f['source']) ? '<a href="' . esc_url($f['source']) . '" style="font-size:11px;color:#0b8b9c;text-decoration:underline">Fonte e dettagli ↗</a>' : '') . '</div>';
        }
        if (count($items) > $limit) { $html .= '<p style="font-size:12px;color:#50677a">+' . (count($items) - $limit) . ' advisory aggiuntivi consultabili nel Master.</p>'; }
        if (!$secondary) {
            $tip = $update ? 'Verificare prerequisiti e compatibilità prima di aggiornare alla versione proposta. Non presumere che risolva ogni advisory senza confrontare le soglie.' :
                'Verificare una release corretta presso lo sviluppatore e definire un intervento di mitigazione.';
            if (!empty($fixed)) { $tip .= ' Soglie patch documentate: ' . implode(', ', array_slice(array_keys($fixed), 0, 4)) . '.'; }
            $html .= '<p style="font-size:12px;line-height:1.5;color:#2b5360;margin:11px 0 0"><strong>Azione:</strong> ' . self::e($tip) . '</p>';
            if ($compatibility) { $html .= '<p style="padding:9px 10px;border-radius:4px;background:#fff7e4;font-size:12px;line-height:1.55;color:#7c5417"><strong>Compatibilità:</strong> ' . self::e($compatibility) . '</p>'; }
        }
        return $html . '</td></tr></table>';
    }

    private static function mail_plugin_compatibility($site, $group) {
        if ($group['type'] !== 'plugin') { return ''; }
        $report = $site['report'] ?? array();
        foreach ((array) ($report['plugins'] ?? array()) as $p) {
            if (($p['slug'] ?? '') !== $group['slug']) { continue; }
            $a = self::update_assessment($p, $report);
            if (($a['class'] ?? 'green') === 'green') { return ''; }
            return implode(' · ', array_slice((array) ($a['notes'] ?? array()), 0, 3));
        }
        return '';
    }

    private static function mail_plugin_update($site, $group) {
        $r = $site['report'] ?? array();
        if ($group['type'] === 'core') { return $r['core_update'] ?? ''; }
        foreach ((array) ($r[$group['type'] === 'theme' ? 'themes' : 'plugins'] ?? array()) as $p) {
            if (($p['slug'] ?? '') === $group['slug']) { return $group['type'] === 'theme' ? ($p['update'] ?? '') : ($p['update']['version'] ?? ''); }
        }
        return '';
    }

    private static function mail_digest_data() {
        $sites = self::sites(); $cache = (array) get_option(self::FEEDS, array());
        $rows = array(); $state = array('critical'=>0,'risk'=>0,'review'=>0,'clear'=>0,'offline'=>0);
        $updates = 0; $advisories = 0; $affected = 0; $vulnerable_sites = 0; $potential = 0; $integrity_total = 0; $hotspots = array(); $complete = count($sites) > 0;
        foreach ($sites as $id => $site) {
            $m = self::sn_metrics($site, $cache);
            $fresh_report = !empty($site['last_seen']) && time() - (int) $site['last_seen'] <= DAY_IN_SECONDS;
            $cov = $m['coverage'];
            $complete_site = $fresh_report && !$m['offline'] && !$m['integrity_count'] && $cov['total'] > 0 && $cov['ok'] === $cov['total'] && !$cov['bad'] && !$cov['waiting'] && !$cov['stale'] && !$cov['identity_review'] && empty($site['feed_review']) && empty($site['possible_findings']);
            $complete = $complete && $complete_site;
            $rows[] = array('id'=>$id,'site'=>$site,'m'=>$m,'complete'=>$complete_site);
            ++$state[$m['state']]; $updates += $m['updates']; $advisories += $m['vulnerabilities']; $affected += $m['affected']; $potential += $m['possible_count']; $integrity_total += $m['integrity_count'];
            if ($m['affected'] || $m['integrity_count']) { ++$vulnerable_sites; }
            foreach ($m['groups'] as $g) {
                $key = $g['key'];
                if (!isset($hotspots[$key])) { $hotspots[$key] = array('name'=>$g['name'],'sites'=>array(),'count'=>0,'tier'=>'unknown'); }
                $hotspots[$key]['sites'][$id] = $site['label']; $hotspots[$key]['count'] += $g['count'];
                if (self::sn_rank($g['max_tier']) > self::sn_rank($hotspots[$key]['tier'])) { $hotspots[$key]['tier'] = $g['max_tier']; }
            }
        }
        usort($rows, function($a, $b) {
            $x = $a['m']; $y = $b['m'];
            $rx = $x['state'] === 'offline' ? 4 : $x['rank']; $ry = $y['state'] === 'offline' ? 4 : $y['rank'];
            return ($ry <=> $rx) ?: ($y['integrity_count'] <=> $x['integrity_count']) ?: ($y['affected'] <=> $x['affected']) ?: strcasecmp($a['site']['label'], $b['site']['label']);
        });
        uasort($hotspots, function($a, $b) {
            return (count($b['sites']) <=> count($a['sites'])) ?: (self::sn_rank($b['tier']) <=> self::sn_rank($a['tier'])) ?: ($b['count'] <=> $a['count']);
        });
        return compact('sites','rows','state','updates','advisories','affected','vulnerable_sites','potential','integrity_total','hotspots','complete');
    }

    /** Sezioni del report giornaliero: colli di bottiglia del parco, licenze in scadenza, raggiungibilita'. */
    private static function digest_bottlenecks_html() {
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $k = $fleet['kpi'];
        $owners = Integra_Sentinel_Fleet::owners();
        $html = self::mail_section('Aggiornamenti: cosa si può fare e cosa è bloccato', 'Una riga per causa e prodotto: un’azione può sbloccare più siti.');
        $html .= self::mail_metrics(array(
            array('value' => $k['ready'], 'label' => 'Aggiornabili subito', 'tone' => 'clear'),
            array('value' => $k['blocked'], 'label' => 'Aggiornamenti bloccati', 'tone' => $k['blocked'] ? 'danger' : 'clear'),
            array('value' => $k['license'], 'label' => 'Licenze da sistemare', 'tone' => $k['license'] ? 'warn' : 'clear'),
            array('value' => $k['manual'] + $k['blind'], 'label' => 'Premium manuali o ciechi', 'tone' => ($k['manual'] + $k['blind']) ? 'warn' : 'clear'),
        ));
        foreach (Integra_Sentinel_Fleet::top_groups($fleet, 6) as $g) {
            $html .= '<div style="border-bottom:1px solid #ecf0f4;padding:9px 2px;font-size:13px;color:#344f62"><strong>' . self::e($g['name']) . '</strong> · ' . self::e(Integra_Sentinel_Fleet::kind_label($g['kind']))
                . '<span style="float:right;font-size:11px;color:#5d7083">' . count($g['sites']) . ' ' . (count($g['sites']) === 1 ? 'sito' : 'siti') . ' · ' . self::e($owners[$g['owner']] ?? 'Agenzia') . '</span>'
                . '<div style="font-size:11px;color:#6b8092;margin-top:4px">' . self::e($g['action']) . '</div></div>';
        }
        if (!$fleet['groups']) { $html .= self::mail_p('Nessun collo di bottiglia nei dati disponibili.'); }
        $html .= self::mail_cta('Apri i colli di bottiglia', admin_url('admin.php?page=integra-sentinel-bottlenecks'));
        $expiring = Integra_Sentinel_Licenses::expiring(30);
        if ($expiring) {
            $html .= self::mail_section('Licenze in scadenza', 'Entro 30 giorni o scadute da poco.');
            foreach (array_slice($expiring, 0, 8) as $lic) {
                $html .= self::mail_p($lic['product'] . ': ' . ($lic['days'] < 0 ? 'scaduta il ' : 'scade il ') . wp_date('d/m/Y', strtotime($lic['expires'] . ' 12:00:00')) . ' · paga ' . ($lic['payer'] === 'cliente' ? 'il cliente' : 'l’agenzia'));
            }
        }
        $down = array();
        $sites = self::sites();
        foreach (Integra_Sentinel_Probe::state() as $sid => $s) {
            if (!empty($s['down_since']) && isset($sites[$sid])) { $down[] = $sites[$sid]['label'] . ' (dal ' . wp_date('d/m H:i', (int) $s['down_since']) . ')'; }
        }
        if ($down) {
            $html .= self::mail_section('Siti con problemi di raggiungibilità');
            $html .= self::mail_p(implode(' · ', $down));
        }
        return $html;
    }

    private static function digest_html($test = false) {
        $d = self::mail_digest_data(); $count = count($d['sites']);
        $has_findings = $d['advisories'] > 0 || $d['integrity_total'] > 0;
        $potential = $d['potential'];
        $wpscan_unresolved = false;
        foreach ($d['rows'] as $entry) { if (!empty($entry['m']['wpscan'])) { $wpscan_unresolved = true; } }
        $oldphp = false;
        foreach ($d['rows'] as $item) {
            $php = $item['site']['report']['php'] ?? '';
            if ($php && Integra_Sentinel_EOL::evaluate('php', $php)['state'] === 'eol') { $oldphp = true; }
        }
        $all_clear = $d['complete'] && !$has_findings && !$potential && !$oldphp && !$wpscan_unresolved;
        $tone = $all_clear ? 'clear' : ($has_findings ? 'danger' : 'warn');
        $headline = $all_clear ? 'Nessuna vulnerabilità o anomalia rilevata' : ($has_findings ? 'Situazione di sicurezza: interventi richiesti' : 'Stato dei siti: verifica da completare');
        $subtitle = ($test ? 'ANTEPRIMA EMAIL · ' : '') . 'Riepilogo giornaliero dei siti WordPress gestiti · ' . wp_date('l d F Y');
        $html = self::mail_metrics(array(
            array('value'=>$count,'label'=>'Siti registrati','tone'=>'brand'),
            array('value'=>$d['vulnerable_sites'],'label'=>'Siti a rischio','tone'=>$d['vulnerable_sites'] ? 'danger' : 'clear'),
            array('value'=>$d['affected'] + $d['integrity_total'],'label'=>'Componenti / Anomalie','tone'=>($d['affected'] + $d['integrity_total']) ? 'danger' : 'clear'),
            array('value'=>$d['updates'],'label'=>'Aggiornamenti disponibili','tone'=>'warn'),
        ));
        if ($all_clear) {
            $html .= self::mail_notice('ATTESTAZIONE DI MONITORAGGIO',
                'Alla data e all’ora indicate, i siti registrati hanno inventari recenti, integrità file confermata e interrogazioni dei componenti complete; non sono risultate vulnerabilità note applicabili alle versioni esaminate.', 'clear');
        } elseif ($has_findings) {
            $html .= self::mail_notice('INTERVENTO CONSIGLIATO', $d['advisories'] . ' advisory CVE e ' . $d['integrity_total'] . ' segnali di integrità coinvolgono ' . $d['vulnerable_sites'] . ' siti. Valuta priorità, integrità file e backup prima di applicare modifiche.', 'danger');
        } else {
            $html .= self::mail_notice('COPERTURA DA COMPLETARE',
                'Non risultano vulnerabilità confermate nei controlli disponibili, ma vi sono ' . (int) $potential . ' potenziali corrispondenze di plugin con identità da verificare, oppure controlli incompleti.', 'warn');
        }
        $html .= self::mail_section('Distribuzione dei siti', 'Un sito è classificato per il suo stato operativo più urgente.');
        $html .= self::mail_distribution($d['state']);
        $html .= self::mail_section('Situazione per sito', 'Le vulnerabilità sono conteggiate per componenti distinti; le anomalie di integrità file/DB sono evidenziate prioritariamente.');
        foreach ($d['rows'] as $entry) {
            $site = $entry['site']; $m = $entry['m'];
            $color = self::mail_color($m['state']);
            $label = self::sn_state_label($m['state']);
            $seen = !empty($site['last_seen']) ? wp_date('d/m H:i', (int) $site['last_seen']) : 'mai';
            $coverage = $m['coverage'];
            $html .= '<table role="presentation" width="100%" style="border-collapse:collapse;margin-top:10px"><tr><td style="border:1px solid #e2eaf1;border-left:4px solid ' . $color . ';padding:13px 14px;border-radius:5px">';
            $html .= '<table role="presentation" width="100%"><tr><td valign="top"><a style="color:#173e57;font-weight:bold;font-size:14px;text-decoration:none" href="' . esc_url(self::sn_admin_url($entry['id'])) . '">' . self::e($site['label']) . ' &rarr;</a>' .
                '<div style="font-size:11px;color:#76899a;margin-top:5px">' . self::e($site['url']) . ' · Ultimo inventario: ' . self::e($seen) . '</div></td>' .
                '<td valign="top" align="right" style="font-size:12px;font-weight:bold;color:' . $color . '">' . self::e($label) . '</td></tr></table>';
            $html .= '<div style="margin-top:10px;font-size:12px;line-height:1.6;color:#415d73"><b>' . $m['affected'] . '</b> componenti vulnerabili &nbsp;·&nbsp; <b>' . $m['integrity_count'] . '</b> anomalie integrità &nbsp;·&nbsp; <b>' . $m['updates'] . '</b> aggiornamenti &nbsp;·&nbsp; API <b>' . $coverage['ok'] . '/' . $coverage['total'] . '</b></div>';
            if ($m['possible_count'] || $coverage['identity_review']) { $html .= '<div style="font-size:11px;color:#a86d21;margin-top:5px">' . (int) $m['possible_count'] . ' corrispondenze potenziali; ' . (int) $coverage['identity_review'] . ' plugin con identità esterna non confermata.</div>'; }
            if (!$entry['complete']) {
                $html .= '<div style="font-size:11px;color:#a86d21;margin-top:5px">Copertura incompleta, privata o dati non recenti: verificare nella dashboard.</div>';
            }
            if (!empty($m['wpscan'])) { $html .= '<div style="font-size:11px;color:#a86d21;margin-top:5px">WPScan: ' . (int) $m['wpscan'] . ' segnalazioni supplementari non confermate, da verificare.</div>'; }
            $issues = self::sn_site_issues($site, $m);
            if ($issues) { $html .= '<div style="font-size:12px;color:#9b5928;margin-top:5px">' . self::e(implode(' · ', array_slice($issues, 0, 3))) . '</div>'; }
            $html .= '</td></tr></table>';
        }
        if ($d['hotspots']) {
            $html .= self::mail_section('Plugin e componenti prioritari', 'I cinque componenti più diffusi tra i siti vulnerabili.');
            foreach (array_slice($d['hotspots'], 0, 5) as $h) {
                $html .= '<div style="border-bottom:1px solid #ecf0f4;padding:10px 2px;font-size:13px;color:#344f62">' .
                    '<strong>' . self::e($h['name']) . '</strong><span style="float:right;color:' . self::mail_color($h['tier']) . ';font-weight:bold">' . self::e(self::sn_severity($h['tier'])) . '</span>' .
                    '<div style="font-size:11px;color:#6b8092;margin-top:5px">' . count($h['sites']) . ' siti coinvolti · ' . $h['count'] . ' advisory</div></div>';
            }
        }
        $html .= self::digest_bottlenecks_html();
        $html .= self::mail_section('Interpretazione dei dati');
        $html .= self::mail_p('Un risultato pulito riguarda soltanto i componenti verificati, i database consultati e i file critici monitorati. Plugin custom, controlli falliti e siti senza inventari recenti devono essere valutati separatamente.');
        $html .= self::mail_cta('Apri la dashboard completa', self::sn_admin_url());
        return self::mail_shell($headline, $subtitle, $html, $tone);
    }

    /** Report di prova (WP-CLI e pulsante nelle impostazioni). */
    public static function send_test_digest() {
        return self::send_report_email(true);
    }

    private static function send_report_email($test = false) {
        $html = self::digest_html($test);
        $subject = '[Integra Sentinel] ' . ($test ? 'TEST · ' : '') . 'Report sicurezza del ' . wp_date('d/m/Y');
        return self::send_html($subject, $html);
    }

    public static function send_daily_digest() {
        self::ensure_digest_schedule();
        if (!self::acquire_lock('digest_delivery', 0)) { return; }
        try {
            $config = self::mail_config();
            if (empty($config['daily'])) { return; }
            $local_date = wp_date('Y-m-d');
            $status = (array) self::fresh_option(self::MAIL_STATUS, array());
            if (!empty($status['ok']) && ($status['date'] ?? '') === $local_date) { return; }
            $ok = self::send_report_email(false);
            $attempts = ((($status['date'] ?? '') === $local_date) ? (int) ($status['attempts'] ?? 0) : 0) + 1;
            self::persist_locked_option(self::MAIL_STATUS,
                array('sent_at' => time(), 'date' => $local_date, 'ok' => $ok, 'attempts' => $attempts));
            if (!$ok && $attempts < 3 && !wp_next_scheduled(self::HOOK_RETRY)) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, self::HOOK_RETRY);
            }
        } finally { self::release_lock('digest_delivery'); }
    }

    /** Mail I/O never holds the sites DB lock; acknowledge only exact IDs sent. */
    public static function dispatch_alerts() {
        if (!self::acquire_lock('alert_delivery', 0)) { return; }
        try {
            $cfg = self::mail_config();
            $sites = self::sites();
            $ack = array(); $retry = false; $fresh_confirmed = array();
            foreach ($sites as $id => $site) {
                // 1. Alert immediati di Integrità File / DB / Rogue Admin (Zero-Noise).
                $int_queue = (array) ($site['integrity_pending_ids'] ?? array());
                if ($int_queue) {
                    $int_current = array();
                    foreach ((array) ($site['integrity_findings'] ?? array()) as $if) {
                        if (!empty($if['id'])) { $int_current[$if['id']] = $if; }
                    }
                    $int_fresh = array(); $int_stale = array();
                    foreach ($int_queue as $ikey) {
                        if (isset($int_current[$ikey])) { $int_fresh[] = $int_current[$ikey]; }
                        else { $int_stale[] = $ikey; }
                    }
                    if ($int_stale) { $ack[$id]['integrity']['discard'] = $int_stale; }
                    if ($int_fresh) {
                        $int_sent = array_column($int_fresh, 'id');
                        $lines = array();
                        foreach (array_slice($int_fresh, 0, 6) as $if) { $lines[] = ($if['category'] ?? 'Anomalia') . ': ' . ($if['detail'] ?? ''); }
                        $hook = Integra_Sentinel_Notify::send('integrity', 'Integrità / intrusione · ' . $site['label'], implode("\n", $lines), self::sn_admin_url($id));
                        if (empty($cfg['alerts'])) {
                            $ack[$id]['integrity']['sent'] = $int_sent;
                        } else {
                            $body = self::mail_notice('ALLARME INTEGRITÀ / INTRUSIONE',
                                'Il Client di ' . $site['label'] . ' ha rilevato modifiche critiche ai file, eseguibili in uploads, pattern sospetti nel DB o nuovi amministratori fuori dalle procedure di aggiornamento.', 'danger');
                            foreach (array_slice($int_fresh, 0, 20) as $if) {
                                $body .= '<div style="margin:10px 0;padding:12px 14px;border:1px solid #f5c6cb;border-left:4px solid #c73648;background:#fff8f9;border-radius:5px">' .
                                    '<div style="font-size:12px;font-weight:bold;color:#921929">' . self::e($if['category'] ?? 'Anomalia') . '</div>' .
                                    '<div style="font-size:13px;color:#294258;margin-top:4px"><code>' . self::e($if['detail'] ?? '') . '</code></div></div>';
                            }
                            $body .= self::mail_cta('Verifica subito nel Master', self::sn_admin_url($id));
                            $mail_html = self::mail_shell('Anomalia di integrità rilevata',
                                $site['label'] . ' · ' . $site['url'] . ' · ' . count($int_fresh) . ' segnali critici', $body, 'danger');
                            if (self::send_html('[Integra Sentinel] INTRUSIONE / INTEGRITÀ · ' . $site['label'], $mail_html) || $hook) {
                                $ack[$id]['integrity']['sent'] = $int_sent;
                            } else { $retry = true; }
                        }
                    }
                }

                // 2. Alert CVE Confermati e Potenziali.
                foreach (array('confirmed', 'possible') as $kind) {
                    $possible = $kind === 'possible';
                    $queue_key = $possible ? 'possible_pending_ids' : 'pending_alert_ids';
                    $findings_key = $possible ? 'possible_findings' : 'findings';
                    $queue = (array) ($site[$queue_key] ?? array());
                    if (!$queue) { continue; }
                    $current = array();
                    foreach ((array) ($site[$findings_key] ?? array()) as $f) {
                        if (!empty($f['id'])) { $current[$f['id']] = $f; }
                    }
                    $fresh = array(); $stale = array();
                    foreach ($queue as $key) {
                        if (isset($current[$key])) { $fresh[] = $current[$key]; }
                        else { $stale[] = $key; }
                    }
                    if ($stale) { $ack[$id][$kind]['discard'] = $stale; }
                    if (!$fresh) { continue; }
                    $sent = array_column($fresh, 'id');
                    $groups = self::sn_group_findings($fresh);
                    $hook = false;
                    if (!$possible) {
                        $fresh_confirmed[$id] = $fresh;
                        $lines = array();
                        foreach (array_slice($groups, 0, 6) as $g) { $lines[] = $g['name'] . ': ' . $g['count'] . ' advisory, gravità ' . strtolower(self::sn_severity($g['max_tier'])); }
                        $hook = Integra_Sentinel_Notify::send('cve', 'Nuove vulnerabilità · ' . $site['label'], implode("\n", $lines), self::sn_admin_url($id));
                    }
                    if (empty($cfg['alerts'])) {
                        $ack[$id][$kind]['sent'] = $sent;
                        continue;
                    }
                    if (!$possible) {
                        $html = self::mail_metrics(array(
                            array('value'=>count($fresh),'label'=>'Nuovi advisory','tone'=>'danger'),
                            array('value'=>count($groups),'label'=>'Componenti interessati','tone'=>'danger'),
                            array('value'=>($site['report']['wp'] ?? '—'),'label'=>'Versione WordPress','tone'=>'brand'),
                            array('value'=>($site['report']['php'] ?? '—'),'label'=>'Versione PHP','tone'=>'warn'),
                        ));
                        $html .= self::mail_notice('NUOVE VULNERABILITÀ NOTE',
                            'Il motore di sicurezza ha individuato nuovi advisory applicabili alle versioni installate su ' . $site['label'] . '. Valutare impatto, disponibilità di patch e compatibilità prima di intervenire.', 'danger');
                        $shown = 0;
                        foreach ($groups as $g) {
                            if ($shown++ >= 16) { break; }
                            $html .= self::mail_component_card($g['name'], $g['items'][0]['version'] ?? '',
                                self::mail_plugin_update($site, $g), $g['items'], false, self::mail_plugin_compatibility($site, $g));
                        }
                        if (count($groups) > 16) { $html .= self::mail_p('Altri ' . (count($groups) - 16) . ' componenti: apri la dashboard.'); }
                        $html .= self::mail_cta('Apri gli interventi per ' . $site['label'], self::sn_admin_url($id));
                        $html = self::mail_shell('Nuove vulnerabilità rilevate',
                            $site['label'] . ' · ' . $site['url'] . ' · ' . count($fresh) . ' advisory nuovi', $html, 'danger');
                        $ok = self::send_html('[Integra Sentinel] ALERT · ' . $site['label'] . ' · ' . count($fresh) . ' nuove vulnerabilità', $html);
                    } else {
                        $html = self::mail_notice('POSSIBILI CORRISPONDENZE - VERIFICA NECESSARIA',
                            'Gli slug di alcuni plugin con canale esterno corrispondono a segnalazioni dei database. L’identità del prodotto non è confermata.', 'warn');
                        foreach (array_slice($groups, 0, 12) as $g) {
                            $html .= self::mail_component_card($g['name'], $g['items'][0]['version'] ?? '', '', $g['items'], true);
                        }
                        $html .= self::mail_cta('Controlla i componenti del sito', self::sn_admin_url($id));
                        $html = self::mail_shell('Advisory potenziali da verificare',
                            $site['label'] . ' · ' . count($fresh) . ' corrispondenze non confermate', $html, 'warn');
                        $ok = self::send_html('[Integra Sentinel] VERIFICA IDENTITÀ · ' . $site['label'], $html);
                    }
                    if ($ok || $hook) { $ack[$id][$kind]['sent'] = $sent; }
                    else { $retry = true; }
                }

                // 3. Alert sito Offline (> 36h).
                if (!empty($site['last_seen']) && time() - (int) $site['last_seen'] > 36 * HOUR_IN_SECONDS && empty($site['offline_alerted'])) {
                    $hook = Integra_Sentinel_Notify::send('down', 'Client muto · ' . $site['label'], 'Il sito ' . $site['url'] . ' non invia inventari da oltre 36 ore: controllare plugin Client, WP-Cron e rete.', self::sn_admin_url($id));
                    if (!empty($cfg['alerts'])) {
                        $content = self::mail_shell('Connessione assente', 'Il sito non invia inventari da oltre 36 ore.',
                            self::mail_notice('ATTENZIONE', 'Il Client di ' . $site['label'] . ' (' . $site['url'] . ') non comunica. Controllare plugin, cron e rete.', 'warn')
                            . self::mail_cta('Apri il sito nel Master', self::sn_admin_url($id)), 'warn');
                        if (self::send_html('[Integra Sentinel] Connessione assente: ' . $site['label'], $content) || $hook) {
                            $ack[$id]['offline_seen'] = (int) $site['last_seen'];
                        } else { $retry = true; }
                    } elseif ($hook) {
                        $ack[$id]['offline_seen'] = (int) $site['last_seen'];
                    }
                }
            }
            // Stessa vulnerabilita' nuova su piu' siti: un avviso unico con la vista per prodotto.
            if ($fresh_confirmed) { Integra_Sentinel_Jobs::emergency($fresh_confirmed, $sites, !empty($cfg['alerts'])); }
            if ($ack && !self::mutate_sites(function($current) use ($ack) {
                foreach ($ack as $id => $groups) {
                    if (!isset($current[$id])) { continue; }
                    foreach (array('confirmed', 'possible', 'integrity') as $kind) {
                        if (empty($groups[$kind])) { continue; }
                        if ($kind === 'integrity') {
                            $queued_key = 'integrity_pending_ids';
                            $sent_key   = 'integrity_alert_ids';
                        } else {
                            $possible   = $kind === 'possible';
                            $queued_key = $possible ? 'possible_pending_ids' : 'pending_alert_ids';
                            $sent_key   = $possible ? 'possible_alert_ids' : 'alert_ids';
                        }
                        $sent = (array) ($groups[$kind]['sent'] ?? array());
                        $discard = (array) ($groups[$kind]['discard'] ?? array());
                        $current[$id][$sent_key] = array_values(array_unique(array_merge(
                            (array) ($current[$id][$sent_key] ?? array()), $sent)));
                        $current[$id][$queued_key] = array_values(array_diff(
                            (array) ($current[$id][$queued_key] ?? array()), array_merge($sent, $discard)));
                    }
                    if (isset($groups['offline_seen']) && (int) ($current[$id]['last_seen'] ?? 0) === $groups['offline_seen']) {
                        $current[$id]['offline_alerted'] = true;
                    }
                }
                return $current;
            })) { $retry = true; }

            $pending = false;
            foreach (self::sites() as $latest) {
                if (!empty($latest['pending_alert_ids']) || !empty($latest['possible_pending_ids']) || !empty($latest['integrity_pending_ids'])) {
                    $pending = true; break;
                }
            }
            if (($retry || $pending) && !wp_next_scheduled(self::HOOK_ALERTS)) {
                wp_schedule_single_event(time() + ($retry ? 15 * MINUTE_IN_SECONDS : 180), self::HOOK_ALERTS);
            }
        } finally { self::release_lock('alert_delivery'); }
    }

    /** Allarme di raggiungibilita' (sonde del Master, sola lettura) e avviso di ripristino. */
    public static function uptime_alert($site, $kind, $since, $results, $pages) {
        $url = self::sn_admin_url($site['id']) . '#sn-probe';
        $since = $since ?: time();
        if ($kind === 'down') {
            $lines = array();
            foreach ((array) $results as $idx => $r) {
                if (empty($r['ok'])) { $lines[] = ($pages[$idx]['label'] ?? 'Pagina') . ': ' . (($r['error'] ?? '') !== '' ? $r['error'] : 'HTTP ' . (int) ($r['code'] ?? 0)); }
            }
            $title = 'Sito non raggiungibile · ' . $site['label'];
            $text = 'Due controlli consecutivi falliti dal ' . wp_date('d/m H:i', $since) . '. ' . implode('; ', $lines);
            $tone = 'danger';
        } else {
            $minutes = max(1, (int) round((time() - $since) / MINUTE_IN_SECONDS));
            $title = 'Sito di nuovo raggiungibile · ' . $site['label'];
            $text = 'Il sito ' . $site['url'] . ' risponde di nuovo. Problema rilevato dal ' . wp_date('d/m H:i', $since) . ' (circa ' . ($minutes < 120 ? $minutes . ' minuti' : round($minutes / 60, 1) . ' ore') . ').';
            $tone = 'clear';
        }
        $hook = Integra_Sentinel_Notify::send('down', $title, $text, $url);
        $mail = false;
        if (!empty(self::mail_config()['alerts'])) {
            $mail = self::send_html('[Integra Sentinel] ' . $title, self::mail_shell($title, $site['url'],
                self::mail_notice($kind === 'down' ? 'SITO NON RAGGIUNGIBILE' : 'RIPRISTINATO', $text, $tone) . self::mail_cta('Apri il sito nel Master', $url), $tone));
        }
        return $mail || $hook;
    }

    private static function counts($site) {
        $report = $site['report'] ?? array();
        $updates = !empty($report['core_update']) ? 1 : 0;
        foreach (($report['plugins'] ?? array()) as $p) { if (!empty($p['update']['version'])) { ++$updates; } }
        foreach (($report['themes'] ?? array()) as $t) { if (!empty($t['update'])) { ++$updates; } }
        $wpcfg = (array) get_option(self::WPSCAN_CONFIG, array());
        $summary = !empty($wpcfg['enabled']) && !empty($site['report']) ? self::wpscan_site_findings($site, (array) get_option(self::WPSCAN_FEEDS, array())) : array('findings'=>array(), 'checked'=>0, 'total'=>0);
        return array(
            'updates' => $updates,
            'vulnerabilities' => count($site['findings'] ?? array()),
            'integrity_count' => count($site['integrity_findings'] ?? array()),
            'wpscan' => count($summary['findings']),
            'wpscan_checked' => $summary['checked'],
            'wpscan_total' => $summary['total'],
            'wpscan_enabled' => !empty($wpcfg['enabled']),
            'offline' => empty($site['last_seen']) || time() - $site['last_seen'] > 36 * HOUR_IN_SECONDS,
        );
    }

    public static function e($value) { return esc_html((string) $value); }

    /** $return: pagina Sentinel a cui tornare dopo il comando (es. Impostazioni); $site: scheda sito da riaprire. */
    public static function post_form_start($return = '', $site = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('integra_sentinel_master_action');
        echo '<input type="hidden" name="action" value="integra_sentinel_master_action">';
        if ($return !== '') { echo '<input type="hidden" name="_sn_return" value="' . esc_attr($return) . '">'; }
        if ($site !== '') { echo '<input type="hidden" name="_sn_site" value="' . esc_attr($site) . '">'; }
    }

    private static function button($cmd, $title, $id = '', $css = 'button', $return = '') {
        self::post_form_start($return, $cmd !== 'delete' ? $id : '');
        echo '<input type="hidden" name="cmd" value="' . esc_attr($cmd) . '">';
        if ($id) { echo '<input type="hidden" name="site_id" value="' . esc_attr($id) . '">'; }
        $confirm = array('delete' => 'Rimuovere definitivamente questo sito da Sentinel?', 'revoke' => 'Revocare subito la chiave? Il Client smetterà di comunicare finché non inserisci la nuova.');
        echo '<button class="' . esc_attr($css) . '" type="submit"' . (isset($confirm[$cmd]) ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm[$cmd])) . ')"' : '') . '>' . self::e($title) . '</button></form>';
    }

    public static function sn_admin_url($site_id = '') {
        $url = admin_url('admin.php?page=integra-sentinel');
        return $site_id ? add_query_arg('site', $site_id, $url) : $url;
    }

    public static function sn_group_findings($findings) {
        $groups = array();
        foreach ((array) $findings as $f) {
            if (!is_array($f)) { continue; }
            $type = (string) ($f['type'] ?? 'plugin');
            $slug = (string) ($f['slug'] ?? 'unknown');
            $key = $type . ':' . $slug;
            if (!isset($groups[$key])) {
                $groups[$key] = array('key'=>$key,'type'=>$type,'slug'=>$slug,'name'=>(string) ($f['component'] ?? $slug),
                    'items'=>array(),'max_score'=>null,'max_tier'=>'unknown','count'=>0,'unfixed'=>false);
            }
            $id = (string) ($f['id'] ?? sha1(wp_json_encode($f)));
            if (isset($groups[$key]['items'][$id])) { continue; }
            $groups[$key]['items'][$id] = $f;
            ++$groups[$key]['count'];
            $score = isset($f['score']) && is_numeric($f['score']) ? (float) $f['score'] : null;
            if ($score !== null && ($groups[$key]['max_score'] === null || $score > $groups[$key]['max_score'])) { $groups[$key]['max_score'] = $score; }
            $tier = self::sn_tier($score);
            if ($tier === 'unknown') {
                $word = strtolower(trim((string) ($f['severity'] ?? '')));
                $known = array('critical'=>'critical','critica'=>'critical','high'=>'high','alta'=>'high','medium'=>'medium','moderate'=>'medium','media'=>'medium','low'=>'low','bassa'=>'low');
                if (isset($known[$word])) { $tier = $known[$word]; }
            }
            if (self::sn_rank($tier) > self::sn_rank($groups[$key]['max_tier'])) { $groups[$key]['max_tier'] = $tier; }
            if (!empty($f['unfixed'])) { $groups[$key]['unfixed'] = true; }
        }
        foreach ($groups as &$g) {
            $g['items'] = array_values($g['items']);
            usort($g['items'], function($a, $b) { return (float) ($b['score'] ?? -1) <=> (float) ($a['score'] ?? -1); });
        }
        unset($g);
        uasort($groups, function($a, $b) {
            $ra = self::sn_rank($a['max_tier']); $rb = self::sn_rank($b['max_tier']);
            return $ra === $rb ? ($b['count'] <=> $a['count']) : ($rb <=> $ra);
        });
        return $groups;
    }

    public static function sn_tier($score) {
        if ($score === null) { return 'unknown'; }
        if ($score >= 9) { return 'critical'; }
        if ($score >= 7) { return 'high'; }
        if ($score >= 4) { return 'medium'; }
        return 'low';
    }

    public static function sn_rank($tier) {
        return array('critical'=>5,'high'=>4,'medium'=>3,'low'=>2,'unknown'=>1,'clear'=>0)[$tier] ?? 0;
    }

    public static function sn_severity($tier) {
        return array('critical'=>'Critica','high'=>'Alta','medium'=>'Media','low'=>'Bassa','unknown'=>'Non classificata','clear'=>'Nessuna nota')[$tier] ?? 'Da verificare';
    }

    public static function sn_badge($text, $tone = 'muted') {
        return '<span class="sn-badge sn-' . esc_attr($tone) . '">' . self::e($text) . '</span>';
    }

    private static function sn_feed_state($plugin, $cache) {
        if (self::skip_plugin_lookup($plugin)) { return array('label'=>'Componente interno','tone'=>'muted','state'=>'internal'); }
        $item = $cache['plugin:' . $plugin['slug']] ?? null;
        $state = $item['state'] ?? 'pending';
        if ($state === 'ok') {
            if (self::plugin_identity($plugin) === 'unconfirmed') {
                return array('label'=>'Identità da verificare','tone'=>'warn','state'=>'review');
            }
            $res = self::advisories_for($cache, 'plugin', $plugin['slug'], $plugin['version']);
            if ($res['status'] === 'review') { return array('label'=>'Advisory da verificare','tone'=>'warn','state'=>'review'); }
            return array('label'=>'Controllato','tone'=>'ok','state'=>'ok');
        }
        $labels = array('error'=>'Errore API','missing'=>'Non censito','pending'=>'In attesa');
        return array('label'=>$labels[$state] ?? 'Non verificato','tone'=>'warn','state'=>$state);
    }

    private static function sn_coverage($site, $cache) {
        $targets = self::scan_target_list(array($site['id'] => $site));
        $ok = 0; $bad = 0; $waiting = 0; $stale = 0;
        foreach ($targets as $key => $spec) {
            if (!isset($cache[$key])) { ++$waiting; continue; }
            if (($cache[$key]['state'] ?? '') === 'ok') {
                ++$ok;
                if (time() - (int) ($cache[$key]['at'] ?? 0) > DAY_IN_SECONDS) { ++$stale; }
            } else { ++$bad; }
        }
        $internal = 0; $identity_review = 0;
        foreach ((array) ($site['report']['plugins'] ?? array()) as $p) {
            $identity = self::plugin_identity($p);
            if ($identity === 'internal') { ++$internal; }
            if ($identity === 'unconfirmed') { ++$identity_review; }
        }
        return array('total'=>count($targets),'ok'=>$ok,'bad'=>$bad,'waiting'=>$waiting,'stale'=>$stale,'excluded'=>$internal,'internal'=>$internal,'identity_review'=>$identity_review);
    }

    public static function sn_metrics($site, $cache) {
        $base = self::counts($site);
        $groups = self::sn_group_findings($site['findings'] ?? array());
        $rank = 0;
        foreach ($groups as $g) { $rank = max($rank, self::sn_rank($g['max_tier'])); }
        if (!empty($base['integrity_count'])) { $rank = max($rank, 5); }
        $tiers = array('critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'unknown'=>0);
        foreach ($groups as $g) { ++$tiers[$g['max_tier']]; }
        $coverage = self::sn_coverage($site, $cache);
        $possible_count = count((array) ($site['possible_findings'] ?? array()));
        $state = $base['offline'] ? 'offline' : ($rank >= 5 ? 'critical' : ($rank >= 3 ? 'risk' : ($rank > 0 ? 'risk' : (!empty($site['feed_pending']) || $coverage['bad'] || $coverage['waiting'] || $coverage['identity_review'] || $possible_count || !empty($site['feed_review']) ? 'review' : 'clear'))));
        return array_merge($base, array('groups'=>$groups,'affected'=>count($groups),'tiers'=>$tiers,'rank'=>$rank,'coverage'=>$coverage,'possible_count'=>$possible_count,'state'=>$state));
    }

    public static function sn_state_label($state) {
        return array('critical'=>'Priorità critica','risk'=>'Vulnerabilità note','review'=>'Verifica incompleta','clear'=>'Nessuna nota','offline'=>'Non collegato')[$state] ?? 'Da verificare';
    }

    public static function sn_stat($number, $title, $sub, $tone = 'neutral') {
        echo '<div class="sn-stat sn-stat-' . esc_attr($tone) . '"><div class="sn-stat-number">' . self::e($number) . '</div><div class="sn-stat-title">' . self::e($title) . '</div><div class="sn-stat-sub">' . self::e($sub) . '</div></div>';
    }

    private static function sn_chart($parts, $total, $labels = array()) {
        echo '<div class="sn-chart" role="img" aria-label="Distribuzione: ';
        $description = array();
        foreach ($parts as $key => $v) { $description[] = ($labels[$key] ?? $key) . ': ' . (int) $v; }
        echo esc_attr(implode('; ', $description)) . '">';
        if (!$total) { echo '<span class="sn-chart-empty"></span>'; }
        foreach ($parts as $key => $v) {
            if (!$v || !$total) { continue; }
            echo '<span class="sn-chart-piece sn-fill-' . esc_attr($key) . '" style="width:' . esc_attr(number_format(100 * $v / $total, 3, '.', '')) . '%"></span>';
        }
        echo '</div><div class="sn-legend">';
        foreach ($parts as $key => $v) {
            echo '<span><i class="sn-dot sn-fill-' . esc_attr($key) . '"></i>' . self::e($labels[$key] ?? $key) . ' <b>' . (int) $v . '</b></span>';
        }
        echo '</div>';
    }

    private static function sn_site_issues($site, $metrics, $analysis = null) {
        $r = $site['report'] ?? array();
        $sec = $r['security'] ?? array();
        $problems = array();
        if (!empty($metrics['integrity_count'])) {
            $problems[] = 'ALLARME INTEGRITÀ: ' . (int) $metrics['integrity_count'] . ' anomalie su file critici, uploads, DB o admin';
        }
        if ($metrics['offline']) { $problems[] = 'Client non collegato o senza report da più di 36 ore'; }
        $probe = Integra_Sentinel_Probe::state()[$site['id'] ?? ''] ?? array();
        if (!empty($probe['down_since']) && (int) ($probe['fails'] ?? 0) >= 2) {
            $problems[] = 'Sito non raggiungibile dal ' . wp_date('d/m H:i', (int) $probe['down_since']);
        }
        if ($analysis && !empty($analysis['bottlenecks'])) {
            $top = $analysis['bottlenecks'][0];
            if (in_array($top['severity'], array('critical', 'high'), true)) {
                $problems[] = (!empty($top['security']) ? 'Patch di sicurezza bloccata: ' : 'Collo di bottiglia: ') . $top['title'];
            }
        }
        if (!empty($site['uses_prev_secret'])) {
            $problems[] = 'Il Client usa ancora la chiave precedente: aggiornala prima del ' . wp_date('d/m/Y', (int) ($site['secret_prev_until'] ?? time()));
        }
        if (isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) && $site['ssl_days_left'] < 14) {
            $problems[] = $site['ssl_days_left'] < 0 ? 'Certificato SSL SCADUTO' : 'Certificato SSL in scadenza tra ' . $site['ssl_days_left'] . ' giorni';
        }
        if (isset($sec['blog_public']) && !$sec['blog_public']) {
            $problems[] = 'SEO bloccata: indicizzazione motori di ricerca disattivata (noindex)';
        }
        if (!empty($sec['last_fatal'])) {
            $problems[] = 'Fatal Error PHP recente: ' . $sec['last_fatal'];
        }
        if (!empty($sec['debug_log_kb']) && (int) $sec['debug_log_kb'] > 0) {
            $problems[] = 'File wp-content/debug.log presente (' . (int) $sec['debug_log_kb'] . ' KB)';
        }
        if (!empty($r['php'])) {
            $eol = Integra_Sentinel_EOL::evaluate('php', $r['php']);
            if ($eol['state'] === 'eol') { $problems[] = 'PHP ' . $r['php'] . ' fuori supporto di sicurezza'; }
            elseif ($eol['state'] === 'soon') { $problems[] = 'PHP ' . $r['php'] . ': supporto fino al ' . wp_date('d/m/Y', strtotime($eol['eol'] . ' 12:00:00')); }
        }
        if (!empty($r['core_update'])) { $problems[] = 'WordPress da aggiornare: ' . $r['wp'] . ' → ' . $r['core_update']; }
        foreach ((array) ($r['plugins'] ?? array()) as $p) {
            if (!empty($p['active']) && stripos($p['slug'], 'activator') !== false) {
                $problems[] = $p['name'] . ': verificare provenienza e integrità';
            }
            if (!empty($p['update']['version'])) {
                $a = self::update_assessment($p, $r);
                if ($a['class'] === 'red') { $problems[] = $p['name'] . ': prerequisiti non soddisfatti'; }
            }
        }
        if ($metrics['coverage']['bad'] || $metrics['coverage']['waiting']) { $problems[] = 'Scansione di sicurezza non completa'; }
        if (!empty($metrics['possible_count']) || !empty($metrics['coverage']['identity_review'])) { $problems[] = 'Plugin commerciali/custom: corrispondenza con il feed da verificare'; }
        return array_slice(array_unique($problems), 0, 6);
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'integra-sentinel') === false) { return; }
        wp_enqueue_style('integra-sentinel-dashboard', plugins_url('assets/sentinel-dashboard.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('integra-sentinel-dashboard', plugins_url('assets/sentinel-dashboard.js', __FILE__), array(), self::VERSION, true);
    }

    public static function page() {
        if (!Integra_Sentinel_Settings::can_view()) { return; }
        $sites = self::sites();
        $once = get_transient('integra_sentinel_master_once_' . get_current_user_id());
        if ($once) { delete_transient('integra_sentinel_master_once_' . get_current_user_id()); }
        $view = isset($_GET['site']) ? sanitize_text_field(wp_unslash($_GET['site'])) : '';
        $right = ($view && isset($sites[$view])) ? '<a class="button" href="' . esc_url(self::sn_admin_url()) . '">← Tutti i siti</a>' : self::sn_badge(count($sites) . ' siti registrati', 'brand');
        Integra_Sentinel_Admin::open('integra-sentinel', $right);
        $messages = array('added'=>'Sito aggiunto: copia subito le credenziali oppure genera un codice di abbinamento dalla scheda del sito.', 'rotated'=>'Chiave rigenerata: aggiornala nel Client. La precedente resta valida per i giorni di tolleranza impostati.',
            'revoked'=>'Chiave revocata: la precedente non funziona più. Aggiorna subito il Client.', 'removed'=>'Sito rimosso.', 'email'=>'Indirizzo email aggiornato.', 'scan'=>'Controllo programmato.',
            'invalid'=>'Dati non validi.', 'duplicate'=>'Sito già presente.', 'crypto'=>'OpenSSL non disponibile.', 'wpscan'=>'Impostazioni WPScan salvate.', 'license'=>'Occorrono chiave e licenza WPScan idonea.',
            'token'=>'Token WPScan non valido.', 'test_sent'=>'Report di prova accettato dal sistema email. Controlla la casella e lo spam.', 'test_failed'=>'Report non inviato: controlla indirizzi, wp_mail e configurazione SMTP.',
            'busy'=>'Database temporaneamente occupato: nessuna modifica effettuata, riprova.', 'deps'=>'Regole delle dipendenze e catalogo premium salvati.', 'denied'=>'Operazione riservata agli amministratori.');
        $msg = isset($_GET['sentinel_msg']) ? sanitize_key(wp_unslash($_GET['sentinel_msg'])) : '';
        if (isset($messages[$msg]) && !isset(Integra_Sentinel_Admin::messages()[$msg])) { echo '<div class="notice notice-info"><p>' . self::e($messages[$msg]) . '</p></div>'; }
        if ($once) {
            echo '<section class="sn-panel sn-secret"><h2>Credenziali da copiare adesso</h2><p>Questi dati saranno visibili una sola volta. Incollali nelle impostazioni del Client, oppure usa un codice di abbinamento dalla scheda del sito (Gestione sito).</p>';
            foreach (array('URL Master'=>untrailingslashit(home_url('/')),'ID sito'=>$once['id'],'Chiave segreta'=>$once['secret']) as $label => $value) {
                echo '<div class="sn-secret-line"><strong>' . self::e($label) . '</strong><code>' . self::e($value) . '</code></div>';
            }
            echo '</section>';
        }
        if ($view && isset($sites[$view])) { self::detail($sites[$view]); }
        else { self::overview($sites); }
        Integra_Sentinel_Admin::close();
    }

    private static function overview($sites) {
        $cache = (array) get_option(self::FEEDS, array());
        $all = array(); $states = array('critical'=>0,'risk'=>0,'review'=>0,'clear'=>0,'offline'=>0);
        $alerts = 0; $affected = 0; $integrity_sum = 0; $with_findings = 0; $hotspots = array(); $tags = array();
        foreach ($sites as $id => $site) {
            $m = self::sn_metrics($site, $cache);
            $all[$id] = array('site'=>$site,'m'=>$m);
            ++$states[$m['state']];
            $alerts += $m['vulnerabilities']; $affected += $m['affected']; $integrity_sum += $m['integrity_count'];
            if ($m['affected'] || $m['integrity_count']) { ++$with_findings; }
            foreach ((array) ($site['meta']['tags'] ?? array()) as $tag) { $tags[$tag] = true; }
            foreach ($m['groups'] as $g) {
                $key = $g['key'];
                if (!isset($hotspots[$key])) { $hotspots[$key] = array('name'=>$g['name'],'sites'=>array(),'count'=>0,'tier'=>'unknown'); }
                $hotspots[$key]['sites'][$id] = $site['label'];
                $hotspots[$key]['count'] += $g['count'];
                if (self::sn_rank($g['max_tier']) > self::sn_rank($hotspots[$key]['tier'])) { $hotspots[$key]['tier'] = $g['max_tier']; }
            }
        }
        uasort($all, function($a, $b) {
            $x = $a['m']; $y = $b['m'];
            $rx = $x['state'] === 'offline' ? 3 : $x['rank'];
            $ry = $y['state'] === 'offline' ? 3 : $y['rank'];
            return ($ry <=> $rx) ?: ($y['integrity_count'] <=> $x['integrity_count']) ?: ($y['affected'] <=> $x['affected']) ?: strcmp($a['site']['label'], $b['site']['label']);
        });
        uasort($hotspots, function($a, $b) { return (count($b['sites']) <=> count($a['sites'])) ?: (self::sn_rank($b['tier']) <=> self::sn_rank($a['tier'])) ?: ($b['count'] <=> $a['count']); });
        $fleet = Integra_Sentinel_Fleet::aggregate();
        $task_counts = Integra_Sentinel_Store::task_counts();
        $open_tasks = 0;
        foreach ($task_counts as $status => $n) { if ($status !== 'done') { $open_tasks += $n; } }
        $can_add = current_user_can('manage_options');
        echo '<section class="sn-page-heading"><div><p class="sn-kicker">PANORAMICA AZIENDALE</p><h2>Situazione dei siti</h2><p>Sicurezza, aggiornamenti e colli di bottiglia di tutto il parco. Sentinel legge e basta: non modifica mai i siti dei clienti.</p></div>' . ($can_add ? '<a class="button" href="#sn-config">+ Aggiungi sito</a>' : '') . '</section>';
        foreach ((array) Integra_Sentinel_Settings::get('freezes') as $f) {
            if (!is_array($f) || count($f) < 3) { continue; }
            $today = wp_date('Y-m-d');
            if ($today >= $f[0] && $today <= $f[1]) {
                echo '<div class="sn-freeze"><strong>Periodo di blocco attivo: ' . self::e($f[2]) . '</strong> · fino al ' . self::e(wp_date('d/m/Y', strtotime($f[1] . ' 12:00:00'))) . (!empty($f[3]) ? ' · siti con tag “' . self::e($f[3]) . '”' : ' · tutti i siti') . '. Solo interventi di sicurezza.</div>';
            }
        }
        echo '<div class="sn-stats">';
        self::sn_stat(count($sites), 'Siti monitorati', ($states['offline'] . ' senza collegamento'), 'brand');
        self::sn_stat($with_findings, 'Siti con criticità', $affected . ' componenti CVE · ' . $integrity_sum . ' anomalie integrità', 'danger');
        self::sn_stat($alerts, 'Advisory applicabili', 'Non equivale al numero di plugin', 'warn');
        self::sn_stat($open_tasks, 'Interventi aperti', (int) ($task_counts['client'] ?? 0) . ' in attesa del cliente', 'neutral');
        echo '</div>';
        echo '<h3 class="sn-band-title">Aggiornamenti del parco</h3>';
        Integra_Sentinel_Admin::readiness($fleet);
        Integra_Sentinel_Admin::bottleneck_center($fleet, true);
        $mailcfg = self::mail_config(); $nextmail = wp_next_scheduled(self::HOOK_DIGEST);
        echo '<div class="sn-mail-banner"><span class="sn-mail-symbol" aria-hidden="true">✉</span><div><strong>Report email automatico</strong><span>' . (!empty($mailcfg['daily']) ? 'Attivo · prossimo invio previsto ' . ($nextmail ? self::e(wp_date('d/m/Y H:i', $nextmail)) : 'da programmare') : 'Disattivato') . ' · Alert ' . (!empty($mailcfg['alerts']) ? 'attivi' : 'disattivati') . (Integra_Sentinel_Notify::configured() ? ' · Slack/Telegram/Make collegati' : '') . '</span></div><a href="' . esc_url(admin_url('admin.php?page=integra-sentinel-settings#sn-mail')) . '">Gestisci notifiche &rarr;</a></div>';
        echo '<div class="sn-main-grid"><section class="sn-panel"><div class="sn-section-head"><div><h3>Distribuzione dei siti</h3><p>Stato operativo corrente, inclusa telemetria anti-intrusione.</p></div></div>';
        self::sn_chart($states, count($sites), array('critical'=>'Critici','risk'=>'Con vulnerabilità','review'=>'Da verificare','clear'=>'Senza vulnerabilità note','offline'=>'Non collegati'));
        echo '<div class="sn-insight">';
        if ($with_findings) { echo '<span class="sn-insight-icon">!</span><span><b>' . $with_findings . ' siti richiedono interventi.</b> Apri prima quelli con anomalie di integrità o componenti critici.</span>'; }
        elseif ($sites) { echo '<span class="sn-insight-icon">i</span><span>Non risultano vulnerabilità o intrusioni nei dati disponibili. Controlla comunque copertura e aggiornamento delle scansioni.</span>'; }
        else { echo '<span class="sn-insight-icon">i</span><span>Registra il primo sito per iniziare.</span>'; }
        echo '</div></section>';
        echo '<section class="sn-panel"><div class="sn-section-head"><div><h3>Componenti da tenere d’occhio</h3><p>Plugin, temi e WordPress vulnerabili raggruppati tra tutti i siti.</p></div><a class="sn-linkbtn" href="' . esc_url(admin_url('admin.php?page=integra-sentinel-fleet')) . '">Tutti i prodotti →</a></div>';
        if (!$hotspots) { echo '<p class="sn-muted">Non ci sono ancora componenti vulnerabili identificati.</p>'; }
        $shown = 0;
        foreach ($hotspots as $hkey => $h) {
            if ($shown++ >= 5) { break; }
            $product = strpos($hkey, 'core:') === 0 ? '' : $hkey;
            echo '<div class="sn-hotspot"><span class="sn-hotspot-dot sn-fill-' . esc_attr($h['tier']) . '"></span><div><b>' . ($product !== '' ? '<a href="' . esc_url(admin_url('admin.php?page=integra-sentinel-fleet&product=' . rawurlencode($product))) . '">' . self::e($h['name']) . '</a>' : self::e($h['name'])) . '</b><small>' . count($h['sites']) . ' siti interessati · ' . $h['count'] . ' advisory</small></div>' . self::sn_badge(self::sn_severity($h['tier']), $h['tier']) . '</div>';
        }
        echo '</section></div>';
        Integra_Sentinel_Admin::trends($fleet);
        echo '<section class="sn-section" id="sn-sites"><div class="sn-section-head"><div><h3>I tuoi siti</h3><p>Ordinati per priorità. Clicca su un sito per vedere cosa sistemare.</p></div><span class="sn-counter">' . count($sites) . ' totali</span></div>';
        if ($sites) {
            echo '<div class="sn-plugin-tools"><label class="sn-search"><span aria-hidden="true">⌕</span><input type="search" data-sn-site-search placeholder="Cerca un sito…" aria-label="Cerca un sito"></label><div class="sn-filters" aria-label="Filtra siti">';
            foreach (array('all' => 'Tutti', 'critical' => 'Critici', 'risk' => 'Con vulnerabilità', 'review' => 'Da verificare', 'blocked' => 'Con colli di bottiglia', 'offline' => 'Non collegati') as $key => $label) {
                echo '<button type="button" class="sn-filter' . ($key === 'all' ? ' is-selected' : '') . '" data-sn-site-filter="' . esc_attr($key) . '" aria-pressed="' . ($key === 'all' ? 'true' : 'false') . '">' . self::e($label) . '</button>';
            }
            echo '</div>';
            if ($tags) {
                ksort($tags);
                echo '<label class="sn-tag-filter">Tag <select data-sn-site-tag><option value="">Tutti</option>';
                foreach (array_keys($tags) as $tag) { echo '<option value="' . esc_attr($tag) . '">' . self::e($tag) . '</option>'; }
                echo '</select></label>';
            }
            echo '</div>';
        }
        echo '<div class="sn-site-grid" id="sn-site-grid">';
        foreach ($all as $id => $entry) {
            $site = $entry['site']; $m = $entry['m']; $r = $site['report'] ?? array();
            $analysis = $fleet['per_site'][$id] ?? null;
            $s = $analysis ? $analysis['summary'] : null;
            $issues = self::sn_site_issues($site, $m, $analysis);
            $coverage = $m['coverage']; $cov_text = $coverage['total'] ? $coverage['ok'] . '/' . $coverage['total'] : '—';
            $meta = (array) ($site['meta'] ?? array());
            $filters = $m['state'] . ($s && $s['bottlenecks'] ? ' blocked' : '');
            echo '<a class="sn-site-card sn-accent-' . esc_attr($m['state']) . '" href="' . esc_url(self::sn_admin_url($id)) . '" data-sn-state="' . esc_attr($filters) . '" data-sn-tags="' . esc_attr(implode(' ', (array) ($meta['tags'] ?? array()))) . '" data-sn-name="' . esc_attr(strtolower($site['label'] . ' ' . $site['url'])) . '">';
            echo '<div class="sn-site-head"><div><h4>' . self::e($site['label']) . '</h4><span class="sn-domain">' . self::e($site['url']) . '</span></div>' . self::sn_badge(self::sn_state_label($m['state']), $m['state']) . '</div>';
            echo '<div class="sn-site-numbers"><div><strong>' . ($m['affected'] + $m['integrity_count']) . '</strong><span>rischi / integrità</span></div><div><strong>' . ($s ? (int) $s['ready'] : $m['updates']) . '</strong><span>' . ($s ? 'aggiornabili subito' : 'aggiornamenti') . '</span></div><div><strong>' . ($s ? (int) $s['bottlenecks'] : 0) . '</strong><span>colli di bottiglia</span></div></div>';
            if ($s && ($s['license'] || $s['manual'] || $s['blind'] || $s['blocked'])) {
                echo '<div class="sn-site-chips">';
                if ($s['blocked']) { echo self::sn_badge($s['blocked'] . ' bloccati', 'danger'); }
                if ($s['license']) { echo self::sn_badge($s['license'] . ' licenze', 'premium'); }
                if ($s['manual']) { echo self::sn_badge($s['manual'] . ' manuali', 'premium'); }
                if ($s['blind']) { echo self::sn_badge($s['blind'] . ' ciechi', 'muted'); }
                echo '</div>';
            }
            if ($issues) { echo '<p class="sn-site-issue">' . self::e($issues[0]) . '</p>'; }
            else { echo '<p class="sn-site-issue sn-site-issue-muted">Nessun problema aggiuntivo prioritario rilevato.</p>'; }
            $ssl_tag = isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) ? ' · SSL ' . $site['ssl_days_left'] . 'gg' : '';
            $tag_text = !empty($meta['tags']) ? ' · #' . implode(' #', (array) $meta['tags']) : '';
            echo '<div class="sn-site-footer"><span>WP ' . self::e($r['wp'] ?? '—') . ' · PHP ' . self::e($r['php'] ?? '—') . self::e($ssl_tag . $tag_text) . '</span><span>API ' . self::e($cov_text) . ' ↗</span></div></a>';
        }
        if (!$all) { echo '<div class="sn-panel sn-empty">Non hai ancora registrato siti. Aggiungine uno qui sotto.</div>'; }
        echo '</div><p class="sn-no-results" id="sn-sites-empty" hidden>Nessun sito corrisponde ai filtri.</p></section>';
        if ($can_add) {
            echo '<details class="sn-panel sn-config" id="sn-config"><summary><span><strong>Aggiungi un sito</strong><small>Crea le credenziali; poi collega il Client con un codice di abbinamento dalla scheda del sito</small></span><span class="sn-chevron">⌄</span></summary><div class="sn-config-body">';
            echo '<div class="sn-two"><div><p>Installa il Client sul sito del cliente. Dopo aver creato il sito qui, aprine la scheda e genera un codice di abbinamento: è il modo più rapido e non richiede di copiare la chiave.</p>';
            self::post_form_start();
            echo '<input type="hidden" name="cmd" value="add"><p><label>Nome del sito<br><input type="text" class="regular-text" name="label" maxlength="120" required placeholder="Cliente Alfa"></label></p>';
            echo '<p><label>URL esatto HTTPS<br><input type="url" class="regular-text" name="site_url" required placeholder="https://www.cliente.it"></label></p>';
            submit_button('Crea il sito', 'primary', 'submit', false);
            echo '</form><p class="sn-hint">Nessun limite di siti. Sono accettate installazioni in sottocartella.</p></div><div><h3>Il resto è in Impostazioni</h3><p>Email e notifiche Slack/Telegram/Make, WPScan, catalogo premium, politiche di aggiornamento, stack standard, marchio dei report e accessi.</p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=integra-sentinel-settings')) . '">Apri le impostazioni →</a></p></div></div>';
            echo '</div></details>';
        }
    }

    /** Destinatari, report giornaliero e alert email (pagina Impostazioni). */
    public static function email_settings_form($return = '') {
        $mailcfg = self::mail_config();
        echo '<h3>Report e notifiche email</h3><p class="sn-hint">Riepilogo giornaliero alle 08:30 (fuso orario WordPress) con colli di bottiglia e licenze in scadenza, più avvisi immediati su intrusioni, nuove vulnerabilità, siti offline o non raggiungibili.</p>';
        self::post_form_start($return);
        echo '<input type="hidden" name="cmd" value="email"><p><label>Destinatari email (separati da virgola)<br><input type="text" class="regular-text" name="notification_email" required value="' . esc_attr(get_option(self::MAIL, get_option('admin_email'))) . '" placeholder="tecnico@azienda.it, direzione@azienda.it"></label></p>';
        echo '<p><label><input type="checkbox" name="daily_enabled" value="1" ' . checked(!empty($mailcfg['daily']), true, false) . '> Report completo ogni giorno alle 08:30</label></p>';
        echo '<p><label><input type="checkbox" name="alerts_enabled" value="1" ' . checked(!empty($mailcfg['alerts']), true, false) . '> Alert immediati su intrusioni file/DB, vulnerabilità nuove, siti offline o non raggiungibili</label></p>';
        submit_button('Salva notifiche', 'secondary', 'submit', false);
        echo '</form>';
        $next_digest = wp_next_scheduled(self::HOOK_DIGEST);
        $status = (array) get_option(self::MAIL_STATUS, array());
        echo '<p class="sn-hint">Prossimo report previsto: <strong>' . ($next_digest ? self::e(wp_date('d/m/Y H:i', $next_digest)) : 'Da programmare') . '</strong> · Fuso: ' . self::e(wp_timezone_string()) . '. Per l’orario puntuale è necessario un cron server.</p>';
        if (!empty($status['sent_at'])) { echo '<p class="sn-hint">Ultimo report: ' . self::e(wp_date('d/m/Y H:i', (int) $status['sent_at'])) . ' · ' . (!empty($status['ok']) ? 'Accettato dal sistema email' : 'Invio non riuscito') . '</p>'; }
        echo '<div class="sn-actions">'; self::button('test_digest', 'Invia un report di prova adesso', '', 'button', $return); self::button('refresh', 'Programma analisi vulnerabilità', '', 'button', $return);
        echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=integra_sentinel_master_preview'), 'integra_sentinel_master_preview')) . '">Anteprima report &nearr;</a>';
        echo '</div>';
    }

    private static function sn_dependency_notes($p, $report, $assessment) {
        $notes = (array) ($assessment['notes'] ?? array());
        foreach ((array) ($p['requires_plugins'] ?? array()) as $dependency) { $notes[] = 'Dipendenza dichiarata: ' . $dependency; }
        foreach (array('elementor'=>array('elementor_tested','elementor_requires'), 'woocommerce'=>array('wc_tested','wc_requires')) as $parent_slug => $fields) {
            if ($p['slug'] === $parent_slug) { continue; }
            $parent = null;
            foreach ((array) ($report['plugins'] ?? array()) as $candidate) { if (($candidate['slug'] ?? '') === $parent_slug) { $parent = $candidate; break; } }
            if (!$parent) { continue; }
            $declared = in_array($parent_slug, (array) ($p['requires_plugins'] ?? array()), true);
            $likely = $declared || stripos(($p['name'] ?? '') . ' ' . ($p['slug'] ?? ''), $parent_slug) !== false || !empty($p[$fields[0]]) || !empty($p[$fields[1]]);
            if (!$likely) { continue; }
            $notes[] = ($declared ? 'Dipende da ' : 'Integrazione con ') . $parent_slug . ' ' . $parent['version'];
            $target = $parent['update']['version'] ?? '';
            if ($target && !empty($p[$fields[0]]) && version_compare($target, $p[$fields[0]], '>')) {
                $notes[] = 'Compatibilità da verificare: ' . $parent_slug . ' ' . $target . ' supera la versione testata ' . $p[$fields[0]];
            } elseif ($target && empty($p[$fields[0]])) {
                $notes[] = 'Compatibilità con ' . $parent_slug . ' ' . $target . ' non dichiarata';
            }
        }
        return array_values(array_unique(array_filter($notes)));
    }

    private static function sn_plugin_records($site, $cache, $groups, $graph = null) {
        $records = array(); $r = $site['report'];
        $nodes = array(); $edge_notes = array();
        if ($graph) {
            foreach ($graph['nodes'] as $n) { $nodes[$n['id']] = $n; }
            foreach ($graph['edges'] as $e) {
                foreach ((array) $e['notes'] as $note) { $edge_notes[$e['s']][] = $note; }
            }
        }
        foreach ((array) ($r['plugins'] ?? array()) as $p) {
            $key = 'plugin:' . $p['slug']; $g = $groups[$key] ?? null;
            $state = self::sn_feed_state($p, $cache);
            $node = $nodes[$p['slug']] ?? null;
            if ($node) {
                // Un solo motore di compatibilita': valutazione dallo stato del nodo, note dai collegamenti della mappa.
                $class = array('blocked' => 'red', 'missing' => 'red', 'held' => 'red', 'license' => 'red', 'bottleneck' => 'yellow', 'warn' => 'yellow',
                    'abandoned' => 'yellow', 'manual' => 'yellow', 'blind' => 'yellow')[$node['status']] ?? 'green';
                $assessment = array('label' => $node['statusLabel'], 'class' => $class, 'notes' => array());
                $notes = array_values(array_unique((array) ($edge_notes[$p['slug']] ?? array())));
            } else {
                $assessment = self::update_assessment($p, $r);
                $notes = self::sn_dependency_notes($p, $r, $assessment);
            }
            $risky = boolval($g); $update = !empty($p['update']['version']);
            $records[] = array('plugin'=>$p,'group'=>$g,'state'=>$state,'assessment'=>$assessment,'notes'=>$notes,
                'risky'=>$risky,'update'=>$update, 'attention'=>$risky || $state['state'] !== 'ok' || ($update && $assessment['class'] !== 'green'));
        }
        usort($records, function($a, $b) {
            $x = $a['group'] ? self::sn_rank($a['group']['max_tier']) : 0;
            $y = $b['group'] ? self::sn_rank($b['group']['max_tier']) : 0;
            return ($y <=> $x) ?: ((int) $b['risky'] <=> (int) $a['risky']) ?: ((int) $b['update'] <=> (int) $a['update']) ?: strcasecmp($a['plugin']['name'], $b['plugin']['name']);
        });
        return $records;
    }

    private static function sn_advisory_rows($items) {
        echo '<div class="sn-advisories">';
        foreach ($items as $f) {
            $tier = self::sn_tier(isset($f['score']) && is_numeric($f['score']) ? (float) $f['score'] : null);
            echo '<div class="sn-advisory"><div class="sn-advisory-main"><strong>' . self::e($f['title'] ?? 'Advisory') . '</strong><small>' . self::e($f['reference'] ?: ($f['range'] ?: 'Intervallo non disponibile')) . '</small></div><div class="sn-advisory-meta">';
            echo self::sn_badge(isset($f['score']) && is_numeric($f['score']) ? number_format((float) $f['score'], 1) . ' · ' . self::sn_severity($tier) : 'Score non disponibile', $tier);
            if (!empty($f['fixed'])) { echo '<span class="sn-fixed">Corretta da ' . self::e($f['fixed']) . '</span>'; }
            elseif (!empty($f['unfixed'])) { echo '<span class="sn-fixed">Patch non indicata nel feed</span>'; }
            if (!empty($f['source'])) { echo '<a href="' . esc_url($f['source']) . '" target="_blank" rel="noopener noreferrer">Fonte ↗</a>'; }
            echo '</div></div>';
        }
        echo '</div>';
    }

    private static function sn_node_anchor($id) {
        return 'sn-plugin-' . sanitize_html_class($id);
    }

    public static function sn_status_tone($status) {
        return array('ok'=>'ok','update'=>'brand','manual'=>'premium','warn'=>'warn','held'=>'risk','bottleneck'=>'danger','blocked'=>'danger','missing'=>'danger',
            'license'=>'license','blind'=>'muted','abandoned'=>'warn')[$status] ?? 'muted';
    }

    private static function sn_origin_tone($origin) {
        return array('wporg'=>'ok','vendor'=>'premium','nochannel'=>'warn','bundled'=>'premium','custom'=>'muted','unknown'=>'muted','internal'=>'brand','missing'=>'danger','env'=>'muted')[$origin] ?? 'muted';
    }

    public static function sn_version_step($from, $to) {
        return '<span class="sn-vstep">' . self::e($from !== '' ? $from : '—') . ($to !== '' ? ' <i aria-hidden="true">→</i> <b>' . self::e($to) . '</b>' : '') . '</span>';
    }

    /** Mappa interattiva + albero + piano: il cuore della scheda sito. */
    private static function sn_dependency_section($graph) {
        $s = $graph['summary'];
        echo '<section class="sn-panel sn-deps" id="sn-deps"><div class="sn-section-head sn-deps-head"><div><h3>Mappa aggiornamenti e dipendenze</h3><p>Ogni nucleo è un plugin principale con gli addon che dipendono da lui. Un addon non compatibile con la nuova versione del nucleo e senza aggiornamenti diventa un collo di bottiglia.</p></div>';
        echo '<div class="sn-tabs" role="tablist" aria-label="Viste delle dipendenze">';
        foreach (array('graph' => 'Nuclei', 'tree' => 'Albero', 'plan' => 'Piano di aggiornamento') as $key => $label) {
            $on = $key === 'graph';
            echo '<button type="button" class="sn-tab' . ($on ? ' is-selected' : '') . '" role="tab" id="sn-tabbtn-' . esc_attr($key) . '" aria-controls="sn-tab-' . esc_attr($key) . '" aria-selected="' . ($on ? 'true' : 'false') . '" tabindex="' . ($on ? '0' : '-1') . '" data-sn-tab="' . esc_attr($key) . '">' . self::e($label) . '</button>';
        }
        echo '</div></div>';
        echo '<div class="sn-deps-strip">';
        echo '<span><b>' . (int) $s['clusters'] . '</b> nuclei</span>';
        echo '<span class="sn-strip-ok"><b>' . (int) $s['ready'] . '</b> aggiornamenti pronti</span>';
        echo '<span class="sn-strip-risk"><b>' . (int) $s['held'] . '</b> trattenuti</span>';
        echo '<span class="sn-strip-danger"><b>' . (int) $s['blocked'] . '</b> bloccati</span>';
        echo '<span class="sn-strip-danger"><b>' . (int) $s['bottlenecks'] . '</b> colli di bottiglia dei plugin</span>';
        echo '<span class="sn-strip-premium"><b>' . (int) $s['manual'] . '</b> premium da aggiornare a mano</span>';
        echo '</div>';

        // Vista grafo (renderizzata dal JS a partire dal JSON incorporato).
        echo '<div class="sn-tabpanel" id="sn-tab-graph" role="tabpanel" aria-labelledby="sn-tabbtn-graph">';
        echo '<div class="sn-graph-toolbar"><label class="sn-search sn-graph-search"><span aria-hidden="true">⌕</span><input type="search" id="sn-graph-find" placeholder="Trova un plugin nella mappa…" aria-label="Trova un plugin nella mappa" list="sn-graph-names"></label><datalist id="sn-graph-names"></datalist>';
        echo '<div class="sn-graph-toggles"><label><input type="checkbox" id="sn-graph-issues"> Solo nuclei con problemi</label><label><input type="checkbox" id="sn-graph-solo" checked> Plugin indipendenti</label><label><input type="checkbox" id="sn-graph-inactive" checked> Inattivi</label></div>';
        echo '<div class="sn-graph-zoom" role="group" aria-label="Zoom"><button type="button" class="button" data-sn-zoom="in" aria-label="Ingrandisci">+</button><button type="button" class="button" data-sn-zoom="out" aria-label="Riduci">−</button><button type="button" class="button" data-sn-zoom="fit">Adatta</button></div></div>';
        echo '<div class="sn-graph-layout"><div class="sn-graph-stage" id="sn-graph-stage"><svg id="sn-graph" role="group" aria-label="Mappa dei nuclei di plugin" xmlns="http://www.w3.org/2000/svg"></svg><p class="sn-graph-fallback">La mappa interattiva richiede JavaScript: usa la vista Albero.</p><p class="sn-graph-help">Trascina per spostarti · Ctrl/⌘ + rotella o pizzico per lo zoom · clic su un nodo per i dettagli</p></div>';
        echo '<aside class="sn-inspector" id="sn-inspector" aria-live="polite"><div class="sn-inspector-empty"><strong>Seleziona un plugin</strong><p>Clicca un nodo per vedere versioni, origine, perché è bloccato e cosa sblocca.</p>';
        echo '<ul class="sn-legend-list"><li><i class="sn-ldot sn-st-bottleneck"></i>Collo di bottiglia / bloccato</li><li><i class="sn-ldot sn-st-held"></i>Trattenuto dagli addon</li><li><i class="sn-ldot sn-st-warn"></i>Da coordinare / verificare</li><li><i class="sn-ldot sn-st-update"></i>Aggiornabile</li><li><i class="sn-ldot sn-st-license"></i>Licenza da sistemare</li><li><i class="sn-ldot sn-st-manual"></i>Premium: aggiornamento manuale</li><li><i class="sn-ldot sn-st-abandoned"></i>Chiuso o non più mantenuto</li><li><i class="sn-ldot sn-st-blind"></i>Versione più recente sconosciuta</li><li><i class="sn-ldot sn-st-ok"></i>Allineato</li><li><i class="sn-ldot sn-ring-premium"></i>Bordo tratteggiato: fuori libreria WordPress.org</li><li><i class="sn-ldot sn-ring-vuln"></i>Anello rosso: vulnerabilità note</li></ul></div></aside></div>';
        echo '</div>';

        // Vista albero (HTML puro, accessibile e stampabile).
        $nodes = array(); $children = array();
        foreach ($graph['nodes'] as $n) { $nodes[$n['id']] = $n; }
        foreach ($nodes as $id => $n) { if ($n['parent'] !== '') { $children[$n['parent']][] = $id; } }
        $edge_note = array();
        foreach ($graph['edges'] as $e) { $edge_note[$e['s'] . '>' . $e['t']] = $e; }
        echo '<div class="sn-tabpanel" id="sn-tab-tree" role="tabpanel" aria-labelledby="sn-tabbtn-tree" hidden>';
        if (!$graph['clusters']) { echo '<p class="sn-muted">Nessun collegamento rilevato tra i plugin di questo sito.</p>'; }
        foreach ($graph['clusters'] as $c) {
            $open = in_array($c['verdictTone'], array('danger', 'warn'), true);
            echo '<details class="sn-tree-cluster sn-tone-' . esc_attr($c['verdictTone']) . '"' . ($open ? ' open' : '') . '><summary><span class="sn-tree-title"><strong>' . self::e($c['name']) . '</strong><small>' . count($c['members']) . ' plugin · ' . self::e($c['verdict']) . '</small></span>' . self::sn_badge(Integra_Sentinel_Dependencies::status_label($c['worst']), self::sn_status_tone($c['worst'])) . '</summary>';
            self::sn_tree_branch($c['root'], $nodes, $children, $edge_note, 0);
            echo '</details>';
        }
        $solo = array();
        foreach ($nodes as $n) { if ($n['role'] === 'solo') { $solo[] = $n; } }
        if ($solo) {
            usort($solo, function($a, $b) {
                return ((Integra_Sentinel_Dependencies::NODE_RANK[$b['status']] ?? 0) <=> (Integra_Sentinel_Dependencies::NODE_RANK[$a['status']] ?? 0)) ?: strcasecmp($a['name'], $b['name']);
            });
            echo '<details class="sn-tree-cluster sn-tone-muted"><summary><span class="sn-tree-title"><strong>Plugin indipendenti</strong><small>' . count($solo) . ' plugin senza dipendenze rilevate</small></span></summary><ul class="sn-tree">';
            foreach ($solo as $n) { self::sn_tree_line($n, null); echo '</li>'; }
            echo '</ul></details>';
        }
        echo '</div>';

        // Vista piano.
        echo '<div class="sn-tabpanel" id="sn-tab-plan" role="tabpanel" aria-labelledby="sn-tabbtn-plan" hidden>';
        if (!$graph['plan']) { echo '<p class="sn-muted">Nessun aggiornamento in sospeso: il sito è allineato con le versioni note.</p>'; }
        else {
            echo '<ol class="sn-plan">';
            foreach ($graph['plan'] as $i => $phase) {
                echo '<li class="sn-phase sn-tone-' . esc_attr($phase['tone'] ?? 'brand') . '"><div class="sn-phase-head"><span class="sn-phase-num">' . ($i + 1) . '</span><div><strong>' . self::e($phase['title']) . '</strong><small>' . self::e($phase['hint']) . '</small></div></div><ul>';
                foreach ($phase['items'] as $it) {
                    $is_env = strpos($it['id'], 'env:') === 0;
                    echo '<li><span class="sn-ldot sn-st-' . esc_attr($it['tone']) . '"></span><span class="sn-plan-main">' . ($is_env ? '<strong>' . self::e($it['name']) . '</strong>' : '<a href="' . esc_url('#' . self::sn_node_anchor($it['id'])) . '"><strong>' . self::e($it['name']) . '</strong></a>') . ' ' . self::sn_version_step($it['from'], $it['to']) . ($it['vuln'] ? ' ' . self::sn_badge('Sicurezza', 'danger') : '') . ($it['text'] !== '' ? '<small>' . self::e($it['text']) . '</small>' : '') . '</span></li>';
                }
                echo '</ul></li>';
            }
            echo '</ol><p class="sn-hint">Piano calcolato dagli header dichiarati dai plugin e dalle versioni note: eseguire sempre un backup e, per i nuclei con addon, una prova in staging.</p>';
        }
        echo '</div>';

        // Dati per il JS: JSON non eseguibile, con caratteri HTML codificati.
        $payload = array('nodes' => $graph['nodes'], 'edges' => $graph['edges'], 'clusters' => $graph['clusters']);
        echo '<script type="application/json" id="sn-graph-data">' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>';
        echo '</section>';
    }

    private static function sn_tree_branch($id, $nodes, $children, $edge_note, $depth) {
        if ($depth > 6 || !isset($nodes[$id])) { return; }
        echo '<ul class="sn-tree' . ($depth ? ' sn-tree-sub' : '') . '">';
        $n = $nodes[$id];
        $edge = $n['parent'] !== '' ? ($edge_note[$id . '>' . $n['parent']] ?? null) : null;
        self::sn_tree_line($n, $edge);
        $kids = $children[$id] ?? array();
        usort($kids, function($a, $b) use ($nodes) {
            return ((Integra_Sentinel_Dependencies::NODE_RANK[$nodes[$b]['status']] ?? 0) <=> (Integra_Sentinel_Dependencies::NODE_RANK[$nodes[$a]['status']] ?? 0)) ?: strcasecmp($nodes[$a]['name'], $nodes[$b]['name']);
        });
        foreach ($kids as $kid) { self::sn_tree_branch($kid, $nodes, $children, $edge_note, $depth + 1); }
        echo '</li></ul>';
    }

    /** Apre un <li> lasciandolo aperto: il chiamante lo chiude dopo eventuali figli. */
    private static function sn_tree_line($n, $edge) {
        $link = $n['role'] === 'missing' ? '<strong>' . self::e($n['name']) . '</strong>' : '<a href="' . esc_url('#' . self::sn_node_anchor($n['id'])) . '"><strong>' . self::e($n['name']) . '</strong></a>';
        echo '<li class="sn-tree-item' . (!$n['active'] && $n['role'] !== 'missing' ? ' is-inactive' : '') . '"><div class="sn-tree-row"><span class="sn-ldot sn-st-' . esc_attr($n['status']) . '"></span><span class="sn-tree-main">' . $link . ' ' . self::sn_version_step($n['ver'], $n['new'] !== '' ? $n['new'] : ($n['latest'] !== '' ? $n['latest'] : '')) . (!$n['active'] && $n['role'] !== 'missing' ? ' <em>inattivo</em>' : '');
        if ($edge) {
            echo '<small class="sn-edge-note sn-edge-' . esc_attr($edge['state']) . '">' . self::e(Integra_Sentinel_Dependencies::conf_label($edge['conf'])) . (!empty($edge['notes']) ? ' · ' . self::e($edge['notes'][0]) : '') . '</small>';
        } elseif ($n['reasons']) {
            echo '<small class="sn-edge-note">' . self::e($n['reasons'][0]) . '</small>';
        }
        echo '</span><span class="sn-tree-flags">';
        if ($n['origin'] !== 'wporg' && $n['origin'] !== 'missing') { echo self::sn_badge($n['envato'] ? 'Envato' : $n['originLabel'], self::sn_origin_tone($n['origin'])); }
        if (!empty($n['vuln']['count'])) { echo self::sn_badge((int) $n['vuln']['count'] . ' CVE', $n['vuln']['tier'] ?: 'danger'); }
        echo self::sn_badge($n['statusLabel'], self::sn_status_tone($n['status'])) . '</span></div>';
    }

    /** Colli di bottiglia del sito: dipendenze, licenze, server, temi, integrita' e pulizia, con chi deve agire. */
    private static function sn_bottleneck_panel($analysis) {
        $list = $analysis['bottlenecks'];
        $kinds = Integra_Sentinel_Fleet::kinds();
        $owners = Integra_Sentinel_Fleet::owners();
        $center = admin_url('admin.php?page=integra-sentinel-bottlenecks');
        echo '<section class="sn-panel sn-bottlenecks" id="sn-bottlenecks"><div class="sn-section-head"><div><h3>Colli di bottiglia</h3><p>Tutto ciò che impedisce o complica gli aggiornamenti del sito, in ordine di impatto, con chi deve agire.</p></div><span class="sn-counter">' . count($list) . '</span></div>';
        if (!$list) {
            echo '<div class="sn-all-clear"><span aria-hidden="true">✓</span><p><strong>Nessun collo di bottiglia</strong>Licenze, addon, server, temi e versioni non bloccano gli aggiornamenti nei dati disponibili.</p></div>';
        }
        $shown = 0;
        foreach ($list as $b) {
            if ($shown++ >= 10) { break; }
            $node = (string) ($b['node'] ?? '');
            $target = ($node !== '' && strpos($node, 'env:') !== 0) ? self::sn_node_anchor($node) : '';
            $tone = array('critical' => 'critical', 'high' => 'high', 'medium' => 'warn', 'low' => 'premium')[$b['severity']] ?? 'muted';
            echo '<article class="sn-bneck sn-bneck-' . esc_attr($b['severity']) . '"><div class="sn-bneck-top"><span class="sn-bneck-tags">' . self::sn_badge($kinds[$b['kind']]['label'] ?? 'Da verificare', $tone)
                . (!empty($b['security']) ? ' ' . self::sn_badge('Sicurezza', 'danger') : '') . ' <span class="sn-owner"><i class="sn-owner-dot sn-owner-' . esc_attr($b['owner']) . '" aria-hidden="true"></i>' . self::e($owners[$b['owner']] ?? 'Agenzia') . '</span></span>';
            if ($node !== '') { echo '<button type="button" class="sn-linkbtn" data-sn-focus="' . esc_attr($node) . '">Mostra sulla mappa</button>'; }
            echo '</div><h4>' . ($target ? '<a href="' . esc_url('#' . $target) . '">' . self::e($b['title']) . '</a>' : self::e($b['title'])) . '</h4><p>' . self::e($b['detail']) . '</p><p class="sn-bneck-action"><b>Azione:</b> ' . self::e($b['action']) . '</p>';
            echo '<p class="sn-bneck-links"><a href="' . esc_url($center . '#sn-g-' . Integra_Sentinel_Fleet::group_key($b)) . '">Crea intervento o approva eccezione →</a> <span>~' . self::e(Integra_Sentinel_Admin::minutes_text((int) $b['minutes'])) . '</span></p></article>';
        }
        if (count($list) > 10) { echo '<p class="sn-hint">Altri ' . (count($list) - 10) . ' elementi nella pagina <a href="' . esc_url($center) . '">Colli di bottiglia</a>.</p>'; }
        if ($analysis['waived']) { echo '<p class="sn-hint">' . count($analysis['waived']) . ' eccezioni approvate escluse da questo elenco · <a href="' . esc_url($center . '#sn-waivers') . '">vedi</a></p>'; }
        echo '</section>';
    }

    private static function sn_origin_panel($graph) {
        $s = $graph['summary'];
        $o = $s['origins'];
        echo '<section class="sn-panel sn-origins"><div class="sn-section-head"><div><h3>Origine dei plugin</h3><p>Da dove arrivano gli aggiornamenti: libreria ufficiale, fornitori premium o nessun canale.</p></div>' . self::sn_badge((int) $s['premium'] . ' premium · ' . (int) $s['envato'] . ' Envato', 'premium') . '</div>';
        $parts = array('wporg' => $o['wporg'], 'vendor' => $o['vendor'], 'bundled' => $o['bundled'], 'nochannel' => $o['nochannel'], 'custom' => $o['custom'], 'unknown' => $o['unknown']);
        self::sn_chart($parts, array_sum($parts), array('wporg'=>'WordPress.org','vendor'=>'Premium con updater','bundled'=>'Inclusi nel tema','nochannel'=>'Senza canale (manuale)','custom'=>'Su misura','unknown'=>'Non rilevato'));
        $rows = array();
        foreach ($graph['nodes'] as $n) {
            if (in_array($n['origin'], array('wporg', 'internal', 'env', 'missing'), true)) { continue; }
            $rows[] = $n;
        }
        $order = array('nochannel' => 0, 'bundled' => 1, 'vendor' => 2, 'custom' => 3, 'unknown' => 4);
        usort($rows, function($a, $b) use ($order) {
            $ma = $a['latest'] !== '' ? 0 : 1; $mb = $b['latest'] !== '' ? 0 : 1;
            return ($ma <=> $mb) ?: (($order[$a['origin']] ?? 9) <=> ($order[$b['origin']] ?? 9)) ?: strcasecmp($a['name'], $b['name']);
        });
        if (!$rows) { echo '<p class="sn-hint">Tutti i plugin identificati provengono dalla libreria WordPress.org.</p></section>'; return; }
        echo '<ul class="sn-origin-list">';
        foreach (array_slice($rows, 0, 14) as $n) {
            $avail = $n['new'] !== '' ? $n['new'] : $n['latest'];
            echo '<li><div><a href="' . esc_url('#' . self::sn_node_anchor($n['id'])) . '"><strong>' . self::e($n['name']) . '</strong></a> ' . ($n['envato'] ? self::sn_badge('Envato', 'premium') : '') . '<small>' . self::e($n['originLabel']) . ($n['originHint'] !== '' ? ' · ' . self::e($n['originHint']) : '') . '</small></div>';
            echo '<div class="sn-origin-ver">' . self::sn_version_step($n['ver'], $avail);
            if ($n['new'] === '' && $n['latest'] !== '') {
                echo '<small>' . self::e($n['latestSrc']) . ($n['catalogUrl'] !== '' ? ' · <a href="' . esc_url($n['catalogUrl']) . '" target="_blank" rel="noopener noreferrer">fornitore ↗</a>' : '') . '</small>';
            } elseif ($n['new'] === '' && in_array($n['origin'], array('nochannel', 'unknown', 'custom'), true)) {
                echo '<small>Ultima versione non nota</small>';
            }
            echo '</div></li>';
        }
        echo '</ul>';
        if (count($rows) > 14) { echo '<p class="sn-hint">Altri ' . (count($rows) - 14) . ' plugin fuori libreria nell\'elenco completo qui sotto (filtro “Fuori libreria”).</p>'; }
        echo '<p class="sn-hint">Le versioni dei premium senza canale arrivano dal catalogo Integra (impostazioni), dai pacchetti inclusi nei temi e dalle versioni viste su altri siti del parco.</p></section>';
    }

    private static function detail($site) {
        $r = (array) ($site['report'] ?? array());
        $cache = (array) get_option(self::FEEDS, array());
        $m = self::sn_metrics($site, $cache);
        $meta = (array) ($site['meta'] ?? array());
        $admin = current_user_can('manage_options');
        $prev_month = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
        $chips = '';
        foreach ((array) ($meta['tags'] ?? array()) as $tag) { $chips .= self::sn_badge('#' . $tag, 'muted') . ' '; }
        if (isset($meta['ring'])) { $chips .= self::sn_badge('Ondata ' . (int) $meta['ring'], 'brand') . ' '; }
        if (!empty($meta['contract'])) { $chips .= self::sn_badge($meta['contract'], 'premium') . ' '; }
        if (!empty($meta['panel_url'])) { $chips .= '<a class="sn-linkbtn" href="' . esc_url($meta['panel_url']) . '" target="_blank" rel="noopener noreferrer">Pannello hosting' . (!empty($meta['hosting']) ? ' · ' . self::e($meta['hosting']) : '') . ' ↗</a>'; }
        echo '<section class="sn-page-heading sn-detail-title"><div><p class="sn-kicker">DETTAGLIO SITO</p><h2>' . self::e($site['label']) . '</h2><p><a href="' . esc_url($site['url']) . '" target="_blank" rel="noopener noreferrer">' . self::e($site['url']) . ' ↗</a> · Ultimo report ' . (!empty($site['last_seen']) ? self::e(wp_date('d/m/Y H:i', (int) $site['last_seen'])) : 'mai ricevuto') . (!empty($r['client_version']) ? ' · Client v' . self::e($r['client_version']) : '') . '</p>'
            . ($chips !== '' ? '<p class="sn-detail-chips">' . $chips . '</p>' : '') . '</div><div class="sn-detail-actions">' . self::sn_badge(self::sn_state_label($m['state']), $m['state'])
            . ($r ? '<a class="button" target="_blank" rel="noopener" href="' . esc_url(Integra_Sentinel_Admin::document_url('monthly', $site['id'], $prev_month)) . '">Report mensile ↗</a><a class="button" target="_blank" rel="noopener" href="' . esc_url(Integra_Sentinel_Admin::document_url('onboarding', $site['id'])) . '">Audit di presa in carico ↗</a>' : '') . '</div></section>';
        Integra_Sentinel_Admin_Pages::pairing_notice($site);
        if (!$r) {
            echo '<div class="sn-panel sn-empty"><h3>In attesa del primo inventario</h3><p>Installa Integra Sentinel Client sul sito, poi in Impostazioni → Integra Sentinel usa il collegamento rapido con il codice di abbinamento (oppure le credenziali manuali).</p>';
            if ($admin) {
                Integra_Sentinel_Admin::form_start('pair_code', 'integra-sentinel', array('site_id' => $site['id'], '_sn_site' => $site['id']));
                echo '<p><button class="button button-primary" type="submit">Genera codice di abbinamento</button></p></form>';
            }
            echo '</div>';
            Integra_Sentinel_Admin_Pages::site_meta_form($site);
            if ($admin) { echo '<details class="sn-config sn-panel"><summary><strong>Gestione sito</strong></summary><div class="sn-actions">'; self::button('rotate', 'Rigenera chiave', $site['id']); self::button('delete', 'Rimuovi sito', $site['id']); echo '</div></details>'; }
            return;
        }
        $coverage = $m['coverage'];
        $analysis = Integra_Sentinel_Analysis::site($site, $m['groups']);
        $graph = $analysis['graph'];
        $gs = $graph['summary'];
        $s = $analysis['summary'];
        echo '<div class="sn-stats sn-stats-5 sn-detail-stats">';
        self::sn_stat($m['affected'], 'Componenti vulnerabili', $m['vulnerabilities'] . ' advisory · ' . $m['integrity_count'] . ' anomalie integrità', ($m['affected'] || $m['integrity_count']) ? 'danger' : 'clear');
        self::sn_stat($s['ready'], 'Aggiornabili subito', $s['blocked'] . ' bloccati · ' . $s['updates'] . ' disponibili' . ($s['quarantined'] ? ' · ' . $s['quarantined'] . ' in quarantena' : ''), 'ok');
        self::sn_stat($s['bottlenecks'], 'Colli di bottiglia', $s['security'] ? $s['security'] . ' trattengono patch di sicurezza' : 'lavoro stimato ~' . Integra_Sentinel_Admin::minutes_text($s['minutes']), $s['bottlenecks'] ? 'danger' : 'clear');
        self::sn_stat($gs['premium'], 'Fuori libreria WordPress', $s['license'] . ' licenze · ' . $s['manual'] . ' manuali · ' . $s['blind'] . ' ciechi', 'premium');
        self::sn_stat($coverage['ok'] . '/' . $coverage['total'], 'Controlli API riusciti', 'PHP ' . ($r['php'] ?: '—') . ' · WordPress ' . $r['wp'], 'brand');
        echo '</div>';
        $freeze = Integra_Sentinel_Settings::active_freeze((array) ($meta['tags'] ?? array()));
        if ($freeze) { echo '<div class="sn-freeze"><strong>Periodo di blocco: ' . self::e($freeze['label']) . '</strong> · fino al ' . self::e(wp_date('d/m/Y', strtotime($freeze['to'] . ' 12:00:00'))) . '. Solo interventi di sicurezza.</div>'; }
        if (!empty($meta['window']) || !empty($meta['notes'])) {
            echo '<div class="sn-site-notes">' . (!empty($meta['window']) ? '<span><strong>Finestra di manutenzione:</strong> ' . self::e($meta['window']) . '</span>' : '') . (!empty($meta['notes']) ? '<span><strong>Note:</strong> ' . self::e(wp_trim_words($meta['notes'], 40)) . '</span>' : '') . '</div>';
        }

        // Pannello rosso di Allarme Integrità / Intrusione (se ci sono anomalie).
        $int_list = (array) ($site['integrity_findings'] ?? array());
        if ($int_list) {
            echo '<div class="sn-integrity-alert"><h3>&#9888; Allarme Integrità File, Database e Amministratori (' . count($int_list) . ')</h3><ul>';
            foreach ($int_list as $if) {
                echo '<li><strong>' . self::e($if['category'] ?? 'Anomalia') . ':</strong> <code>' . self::e($if['detail'] ?? '') . '</code></li>';
            }
            echo '</ul><p class="sn-hint" style="margin-top:8px;color:#731c28">Se hai effettuato tu modifiche manuali legittime via FTP a file del tema o wp-config.php, apri Impostazioni → Integra Sentinel sul sito Client e clicca su &ldquo;Approva modifiche file e rigenera Baseline Hash&rdquo;. I file del core diversi dai checksum ufficiali vanno invece reinstallati.</p></div>';
        }

        // Mappa delle dipendenze, colli di bottiglia e origine dei plugin.
        self::sn_dependency_section($graph);
        echo '<div class="sn-main-grid sn-deps-grid">';
        self::sn_bottleneck_panel($analysis);
        self::sn_origin_panel($graph);
        echo '</div>';
        echo '<div class="sn-main-grid">';
        Integra_Sentinel_Admin_Pages::environment_panel($analysis);
        Integra_Sentinel_Admin_Pages::themes_panel($analysis);
        echo '</div>';

        // Griglia di telemetria operativa (SEO, SSL, Hardening/Admin, Errori PHP).
        $sec = (array) ($r['security'] ?? array());
        $ssl_days = $site['ssl_days_left'] ?? null;
        echo '<div class="sn-env-grid">';
        echo '<div class="sn-env-card"><strong>Indicizzazione SEO</strong>' . (!isset($sec['blog_public']) || $sec['blog_public'] ? self::sn_badge('Indicizzabile (OK)', 'ok') : self::sn_badge('BLOCCATO (noindex)', 'danger')) . '</div>';
        echo '<div class="sn-env-card"><strong>Certificato SSL</strong>' . ($ssl_days === null ? self::sn_badge('Al prossimo audit', 'muted') : ($ssl_days < 14 ? self::sn_badge('Scade in ' . (int) $ssl_days . 'gg', 'danger') : self::sn_badge('Valido (' . (int) $ssl_days . ' giorni)', 'ok'))) . '</div>';
        $adm_count = count((array) ($sec['admins'] ?? array()));
        $fedit = !empty($sec['file_edit_allowed']);
        echo '<div class="sn-env-card"><strong>Hardening &amp; Admin</strong>' . ($adm_count ? '<b>' . $adm_count . ' admin</b> · ' : '') . ($fedit ? self::sn_badge('Editor PHP attivo', 'warn') : self::sn_badge('DISALLOW_FILE_EDIT', 'ok')) . '</div>';
        $dlog = (int) ($sec['debug_log_kb'] ?? 0);
        echo '<div class="sn-env-card"><strong>Log &amp; Fatal Error</strong>' . ($dlog > 0 ? self::sn_badge('debug.log ' . $dlog . ' KB', 'warn') : self::sn_badge('Nessun debug.log', 'ok')) . (!empty($sec['last_fatal']) ? '<div style="font-size:11px;color:#a91f36;margin-top:4px">' . self::e($sec['last_fatal']) . '</div>' : '') . '</div>';
        echo '</div>';

        $warnings = self::sn_site_issues($site, $m, $analysis);
        if ($warnings) {
            echo '<div class="sn-warning-panel"><h3>Attenzioni operative</h3><ul>';
            foreach ($warnings as $warning) { echo '<li>' . self::e($warning) . '</li>'; }
            echo '</ul></div>';
        }
        echo '<div class="sn-main-grid sn-detail-summary"><section class="sn-panel"><div class="sn-section-head"><div><h3>Da affrontare prima</h3><p>Componenti ordinati per gravità massima nota, non per numero di righe del database.</p></div></div>';
        if (!$m['groups']) { echo '<p class="sn-muted">Nessuna vulnerabilità applicabile nei risultati disponibili.</p>'; }
        $shown = 0;
        foreach ($m['groups'] as $g) {
            if ($shown++ >= 6) { break; }
            $installed = ''; $target = '';
            foreach ($r['plugins'] as $p) { if ($g['type'] === 'plugin' && $p['slug'] === $g['slug']) { $installed = $p['version']; $target = $p['update']['version'] ?? ''; break; } }
            if (!$installed) { foreach ($r['themes'] as $t) { if ($g['type'] === 'theme' && $t['slug'] === $g['slug']) { $installed = $t['version']; $target = $t['update'] ?? ''; break; } } }
            if ($g['type'] === 'core') { $installed = $r['wp']; $target = $r['core_update'] ?? ''; }
            $anchor_id = $g['type'] === 'core' ? 'sn-plugin-core' : 'sn-plugin-' . sanitize_html_class($g['slug']);
            echo '<a class="sn-priority" href="' . esc_url('#' . $anchor_id) . '"><span class="sn-priority-number">' . $shown . '</span><span class="sn-priority-copy"><strong>' . self::e($g['name']) . '</strong><small>' . self::e($installed) . ($target ? ' → ' . self::e($target) : ' · verificare soluzione') . ' · ' . $g['count'] . ' advisory</small></span>' . self::sn_badge(self::sn_severity($g['max_tier']), $g['max_tier']) . '</a>';
        }
        echo '</section><section class="sn-panel"><div class="sn-section-head"><div><h3>Gravità per componente</h3><p>Ogni componente è contato una volta, in base al suo score più alto.</p></div></div>';
        self::sn_chart($m['tiers'], $m['affected'], array('critical'=>'Critica','high'=>'Alta','medium'=>'Media','low'=>'Bassa','unknown'=>'Non classificata'));
        echo '<div class="sn-coverage"><div><strong>Copertura WPVulnerability</strong><span>' . $coverage['ok'] . ' verificati su ' . $coverage['total'] . ' interrogabili</span></div>';
        echo '<div class="sn-progress"><div style="width:' . esc_attr($coverage['total'] ? number_format($coverage['ok'] / $coverage['total'] * 100, 2, '.', '') : '0') . '%"></div></div>';
        if ($coverage['bad'] || $coverage['waiting'] || $coverage['stale'] || $coverage['identity_review']) {
            echo '<p class="sn-hint">' . $coverage['bad'] . ' controlli non riusciti · ' . $coverage['waiting'] . ' in attesa · ' . $coverage['stale'] . ' verifiche oltre 24 ore · ' . $coverage['identity_review'] . ' identità da verificare; ' . $coverage['internal'] . ' componenti interni fuori dai feed esterni.</p>';
        } else { echo '<p class="sn-hint">Interrogazioni completate per i componenti identificati; ' . $coverage['internal'] . ' componenti interni non inclusi nei feed. Il risultato dipende dalle fonti esterne.</p>'; }
        echo '</div></section></div>';
        if (Integra_Sentinel_Settings::can_manage()) {
            echo '<section class="sn-panel sn-scan"><div><h3>Controllo delle vulnerabilità e SSL</h3><p>Il Master interroga i database di vulnerabilità per WordPress, temi e plugin del sito e verifica il certificato HTTPS. Nessuna azione sul sito.</p></div><div><button type="button" class="button button-primary" id="sn-scan-now" data-site="' . esc_attr($site['id']) . '" data-nonce="' . esc_attr(wp_create_nonce('integra_sentinel_scan_site')) . '">Controlla adesso</button><div id="sn-scan-progress" role="status" aria-live="polite"></div></div></section>';
        }
        Integra_Sentinel_Admin_Pages::probe_panel($site);
        Integra_Sentinel_Admin_Pages::requests_panel($site);
        $suspected = self::sn_group_findings((array) ($site['possible_findings'] ?? array()));
        if ($suspected) {
            echo '<section class="sn-panel"><div class="sn-section-head"><div><h3>Corrispondenze da confermare</h3><p>Advisory associati allo slug di componenti con Update URI esterno non identificati con sufficiente certezza. Non sono conteggiati come vulnerabilità confermate.</p></div></div>';
            foreach ($suspected as $g) {
                echo '<details class="sn-nested-details"><summary>' . self::e($g['name']) . ' · ' . (int) $g['count'] . ' advisory potenziali</summary>';
                self::sn_advisory_rows($g['items']);
                echo '</details>';
            }
            echo '</section>';
        }
        $records = self::sn_plugin_records($site, $cache, $m['groups'], $graph);
        $gnodes = array();
        foreach ($graph['nodes'] as $gn) { $gnodes[$gn['id']] = $gn; }
        echo '<section class="sn-section sn-plugin-section" id="sn-plugins"><div class="sn-section-head"><div><h3>Plugin installati <span class="sn-title-count">' . count($records) . '</span></h3><p>Un plugin per scheda. Apri per vedere le CVE, le dipendenze e i passaggi consigliati.</p></div></div>';
        echo '<div class="sn-plugin-tools"><label class="sn-search"><span aria-hidden="true">⌕</span><input type="search" id="sn-plugin-search" placeholder="Cerca un plugin…" aria-label="Cerca plugin"></label><div class="sn-filters" aria-label="Filtri plugin">';
        foreach (array('all' => 'Tutti', 'risk' => 'Vulnerabili', 'update' => 'Da aggiornare', 'review' => 'Da verificare', 'bottleneck' => 'Colli di bottiglia', 'license' => 'Licenze', 'premium' => 'Fuori libreria', 'clean' => 'Senza vulnerabilità note') as $key => $label) {
            echo '<button type="button" class="sn-filter' . ($key === 'all' ? ' is-selected' : '') . '" data-sn-filter="' . esc_attr($key) . '" aria-pressed="' . ($key === 'all' ? 'true' : 'false') . '">' . self::e($label) . '</button>';
        }
        echo '</div></div>';
        echo '<div id="sn-plugin-list" class="sn-plugin-list">';
        foreach ($records as $rec) {
            $p = $rec['plugin']; $g = $rec['group']; $state = $rec['state']; $a = $rec['assessment']; $update = $p['update']['version'] ?? '';
            $tier = $g ? $g['max_tier'] : 'clear'; $risk = $g ? $g['count'] : 0;
            $gn = $gnodes[$p['slug']] ?? null;
            $filter = ($risk ? ' risk' : '') . ($update ? ' update' : '') . (($state['state'] !== 'ok' || ($update && $a['class'] !== 'green')) ? ' review' : '') . (!$risk && $state['state'] === 'ok' ? ' clean' : '')
                . ($gn && in_array($gn['status'], array('bottleneck', 'blocked', 'held', 'license'), true) ? ' bottleneck' : '') . ($gn && $gn['license'] !== '' ? ' license' : '')
                . ($gn && !in_array($gn['origin'], array('wporg', 'internal'), true) ? ' premium' : '');
            echo '<details class="sn-plugin-entry sn-entry-' . esc_attr($risk ? $tier : ($state['state'] !== 'ok' ? 'review' : 'clear')) . '" id="sn-plugin-' . esc_attr(sanitize_html_class($p['slug'])) . '" data-sn-name="' . esc_attr(strtolower($p['name'] . ' ' . $p['slug'])) . '" data-sn-tags="' . esc_attr(trim($filter)) . '"><summary>';
            echo '<span class="sn-plugin-icon" aria-hidden="true">' . self::e(strtoupper(substr(trim((string) $p['name']), 0, 1))) . '</span><span class="sn-plugin-main"><strong>' . self::e($p['name']) . '</strong><small>' . self::e($p['version']) . ($update ? ' → ' . self::e($update) : '') . ' · ' . ($p['active'] ? 'Attivo' : 'Inattivo') . ($gn && $gn['auto'] ? ' · aggiornamento automatico' : '') . '</small></span><span class="sn-plugin-flags">';
            if ($risk) { echo self::sn_badge($risk . ' vulnerabilità', $tier); }
            elseif ($state['state'] === 'ok') { echo self::sn_badge('Nessuna nota', 'ok'); }
            else { echo self::sn_badge($state['label'], $state['tone']); }
            if ($gn && !in_array($gn['origin'], array('wporg', 'internal'), true)) { echo self::sn_badge($gn['envato'] ? 'Envato' : 'Fuori libreria', self::sn_origin_tone($gn['origin'])); }
            if ($gn && in_array($gn['status'], array('bottleneck', 'blocked', 'held', 'manual', 'license', 'blind', 'abandoned', 'warn'), true)) { echo self::sn_badge($gn['statusLabel'], self::sn_status_tone($gn['status'])); }
            elseif ($update && $a['class'] === 'red') { echo self::sn_badge('Bloccato', 'danger'); }
            elseif ($update && $a['class'] === 'yellow') { echo self::sn_badge('Verificare compatibilità', 'warn'); }
            elseif ($update) { echo self::sn_badge('Aggiornamento', 'brand'); }
            echo '</span><span class="sn-entry-arrow" aria-hidden="true">⌄</span></summary><div class="sn-plugin-body">';
            echo '<div class="sn-plugin-body-top"><div><strong>Valutazione aggiornamento</strong><p>' . self::e($a['label']) . ($update ? ' · versione proposta ' . self::e($update) : '') . '</p></div><div><strong>Controllo vulnerabilità</strong><p>' . self::e($state['label']) . '</p></div></div>';
            if ($gn) {
                $avail = $gn['new'] !== '' ? $gn['new'] : $gn['latest'];
                echo '<div class="sn-plugin-body-top sn-plugin-origin"><div><strong>Origine e canale</strong><p>' . self::e($gn['originLabel']) . ($gn['originHint'] !== '' ? ' · ' . self::e($gn['originHint']) : '') . '</p>' . (!empty($p['plugin_uri']) ? '<p><a href="' . esc_url($p['plugin_uri']) . '" target="_blank" rel="noopener noreferrer">Pagina del prodotto ↗</a></p>' : '') . '</div>';
                echo '<div><strong>Posizione nei nuclei</strong><p>' . self::e($gn['statusLabel']) . ($gn['parent'] !== '' && isset($gnodes[$gn['parent']]) ? ' · dipende da ' . self::e($gnodes[$gn['parent']]['name']) : ($gn['role'] === 'hub' ? ' · nucleo principale' : ' · indipendente')) . ($avail !== '' && $gn['new'] === '' ? ' · disponibile ' . self::e($avail) . ' (' . self::e($gn['latestSrc']) . ')' : '') . '</p><p><button type="button" class="sn-linkbtn" data-sn-focus="' . esc_attr($gn['id']) . '">Mostra sulla mappa</button> · <a class="sn-linkbtn" href="' . esc_url(admin_url('admin.php?page=integra-sentinel-fleet&product=' . rawurlencode('plugin:' . $p['slug']))) . '">Su tutti i siti</a></p></div></div>';
                if ($gn['reasons']) { echo '<h4>Perché</h4><ul class="sn-note-list">'; foreach ($gn['reasons'] as $reason) { echo '<li>' . self::e($reason) . '</li>'; } echo '</ul>'; }
            }
            if ($rec['notes']) { echo '<h4>Compatibilità e dipendenze</h4><ul class="sn-note-list">'; foreach ($rec['notes'] as $note) { echo '<li>' . self::e($note) . '</li>'; } echo '</ul>'; }
            if ($g) { echo '<h4>Advisory relativi alla versione installata (' . $risk . ')</h4>'; self::sn_advisory_rows($g['items']); }
            elseif ($state['state'] === 'ok') { echo '<p class="sn-hint">Nessuna vulnerabilità applicabile identificata dalla fonte per la versione registrata; non equivale a garanzia di sicurezza.</p>'; }
            else { echo '<p class="sn-hint">Copertura non confermata: non interpretare come assenza di vulnerabilità.</p>'; }
            echo '</div></details>';
        }
        echo '</div><p class="sn-no-results" id="sn-no-results" hidden>Nessun plugin corrisponde alla ricerca o al filtro scelto.</p></section>';
        Integra_Sentinel_Admin_Pages::site_meta_form($site);
        echo '<details class="sn-panel sn-config sn-extra"><summary><span><strong>Temi, MU-Plugins, Amministratori, WPScan, storico e gestione</strong><small>Informazioni complete, storico dei cambi di versione e gestione delle chiavi</small></span><span class="sn-chevron">⌄</span></summary><div class="sn-config-body">';
        $theme_groups = $m['groups'];
        echo '<h3>Temi installati</h3><table class="sn-simple-table"><thead><tr><th>Tema</th><th>Versione</th><th>Stato</th><th>Advisory</th></tr></thead><tbody>';
        foreach ((array) ($r['themes'] ?? array()) as $t) {
            $group = $theme_groups['theme:' . $t['slug']] ?? null;
            echo '<tr id="sn-plugin-' . esc_attr(sanitize_html_class($t['slug'])) . '"><td>' . self::e($t['name']) . '</td><td>' . self::e($t['version']) . (!empty($t['update']) ? ' → ' . self::e($t['update']) : '') . '</td><td>' . ($t['active'] ? 'Attivo' : 'Installato') . '</td><td>' . ($group ? self::sn_badge($group['count'] . ' vulnerabilità', $group['max_tier']) : self::sn_badge('Nessuna nota', 'muted')) . '</td></tr>';
        }
        echo '</tbody></table>';
        foreach ($theme_groups as $g) {
            if ($g['type'] === 'theme' || $g['type'] === 'core') {
                echo '<details class="sn-nested-details" ' . ($g['type'] === 'core' ? 'id="sn-plugin-core"' : '') . '><summary>Advisory ' . self::e($g['name']) . ' (' . $g['count'] . ')</summary>';
                self::sn_advisory_rows($g['items']); echo '</details>';
            }
        }
        if (!empty($r['mu_plugins'])) {
            echo '<h3>Must-Use Plugins (mu-plugins) e Drop-in</h3><table class="sn-simple-table"><thead><tr><th>File</th><th>Nome</th><th>Versione</th></tr></thead><tbody>';
            foreach ((array) $r['mu_plugins'] as $mu) {
                echo '<tr><td><code>' . self::e($mu['file'] ?? '') . '</code></td><td>' . self::e($mu['name'] ?? '') . '</td><td>' . self::e($mu['version'] ?: '—') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!empty($sec['admins'])) {
            echo '<h3>Utenti Amministratori censiti (' . count($sec['admins']) . ')</h3><p class="sn-hint">Solo i nomi utente: le email degli amministratori non lasciano il sito del cliente. Un amministratore nuovo viene segnalato negli alert di integrità.</p><p><code>' . self::e(implode(' · ', array_map(array(__CLASS__, 'mask_admin'), (array) $sec['admins']))) . '</code></p>';
        }
        self::wpscan_detail($site);
        echo '<h3>Storico versioni e sicurezza</h3><p class="sn-hint">Eventi determinati confrontando gli inventari e gli amministratori tra un invio e il successivo (conservati 2 anni).</p>';
        $events = Integra_Sentinel_Store::events($site['id'], 0, 0, 300);
        if (!$events && !empty($site['history'])) {
            foreach (array_reverse((array) $site['history']) as $event) { $events[] = array('at' => (int) $event['at'], 'message' => (string) $event['event']); }
        }
        if (!$events) { echo '<p>Nessun evento registrato.</p>'; }
        else { echo '<div class="sn-table-wrap"><table class="sn-simple-table"><thead><tr><th>Data</th><th>Evento</th></tr></thead><tbody>'; foreach ($events as $event) { echo '<tr><td>' . self::e(wp_date('d/m/Y H:i', (int) $event['at'])) . '</td><td>' . self::e($event['message']) . '</td></tr>'; } echo '</tbody></table></div>'; }
        if ($admin) {
            echo '<h3>Gestione del collegamento</h3><p class="sn-hint">Il codice di abbinamento collega o ricollega il Client senza copiare la chiave. “Rigenera” mantiene valida la chiave precedente per i giorni di tolleranza; “Revoca” la invalida subito (chiave compromessa).</p><div class="sn-actions sn-danger-actions">';
            Integra_Sentinel_Admin::form_start('pair_code', 'integra-sentinel', array('site_id' => $site['id'], '_sn_site' => $site['id']));
            echo '<button class="button" type="submit">Genera codice di abbinamento</button></form>';
            self::button('rotate', 'Rigenera chiave', $site['id']); self::button('revoke', 'Revoca chiave subito', $site['id']); self::button('delete', 'Rimuovi sito', $site['id']);
            echo '</div>';
        }
        echo '</div></details>';
    }
}

add_action('integra_sentinel_master_audit_soon', array('Integra_Sentinel_Master', 'audit'));
register_activation_hook(__FILE__, array('Integra_Sentinel_Master', 'activate'));
register_deactivation_hook(__FILE__, array('Integra_Sentinel_Master', 'deactivate'));
Integra_Sentinel_Master::init();