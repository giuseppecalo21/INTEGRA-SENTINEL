<?php
/**
 * Plugin Name: Integra Sentinel Client
 * Plugin URI: https://integracorporate.it/
 * Description: Inventario WordPress, telemetria di integrità zero-noise e report firmati verso Integra Sentinel Master.
 * Version: 1.8.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Integra
 * Author URI: https://integracorporate.it/
 * Text Domain: integra-sentinel-client
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Client {
    const VERSION  = '1.8.0';
    const OPTION   = 'integra_sentinel_client_settings';
    const LAST     = 'integra_sentinel_client_last';
    const BASELINE = 'integra_sentinel_client_baseline';
    const FATAL    = 'integra_sentinel_client_last_fatal';
    const HOOK     = 'integra_sentinel_client_send';
    const HOOK_UPD = 'integra_sentinel_client_after_update';
    const COUPLING = 'integra_sentinel_client_couplings';
    const WC_TPL   = 'integra_sentinel_client_wc_templates';
    const AUTO     = 'integra_sentinel_client_auto_updated';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_integra_sentinel_client_save', array(__CLASS__, 'save'));
        add_action('admin_post_integra_sentinel_client_pair', array(__CLASS__, 'pair'));
        add_action('admin_post_integra_sentinel_client_sync', array(__CLASS__, 'manual_sync'));
        add_action('admin_post_integra_sentinel_client_rebaseline', array(__CLASS__, 'manual_rebaseline'));
        add_action(self::HOOK, array(__CLASS__, 'send'));
        add_action(self::HOOK_UPD, array(__CLASS__, 'send'));
        add_action('upgrader_process_complete', array(__CLASS__, 'after_update'), 10, 2);
        add_action('automatic_updates_complete', array(__CLASS__, 'after_auto_updates'));
        add_action('shutdown', array(__CLASS__, 'capture_fatal_error'), 999);
    }

    public static function activate() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 120, 'twicedaily', self::HOOK);
        }
        // Inizializza la baseline degli hash alla prima attivazione.
        if (false === get_option(self::BASELINE, false)) {
            self::rebuild_baseline('activation');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::HOOK_UPD);
    }

    /**
     * Quando WordPress completa un aggiornamento legittimo di core, plugin o temi,
     * rigenera la baseline degli hash dei file critici per azzerare i falsi positivi.
     */
    public static function after_update($upgrader, $extra) {
        if (isset($extra['action']) && in_array($extra['action'], array('update', 'install'), true)) {
            self::rebuild_baseline('upgrader:' . sanitize_key($extra['type'] ?? 'unknown'));
            if (!wp_next_scheduled(self::HOOK_UPD)) {
                wp_schedule_single_event(time() + 180, self::HOOK_UPD);
            }
        }
    }

    /**
     * Aggiornamenti applicati da WordPress in automatico: si annotano (tipo, slug, versione) perche' il Master
     * possa distinguerli da quelli fatti dall'agenzia. Si conservano 40 giorni, al massimo 100 voci.
     */
    public static function after_auto_updates($results) {
        $list = (array) get_option(self::AUTO, array());
        foreach (array('plugin', 'theme', 'core') as $type) {
            foreach ((array) ($results[$type] ?? array()) as $r) {
                if (!is_object($r) || $r->result !== true || !is_object($r->item ?? null)) { continue; }
                $item = $r->item;
                if ($type === 'plugin') { $slug = self::plugin_slug((string) ($item->plugin ?? '')); $version = (string) ($item->new_version ?? ''); }
                elseif ($type === 'theme') { $slug = sanitize_title((string) ($item->theme ?? '')); $version = (string) ($item->new_version ?? ''); }
                else { $slug = 'wordpress'; $version = (string) ($item->current ?? ($item->version ?? '')); }
                if ($slug === '' || $version === '') { continue; }
                $list[] = array('type' => $type, 'slug' => $slug, 'version' => substr($version, 0, 40), 'at' => time());
            }
        }
        $cut = time() - 40 * DAY_IN_SECONDS;
        $list = array_values(array_filter($list, function($e) use ($cut) { return is_array($e) && (int) ($e['at'] ?? 0) >= $cut; }));
        update_option(self::AUTO, array_slice($list, -100), false);
    }

    /** Intercetta eventuali errori fatali PHP sul sito cliente per segnalarli al Master. */
    public static function capture_fatal_error() {
        $err = error_get_last();
        if (!is_array($err) || empty($err['type'])) { return; }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
        if (!in_array((int) $err['type'], $fatal_types, true)) { return; }
        $file = isset($err['file']) ? str_replace(normalize_path_safe(ABSPATH), '', normalize_path_safe((string) $err['file'])) : 'unknown';
        $msg  = isset($err['message']) ? substr(sanitize_text_field((string) $err['message']), 0, 240) : 'Fatal error';
        $line = isset($err['line']) ? (int) $err['line'] : 0;
        $sig  = sha1($file . ':' . $line . ':' . $msg);
        $prev = get_option(self::FATAL, array());
        if (is_array($prev) && ($prev['sig'] ?? '') === $sig && time() - (int) ($prev['at'] ?? 0) < 3600) {
            return;
        }
        update_option(self::FATAL, array(
            'sig'  => $sig,
            'at'   => time(),
            'file' => substr($file, 0, 180),
            'line' => $line,
            'msg'  => $msg,
        ), false);
    }

    public static function menu() {
        add_options_page('Integra Sentinel Client', 'Integra Sentinel', 'manage_options', 'integra-sentinel-client', array(__CLASS__, 'page'));
    }

    private static function encryption_key() {
        $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        return hash('sha256', $salt ?: __FILE__, true);
    }

    private static function encrypt_secret($secret) {
        if (!function_exists('openssl_encrypt')) { return ''; }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return false === $cipher ? '' : 'enc:' . base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt_secret($stored) {
        $stored = (string) $stored;
        if ($stored === '') { return ''; }
        // Retrocompatibilità trasparente con chiavi salvate in chiaro dalla v1.1.0.
        if (preg_match('/^[a-f0-9]{64}$/i', $stored)) {
            return strtolower($stored);
        }
        if (strpos($stored, 'enc:') !== 0 || !function_exists('openssl_decrypt')) { return ''; }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return ($plain !== false && preg_match('/^[a-f0-9]{64}$/i', $plain)) ? strtolower($plain) : '';
    }

    private static function config() {
        $cfg = (array) get_option(self::OPTION, array());
        if (!empty($cfg['secret']) && preg_match('/^[a-f0-9]{64}$/i', (string) $cfg['secret'])) {
            $enc = self::encrypt_secret(strtolower((string) $cfg['secret']));
            if ($enc) {
                $cfg['secret'] = $enc;
                update_option(self::OPTION, $cfg, false);
            }
        }
        return $cfg;
    }

    private static function normalize_master($url) {
        $url = untrailingslashit(esc_url_raw(trim((string) $url)));
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            return '';
        }
        return $url;
    }

    public static function save() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('integra_sentinel_client_save');
        $old = self::config();
        $master = self::normalize_master(isset($_POST['master']) ? wp_unslash($_POST['master']) : '');
        $site_id = isset($_POST['site_id']) ? sanitize_text_field(wp_unslash($_POST['site_id'])) : '';
        $secret_input = isset($_POST['secret']) ? trim(sanitize_text_field(wp_unslash($_POST['secret']))) : '';
        $plain_secret = $secret_input !== '' ? strtolower($secret_input) : self::decrypt_secret($old['secret'] ?? '');
        $valid = $master && preg_match('/^[a-f0-9-]{36}$/i', $site_id) && preg_match('/^[a-f0-9]{64}$/i', $plain_secret);
        if ($valid) {
            $stored_secret = self::encrypt_secret($plain_secret);
            if (!$stored_secret) { $stored_secret = $plain_secret; }
            update_option(self::OPTION, array(
                'master'  => $master,
                'site_id' => strtolower($site_id),
                'secret'  => $stored_secret,
            ), false);
        }
        wp_safe_redirect(add_query_arg('integra_msg', $valid ? 'saved' : 'invalid', admin_url('options-general.php?page=integra-sentinel-client')));
        exit;
    }

    public static function manual_sync() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('integra_sentinel_client_sync');
        $result = self::send();
        wp_safe_redirect(add_query_arg('integra_msg', $result ? 'synced' : 'failed', admin_url('options-general.php?page=integra-sentinel-client')));
        exit;
    }

    public static function manual_rebaseline() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('integra_sentinel_client_rebaseline');
        self::rebuild_baseline('manual_admin');
        self::send();
        wp_safe_redirect(add_query_arg('integra_msg', 'rebaselined', admin_url('options-general.php?page=integra-sentinel-client')));
        exit;
    }

    /**
     * Abbinamento con codice monouso generato dal Master (valido 30 minuti, legato all'URL del sito).
     * Il Master restituisce ID e chiave via HTTPS verificato: nessun copia-incolla della chiave.
     */
    public static function pair() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('integra_sentinel_client_pair');
        $redirect = admin_url('options-general.php?page=integra-sentinel-client');
        $master = self::normalize_master(isset($_POST['master']) ? wp_unslash($_POST['master']) : '');
        $code = isset($_POST['pair_code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_POST['pair_code']))) : '';
        $result = self::pair_with($master, $code);
        wp_safe_redirect(add_query_arg(array('integra_msg' => $result['msg'], 'integra_detail' => $result['detail']), $redirect));
        exit;
    }

    /** @return array{msg:string,detail:string} */
    public static function pair_with($master, $code) {
        if (!$master || !preg_match('/^[A-Z0-9]{12}$/', (string) $code)) {
            return array('msg' => 'pair_invalid', 'detail' => '');
        }
        $body = wp_json_encode(array('code' => $code, 'url' => untrailingslashit(home_url('/')), 'client_version' => self::VERSION));
        $endpoints = array(
            $master . '/wp-json/integra-sentinel/v1/pair',
            add_query_arg('rest_route', '/integra-sentinel/v1/pair', trailingslashit($master)),
        );
        $detail = '';
        foreach ($endpoints as $i => $endpoint) {
            $response = wp_safe_remote_post($endpoint, array(
                'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => $body, 'limit_response_size' => 8192,
            ));
            if (is_wp_error($response)) { $detail = 'rete'; break; }
            $http = (int) wp_remote_retrieve_response_code($response);
            if ($http === 404 && $i === 0) { continue; }
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            $site_id = is_array($data) ? (string) ($data['site_id'] ?? '') : '';
            $secret = is_array($data) ? strtolower((string) ($data['secret'] ?? '')) : '';
            if ($http === 200 && preg_match('/^[a-f0-9-]{36}$/i', $site_id) && preg_match('/^[a-f0-9]{64}$/', $secret)) {
                $stored = self::encrypt_secret($secret);
                update_option(self::OPTION, array('master' => $master, 'site_id' => strtolower($site_id), 'secret' => $stored ?: $secret), false);
                return array('msg' => self::send() ? 'paired' : 'paired_nosync', 'detail' => '');
            }
            $detail = is_array($data) && !empty($data['code']) && is_string($data['code']) ? sanitize_key($data['code']) : 'http_' . $http;
            break;
        }
        return array('msg' => 'pair_failed', 'detail' => $detail);
    }

    private static function plugin_slug($file) {
        return sanitize_title(false !== strpos($file, '/') ? dirname($file) : basename($file, '.php'));
    }

    /** Elenco dei file critici di sistema e del tema da sottoporre a controllo hash SHA-256. */
    private static function critical_files_map() {
        $files = array();
        $root_watch = array('index.php', 'wp-config.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'wp-login.php', '.htaccess');
        foreach ($root_watch as $rel) {
            $full = ABSPATH . $rel;
            if (is_file($full) && is_readable($full)) {
                $files['root:' . $rel] = $full;
            }
        }
        // Controllo file critici del tema attivo e dell'eventuale tema genitore.
        $theme_dirs = array_unique(array(get_stylesheet_directory(), get_template_directory()));
        foreach ($theme_dirs as $tdir) {
            if (!$tdir || !is_dir($tdir)) { continue; }
            $tname = basename($tdir);
            foreach (array('functions.php', 'header.php', 'footer.php', 'index.php') as $tfile) {
                $full = trailingslashit($tdir) . $tfile;
                if (is_file($full) && is_readable($full)) {
                    $files['theme:' . $tname . '/' . $tfile] = $full;
                }
            }
        }
        // Tutti i file PHP nella directory mu-plugins.
        if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR) && is_readable(WPMU_PLUGIN_DIR)) {
            $entries = @scandir(WPMU_PLUGIN_DIR);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') { continue; }
                    $full = trailingslashit(WPMU_PLUGIN_DIR) . $entry;
                    if (is_file($full) && is_readable($full) && preg_match('/\.(php|phtml|phar)$/i', $entry)) {
                        $files['mu:' . $entry] = $full;
                    }
                }
            }
        }
        $hashes = array();
        foreach ($files as $label => $path) {
            if (@filesize($path) <= 5242880) {
                $h = @hash_file('sha256', $path);
                if ($h) { $hashes[$label] = $h; }
            }
        }
        return $hashes;
    }

    public static function rebuild_baseline($reason = 'auto') {
        $baseline = array(
            'created_at' => time(),
            'reason'     => sanitize_text_field($reason),
            'hashes'     => self::critical_files_map(),
        );
        update_option(self::BASELINE, $baseline, false);
        return $baseline;
    }

    /**
     * Verifica se un file .php in uploads è un innocuo stub (index.php) o un file dati
     * protetto di un plugin noto (Sucuri, AIOS, Redux, Smile Fonts) privo di payload malevoli.
     */
    private static function is_benign_upload_php($full_path, $rel_path, $filename) {
        $size = (int) @filesize($full_path);
        if ($size > 524288) { return false; } // Oltre 512 KB in uploads non è mai uno stub sicuro.

        $rel_norm = strtolower(str_replace('\\', '/', $rel_path));
        $fname    = strtolower($filename);

        $is_index_stub = ($fname === 'index.php' && $size <= 2048);
        $is_known_plugin_data = (
            preg_match('#^sucuri/sucuri-[a-z0-9_-]+\.php$#', $rel_norm) ||
            preg_match('#^aios/firewall-rules/[a-z0-9_-]+\.php$#', $rel_norm) ||
            preg_match('#^redux/extension_compatibility/[a-z0-9_]+\.php$#', $rel_norm) ||
            preg_match('#^smile_fonts/[a-z0-9_-]+/charmap\.php$#', $rel_norm)
        );

        if (!$is_index_stub && !$is_known_plugin_data) {
            return false;
        }

        $content = (string) @file_get_contents($full_path, false, null, 0, 262144);
        if ($content === '' && $is_index_stub) { return true; }

        // Se un attaccante inietta una backdoor dentro index.php o nei file di Sucuri/Redux,
        // saranno presenti superglobali di input o funzioni di esecuzione/offuscamento.
        $dangerous_pattern = '/(\$_POST|\$_GET|\$_REQUEST|\$_FILES|\$_COOKIE|\$_SERVER\s*\[\s*[\'"]HTTP_|eval\s*\(|base64_decode\s*\(|gzinflate\s*\(|gzuncompress\s*\(|str_rot13\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(|proc_open\s*\(|popen\s*\(|assert\s*\(|create_function\s*\(|move_uploaded_file\s*\(|curl_exec\s*\(|`)/i';

        if (preg_match($dangerous_pattern, $content)) {
            return false; // Contiene codice sospetto: segnala immediatamente!
        }

        // Per gli index.php verifichiamo che, rimossi i commenti PHP e i tag di apertura,
        // non ci sia codice operativo diverso da un semplice exit/die/return.
        if ($is_index_stub) {
            if (preg_match('/\b(include|include_once|require|require_once|file_put_contents|fopen|fwrite)\b/i', $content)) {
                return false;
            }
            $stripped = preg_replace('#/\*.*?\*/#s', '', $content);
            $stripped = preg_replace('#(//|\#).*$#m', '', (string) $stripped);
            $stripped = preg_replace('/(<\?php|<\?|\?>|\s+|;)/i', '', (string) $stripped);
            if ($stripped === '' || preg_match('/^(exit(\(\))?|die(\(\))?|return)$/i', (string) $stripped)) {
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * Scansiona wp-content/uploads/ alla ricerca ESCLUSIVA di file eseguibili (.php, .phtml, .phar, ecc.)
     * o .htaccess con direttive di esecuzione PHP, filtrando gli stub index.php puliti e i file dati verificati.
     */
    private static function scan_uploads_executables() {
        $uploads = wp_upload_dir(null, false);
        $basedir = $uploads['basedir'] ?? '';
        if (!$basedir || !is_dir($basedir) || !is_readable($basedir)) { return array(); }
        $suspects = array();
        $start = microtime(true);
        $visited_dirs = 0;
        $visited_files = 0;
        $queue = array($basedir);

        while (!empty($queue)) {
            if ((microtime(true) - $start) > 1.5 || $visited_dirs > 450 || $visited_files > 12000 || count($suspects) >= 25) {
                break;
            }
            $current_dir = array_shift($queue);
            ++$visited_dirs;
            $items = @scandir($current_dir);
            if (!is_array($items)) { continue; }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') { continue; }
                $full = $current_dir . DIRECTORY_SEPARATOR . $item;
                if (is_link($full)) { continue; }
                if (is_dir($full)) {
                    $queue[] = $full;
                    continue;
                }
                ++$visited_files;
                $rel = ltrim(str_replace(normalize_path_safe($basedir), '', normalize_path_safe($full)), '/');
                if (preg_match('/\.(php\d*|phtml|phar|cgi|pl|py|sh|suspected)$/i', $item)) {
                    if (self::is_benign_upload_php($full, $rel, $item)) {
                        continue;
                    }
                    $suspects[] = 'uploads/' . $rel;
                } elseif (strtolower($item) === '.htaccess' && @filesize($full) < 65536) {
                    $content = (string) @file_get_contents($full, false, null, 0, 8192);
                    if (preg_match('/(AddHandler|SetHandler|AddType\s+application\/x-httpd-php|auto_prepend_file|auto_append_file|php_value)/i', $content)) {
                        $suspects[] = 'uploads/' . $rel . ' (direttiva PHP)';
                    }
                }
                if (count($suspects) >= 25) { break; }
            }
        }
        return $suspects;
    }

    /** Rileva file .php anomali nella root di WordPress non appartenenti al Core standard. */
    private static function scan_unexpected_root_php() {
        $standard_root = array(
            'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
            'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
            'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
            'wp-trackback.php', 'xmlrpc.php',
            // Drop-in / WAF / installer legittimi comuni:
            'wordfence-waf.php', 'malcare-waf.php', 'aios-bootstrap.php',
        );
        $unexpected = array();
        $items = @scandir(ABSPATH);
        if (!is_array($items)) { return array(); }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            if (!preg_match('/\.(php\d*|phtml|phar)$/i', $item)) { continue; }
            if (in_array(strtolower($item), $standard_root, true)) { continue; }
            $full = ABSPATH . $item;
            if (is_file($full)) {
                $unexpected[] = $item;
                if (count($unexpected) >= 20) { break; }
            }
        }
        return $unexpected;
    }

    /**
     * Cerca pattern di codice malevolo / offuscamento ad altissima confidenza nei post pubblicati
     * senza mai generare notifiche per le normali modifiche editoriali del cliente.
     */
    private static function scan_db_malware_signatures() {
        global $wpdb;
        $signals = array();
        $rows = $wpdb->get_results(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND (
                    post_content LIKE '%eval(base64_decode%'
                 OR post_content LIKE '%eval(gzinflate%'
                 OR post_content LIKE '%String.fromCharCode(1%'
                 OR post_content LIKE '%fromCharCode(0x%'
                 OR post_content LIKE '%document.write(unescape(%'
                 OR post_content LIKE '%<iframe%display:none%'
                 OR post_content LIKE '%<iframe%width=\"0\"%height=\"0\"%'
               )
             LIMIT 10",
            ARRAY_A
        );
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $signals[] = sprintf('Post #%d (%s: %s) contiene pattern sospetto', (int) $r['ID'], sanitize_key($r['post_type']), substr(sanitize_text_field($r['post_title']), 0, 50));
            }
        }
        return $signals;
    }

    /** Raccoglie la telemetria di sicurezza zero-noise e gli indicatori operativi del sito. */
    private static function security_telemetry() {
        $baseline = get_option(self::BASELINE, false);
        if (!is_array($baseline) || empty($baseline['hashes']) || !is_array($baseline['hashes'])) {
            $baseline = self::rebuild_baseline('initial');
        }
        $current_hashes = self::critical_files_map();
        $base_hashes    = $baseline['hashes'];
        $modified_files = array();
        foreach ($current_hashes as $label => $hash) {
            if (!isset($base_hashes[$label])) {
                $modified_files[] = $label . ' (nuovo file critico)';
            } elseif (!hash_equals((string) $base_hashes[$label], (string) $hash)) {
                $modified_files[] = $label . ' (hash modificato)';
            }
        }
        foreach ($base_hashes as $label => $old_hash) {
            if (!isset($current_hashes[$label])) {
                $modified_files[] = $label . ' (rimosso)';
            }
        }

        // Elenco degli amministratori per rilevamento di eventuali Rogue Admin sul Master.
        $admins = array();
        if (function_exists('get_users')) {
            $user_objs = get_users(array(
                'role'   => 'administrator',
                'fields' => array('user_login', 'user_email', 'user_registered'),
                'number' => 50,
            ));
            foreach ((array) $user_objs as $u) {
                if (!empty($u->user_login)) {
                    $admins[] = sanitize_text_field($u->user_login . ' <' . $u->user_email . '>');
                }
            }
            sort($admins);
        }

        $debug_log_path = WP_CONTENT_DIR . '/debug.log';
        $debug_log_size = (is_file($debug_log_path) && is_readable($debug_log_path)) ? (int) @filesize($debug_log_path) : 0;
        $last_fatal     = (array) get_option(self::FATAL, array());
        $fatal_summary  = '';
        if (!empty($last_fatal['at']) && (time() - (int) $last_fatal['at']) <= 7 * DAY_IN_SECONDS) {
            $fatal_summary = sprintf(
                '%s in %s:%d (%s)',
                (string) ($last_fatal['msg'] ?? 'Fatal Error'),
                (string) ($last_fatal['file'] ?? ''),
                (int) ($last_fatal['line'] ?? 0),
                wp_date('d/m H:i', (int) $last_fatal['at'])
            );
        }

        return array(
            'uploads_php'       => array_slice(self::scan_uploads_executables(), 0, 20),
            'root_unexpected'   => array_slice(self::scan_unexpected_root_php(), 0, 20),
            'modified_critical' => array_slice($modified_files, 0, 25),
            'db_injections'     => array_slice(self::scan_db_malware_signatures(), 0, 10),
            'admins'            => array_slice($admins, 0, 40),
            'blog_public'       => (int) get_option('blog_public', 1) === 1,
            'file_edit_allowed' => !defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT,
            'debug_log_kb'      => $debug_log_size > 0 ? (int) ceil($debug_log_size / 1024) : 0,
            'last_fatal'        => substr($fatal_summary, 0, 240),
            'baseline_at'       => (int) ($baseline['created_at'] ?? 0),
        );
    }

    /** Restituisce solo l'host di un URL: i link di download premium possono contenere chiavi di licenza. */
    private static function url_host($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_match('/^[a-z0-9.-]{1,120}$/', $host) ? $host : '';
    }

    /**
     * Canale di aggiornamento reale, letto dal transient che WordPress popola dopo il controllo:
     * wporg = presente in WordPress.org; vendor = updater del fornitore; external = Update URI senza updater;
     * disabled = Update URI che disattiva gli aggiornamenti; none = nessun canale; unknown = controllo mai eseguito.
     */
    private static function update_channel($file, $data, $update_plugins) {
        $item = null;
        if (is_object($update_plugins)) {
            foreach (array('response', 'no_update') as $bucket) {
                if (isset($update_plugins->{$bucket}) && is_array($update_plugins->{$bucket}) && isset($update_plugins->{$bucket}[$file])) {
                    $item = $update_plugins->{$bucket}[$file];
                    break;
                }
            }
        }
        if (is_array($item)) { $item = (object) $item; }
        if (is_object($item)) {
            $id = (string) ($item->id ?? '');
            $package_host = self::url_host($item->package ?? '');
            $url_host = self::url_host($item->url ?? '');
            if (strpos($id, 'w.org/plugins/') === 0 || $package_host === 'downloads.wordpress.org' || $url_host === 'wordpress.org') {
                return array('type' => 'wporg', 'host' => 'wordpress.org');
            }
            return array('type' => 'vendor', 'host' => $package_host ?: $url_host);
        }
        $update_uri = trim((string) ($data['UpdateURI'] ?? ''));
        if ($update_uri !== '') {
            if (in_array(strtolower($update_uri), array('false', 'none', 'no'), true)) {
                return array('type' => 'disabled', 'host' => '');
            }
            $host = self::url_host($update_uri) ?: self::url_host('https://' . ltrim($update_uri, '/'));
            if ($host !== '' && $host !== 'wordpress.org' && $host !== 'w.org') {
                return array('type' => has_filter('update_plugins_' . $host) ? 'vendor' : 'external', 'host' => $host);
            }
        }
        $checked = is_object($update_plugins) && !empty($update_plugins->last_checked);
        return array('type' => $checked ? 'none' : 'unknown', 'host' => '');
    }

    /** Firme di codice che indicano un addon costruito per un ecosistema (chiavi condivise con il Master). */
    private static function coupling_signatures() {
        return array(
            'woocommerce'  => '~woocommerce_loaded|before_woocommerce_init|WC_VERSION|class_exists\s*\(\s*[\'"][^\'"]?WooCommerce[\'"]~',
            'elementor'    => '~elementor/loaded|ELEMENTOR_VERSION|elementor/widgets/register|Elementor\\\\Plugin~',
            'acf'          => '~acf/init|acf/include_field_types|class_exists\s*\(\s*[\'"]ACF[\'"]~',
            'gravityforms' => '~gform_loaded|GFForms|GFAddOn~',
            'wpml'         => '~ICL_SITEPRESS_VERSION|wpml_loaded~',
            'cf7'          => '~WPCF7_VERSION|wpcf7_init|wpcf7_before_send_mail~',
            'wpbakery'     => '~WPB_VC_VERSION|vc_before_init|vc_map\s*\(~',
            'edd'          => '~EDD_VERSION|Easy_Digital_Downloads~',
            'buddypress'   => '~bp_include|BP_VERSION|bp_loaded~',
            'learndash'    => '~LEARNDASH_VERSION|learndash_init~',
            'polylang'     => '~POLYLANG_VERSION|pll_init~',
            'wpforms'      => '~WPFORMS_VERSION|wpforms_loaded~',
            'tec'          => '~Tribe__Events__Main|tribe_common_loaded|TRIBE_EVENTS_FILE~',
            'yoast'        => '~WPSEO_VERSION|wpseo_loaded~',
            'rankmath'     => '~rank_math/loaded|RANK_MATH_VERSION~',
            'beaver'       => '~FL_BUILDER_VERSION|fl_builder_init~',
            'revslider'    => '~RS_REVISION|RevSliderFront~',
            'memberpress'  => '~MEPR_VERSION|MeprUser~',
            'ninjaforms'   => '~ninja_forms_loaded|Ninja_Forms\s*\(~',
        );
    }

    /**
     * Legge (max 48 KB) il solo file principale di ciascun plugin per le firme di ecosistema e controlla
     * la presenza di updater commerciali noti; calcola l'impronta SHA-256 del file principale, che il Master
     * confronta con i checksum ufficiali e con gli altri siti. Cache per file+versione+mtime; budget di 2 secondi.
     */
    private static function plugin_code_analysis($all_plugins) {
        $cache = get_option(self::COUPLING, array());
        $cache = is_array($cache) ? $cache : array();
        $fresh = array();
        $signatures = self::coupling_signatures();
        $updater_files = array(
            'freemius' => array('freemius/start.php', 'vendor/freemius/wordpress-sdk/start.php', 'includes/freemius/start.php', 'lib/freemius/start.php', 'inc/freemius/start.php'),
            'edd'      => array('EDD_SL_Plugin_Updater.php', 'includes/EDD_SL_Plugin_Updater.php', 'inc/EDD_SL_Plugin_Updater.php', 'includes/updater/EDD_SL_Plugin_Updater.php', 'includes/admin/EDD_SL_Plugin_Updater.php', 'updater/EDD_SL_Plugin_Updater.php'),
            'puc'      => array('plugin-update-checker/plugin-update-checker.php', 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php', 'lib/plugin-update-checker/plugin-update-checker.php', 'includes/plugin-update-checker/plugin-update-checker.php', 'inc/plugin-update-checker/plugin-update-checker.php'),
        );
        $start = microtime(true);
        foreach ($all_plugins as $file => $data) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            $mtime = (int) @filemtime($path);
            $stamp = (string) ($data['Version'] ?? '') . ':' . $mtime;
            if (isset($cache[$file]['s']) && $cache[$file]['s'] === $stamp && array_key_exists('h', $cache[$file])) {
                $fresh[$file] = $cache[$file];
                continue;
            }
            if ((microtime(true) - $start) > 2.0) { continue; } // completato al prossimo invio
            $couplings = array();
            $content = (is_file($path) && is_readable($path)) ? (string) @file_get_contents($path, false, null, 0, 49152) : '';
            if ($content !== '') {
                foreach ($signatures as $key => $regex) {
                    if (preg_match($regex, $content)) { $couplings[] = $key; }
                }
            }
            $updaters = array();
            if (strpos($file, '/') !== false) {
                $dir = WP_PLUGIN_DIR . '/' . dirname($file) . '/';
                foreach ($updater_files as $key => $candidates) {
                    foreach ($candidates as $candidate) {
                        if (is_file($dir . $candidate)) { $updaters[] = $key; break; }
                    }
                }
            }
            $hash = '';
            if ($content !== '' && (int) @filesize($path) <= 2097152) {
                $hash = (string) @hash_file('sha256', $path);
            }
            $fresh[$file] = array('s' => $stamp, 'c' => $couplings, 'u' => $updaters, 'h' => $hash);
        }
        if ($fresh !== $cache) { update_option(self::COUPLING, $fresh, false); }
        return $fresh;
    }

    /**
     * Stato della licenza dei premium piu diffusi, letto dalle loro opzioni gia salvate nel database.
     * Si invia solo lo stato e la scadenza, MAI la chiave. "present" = chiave inserita, validita non verificabile qui.
     */
    private static function license_status($slug) {
        switch ($slug) {
            case 'elementor-pro':
                if (trim((string) get_option('elementor_pro_license_key', '')) === '') { return array('status' => 'missing', 'expires' => ''); }
                $data = get_option('_elementor_pro_license_v2_data');
                if (is_array($data) && isset($data['value']) && is_string($data['value'])) {
                    $info = json_decode($data['value'], true);
                    if (is_array($info) && !empty($info['license']) && is_string($info['license'])) {
                        return array('status' => self::license_word($info['license']), 'expires' => self::license_date($info['expires'] ?? ''));
                    }
                }
                return array('status' => 'present', 'expires' => '');
            case 'advanced-custom-fields-pro':
                if (!((defined('ACF_PRO_LICENSE') && ACF_PRO_LICENSE) || get_option('acf_pro_license'))) { return array('status' => 'missing', 'expires' => ''); }
                $state = get_option('acf_pro_license_status');
                if (is_array($state) && !empty($state['status']) && is_string($state['status'])) {
                    return array('status' => self::license_word($state['status']), 'expires' => !empty($state['lifetime']) ? 'lifetime' : self::license_date($state['expiry'] ?? ''));
                }
                return array('status' => 'present', 'expires' => '');
            case 'gravityforms':
                $has = (defined('GF_LICENSE_KEY') && GF_LICENSE_KEY) || get_option('rg_gforms_key');
                return array('status' => $has ? 'present' : 'missing', 'expires' => '');
            case 'wp-rocket':
                $settings = get_option('wp_rocket_settings');
                $has = (defined('WP_ROCKET_KEY') && WP_ROCKET_KEY) || (is_array($settings) && !empty($settings['consumer_key']));
                return array('status' => $has ? 'present' : 'missing', 'expires' => '');
            case 'divi-builder':
            case 'divi':
            case 'extra':
                $opt = get_site_option('et_automatic_updates_options', get_option('et_automatic_updates_options'));
                $has = is_array($opt) && !empty($opt['username']) && !empty($opt['api_key']);
                return array('status' => $has ? 'present' : 'missing', 'expires' => '');
        }
        return null;
    }

    private static function license_word($word) {
        $word = strtolower(trim((string) $word));
        if (in_array($word, array('valid', 'active'), true)) { return 'valid'; }
        if ($word === 'expired') { return 'expired'; }
        if (in_array($word, array('invalid', 'inactive', 'site_inactive', 'disabled', 'revoked', 'cancelled', 'canceled', 'missing', 'unlicensed', 'no_activations_left'), true)) { return 'invalid'; }
        return 'present';
    }

    private static function license_date($value) {
        if (is_numeric($value) && (int) $value > 0) { return gmdate('Y-m-d', (int) $value); }
        $value = trim((string) $value);
        if (strtolower($value) === 'lifetime') { return 'lifetime'; }
        $time = $value !== '' ? strtotime($value) : false;
        return $time ? gmdate('Y-m-d', $time) : '';
    }

    /** Canale di aggiornamento di un tema, come per i plugin (wporg, vendor, none, unknown). */
    private static function theme_channel($stylesheet, $update_themes) {
        $item = null;
        if (is_object($update_themes)) {
            foreach (array('response', 'no_update') as $bucket) {
                if (isset($update_themes->{$bucket}[$stylesheet])) { $item = $update_themes->{$bucket}[$stylesheet]; break; }
            }
        }
        if (is_object($item)) { $item = (array) $item; }
        if (is_array($item)) {
            $package_host = self::url_host($item['package'] ?? '');
            $url_host = self::url_host($item['url'] ?? '');
            if ($package_host === 'downloads.wordpress.org' || $url_host === 'wordpress.org') {
                return array('type' => 'wporg', 'host' => 'wordpress.org');
            }
            return array('type' => 'vendor', 'host' => $package_host ?: $url_host);
        }
        $checked = is_object($update_themes) && !empty($update_themes->last_checked);
        return array('type' => $checked ? 'none' : 'unknown', 'host' => '');
    }

    /**
     * Ambiente che decide se gli aggiornamenti possono funzionare: costanti, permessi, spazio, memoria,
     * WP-Cron, Site Health, backup, lavori post-aggiornamento. Solo letture locali gia disponibili.
     */
    private static function environment() {
        global $wpdb;
        if (!function_exists('get_filesystem_method')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $info = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';
        $db = array('type' => 'mysql', 'version' => '');
        if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $info, $m)) {
            $db = array('type' => 'mariadb', 'version' => $m[1]);
        } elseif (stripos($info, 'mariadb') !== false && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $info, $m)) {
            $db = array('type' => 'mariadb', 'version' => $m[1]);
        } elseif (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $info, $m)) {
            $db['version'] = $m[1];
        }
        $disk = null;
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(WP_CONTENT_DIR);
            if (is_numeric($free)) { $disk = (int) floor($free / 1048576); }
        }
        $fs_method = '';
        try { $fs_method = (string) get_filesystem_method(); } catch (\Throwable $e) { $fs_method = ''; }

        $overdue = 0; $oldest = 0;
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : array();
        foreach ((array) $crons as $timestamp => $hooks) {
            if ((int) $timestamp < time() - HOUR_IN_SECONDS) {
                $overdue += is_array($hooks) ? count($hooks) : 1;
                $oldest = $oldest ? min($oldest, (int) $timestamp) : (int) $timestamp;
            }
        }
        $health = null;
        $raw_health = get_transient('health-check-site-status-result');
        if (is_string($raw_health)) {
            $decoded = json_decode($raw_health, true);
            if (is_array($decoded)) {
                $health = array('good' => (int) ($decoded['good'] ?? 0), 'recommended' => (int) ($decoded['recommended'] ?? 0), 'critical' => (int) ($decoded['critical'] ?? 0));
            }
        }
        $auto_plugins = array();
        foreach (array_slice((array) get_site_option('auto_update_plugins', array()), 0, 300) as $file) {
            if (is_string($file) && $file !== '') { $auto_plugins[] = self::plugin_slug($file); }
        }
        $core_auto = 'minor';
        if (defined('WP_AUTO_UPDATE_CORE')) {
            $core_auto = WP_AUTO_UPDATE_CORE === true ? 'all' : (WP_AUTO_UPDATE_CORE === false ? 'off' : sanitize_key((string) WP_AUTO_UPDATE_CORE));
        } elseif (get_site_option('auto_update_core_major') === 'enabled') {
            $core_auto = 'all';
        }
        if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) { $core_auto = 'off'; }

        $env = array(
            'db' => $db,
            'locale' => (string) get_locale(),
            'file_mods' => !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS),
            'fs_method' => $fs_method,
            'fs_credentials' => defined('FTP_HOST') && defined('FTP_USER'),
            'writable' => array(
                'plugins' => wp_is_writable(WP_PLUGIN_DIR),
                'themes' => wp_is_writable(get_theme_root()),
                'content' => wp_is_writable(WP_CONTENT_DIR),
            ),
            'disk_free_mb' => $disk,
            'memory_limit' => (string) ini_get('memory_limit'),
            'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'cron' => array(
                'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'alternate' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
                'overdue' => $overdue,
                'oldest' => $oldest,
            ),
            'object_cache' => (bool) wp_using_ext_object_cache(),
            'health' => $health,
            'auto_updates' => array('plugins' => $auto_plugins, 'core' => $core_auto),
            'backup' => self::backup_info(),
            'pending' => self::pending_tasks(),
        );
        if (class_exists('Envato_Market') || defined('ENVATO_MARKET_VERSION')) {
            $envato = get_option('envato_market');
            $env['envato_market'] = array('token' => is_array($envato) && !empty($envato['token']));
        }
        return $env;
    }

    /** Ultimo backup noto da UpdraftPlus (il piu diffuso); gli altri plugin di backup sono riconosciuti dal Master. */
    private static function backup_info() {
        $last = get_option('updraft_last_backup');
        if (is_array($last) && !empty($last['backup_time']) && is_numeric($last['backup_time'])) {
            return array('plugin' => 'updraftplus', 'last' => (int) $last['backup_time'], 'ok' => !isset($last['success']) || !empty($last['success']));
        }
        return null;
    }

    /** Lavori che restano in sospeso dopo un aggiornamento: database WooCommerce/Elementor e template WooCommerce del tema. */
    private static function pending_tasks() {
        $pending = array();
        if (defined('WC_VERSION')) {
            $db = (string) get_option('woocommerce_db_version', '');
            if ($db !== '' && version_compare($db, WC_VERSION, '<')) { $pending['wc_db'] = array('db' => $db, 'code' => (string) WC_VERSION); }
            $templates = self::wc_outdated_templates();
            if ($templates) { $pending['wc_templates'] = $templates; }
        }
        if (defined('ELEMENTOR_VERSION')) {
            $db = (string) get_option('elementor_version', '');
            if ($db !== '' && version_compare($db, ELEMENTOR_VERSION, '<')) { $pending['elementor_db'] = array('db' => $db, 'code' => (string) ELEMENTOR_VERSION); }
        }
        return $pending;
    }

    /**
     * Template WooCommerce sovrascritti dal tema con @version inferiore all'originale (come il "System Status").
     * Ricalcolato al massimo una volta al giorno o quando cambiano tema/WooCommerce; budget 0,8 secondi.
     */
    private static function wc_outdated_templates() {
        if (!function_exists('WC') || !is_object(WC()) || !method_exists(WC(), 'plugin_path')) { return array(); }
        $dirs = array();
        $key = (string) WC_VERSION;
        foreach (array_unique(array(get_stylesheet_directory(), get_template_directory())) as $dir) {
            $wc_dir = trailingslashit($dir) . 'woocommerce';
            if (is_dir($wc_dir)) { $dirs[] = $wc_dir; $key .= '|' . $wc_dir . ':' . (int) @filemtime($wc_dir); }
        }
        if (!$dirs) { return array(); }
        $theme = wp_get_theme();
        $key = md5($key . '|' . $theme->get('Version') . '|' . ($theme->parent() ? $theme->parent()->get('Version') : ''));
        $cache = get_option(self::WC_TPL, array());
        if (is_array($cache) && ($cache['k'] ?? '') === $key && time() - (int) ($cache['at'] ?? 0) < DAY_IN_SECONDS) {
            return (array) ($cache['list'] ?? array());
        }
        $core_dir = trailingslashit(WC()->plugin_path()) . 'templates/';
        $list = array(); $seen = array(); $files = 0; $start = microtime(true);
        foreach ($dirs as $wc_dir) {
            $stack = array($wc_dir);
            while ($stack && $files < 300 && (microtime(true) - $start) < 0.8) {
                $current = array_pop($stack);
                $items = @scandir($current);
                if (!is_array($items)) { continue; }
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') { continue; }
                    $full = $current . '/' . $item;
                    if (is_dir($full)) { $stack[] = $full; continue; }
                    if (substr($item, -4) !== '.php') { continue; }
                    ++$files;
                    $rel = ltrim(substr($full, strlen($wc_dir)), '/');
                    if (isset($seen[$rel]) || !is_file($core_dir . $rel)) { continue; }
                    $seen[$rel] = true; // il tema figlio ha la precedenza sul genitore
                    $theme_version = self::template_version($full);
                    $core_version = self::template_version($core_dir . $rel);
                    if ($core_version !== '' && ($theme_version === '' || version_compare($theme_version, $core_version, '<'))) {
                        $list[] = array('file' => substr($rel, 0, 120), 'theme' => $theme_version, 'core' => $core_version);
                    }
                }
            }
        }
        $list = array_slice($list, 0, 20);
        update_option(self::WC_TPL, array('k' => $key, 'at' => time(), 'list' => $list), false);
        return $list;
    }

    private static function template_version($file) {
        $head = (string) @file_get_contents($file, false, null, 0, 8192);
        return preg_match('/@version\s+([0-9][0-9.]*)/i', $head, $m) ? rtrim($m[1], '.') : '';
    }

    /** MD5 di alcuni file del core: il Master li confronta con i checksum ufficiali di WordPress.org. */
    private static function core_md5() {
        $files = array('wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php',
            'wp-includes/load.php', 'wp-includes/plugin.php', 'wp-includes/functions.php', 'wp-includes/class-wp-hook.php',
            'wp-admin/admin.php', 'wp-admin/includes/plugin.php');
        $out = array();
        foreach ($files as $rel) {
            $full = ABSPATH . $rel;
            if (is_file($full) && is_readable($full) && (int) @filesize($full) <= 3145728) {
                $hash = @md5_file($full);
                if ($hash) { $out[$rel] = $hash; }
            }
        }
        return $out;
    }

    /**
     * Plugin richiesti/inclusi dal tema tramite TGM Plugin Activation (tipico dei temi ThemeForest).
     * Si inviano solo slug, versione inclusa e tipo di sorgente: mai percorsi o URL dei pacchetti.
     */
    private static function theme_bundled_plugins() {
        $out = array();
        try {
            if (empty($GLOBALS['tgmpa']) || !is_object($GLOBALS['tgmpa'])) { return $out; }
            $tgmpa = $GLOBALS['tgmpa'];
            if (empty($tgmpa->plugins) && has_action('tgmpa_register')) { do_action('tgmpa_register'); }
            $list = (isset($tgmpa->plugins) && is_array($tgmpa->plugins)) ? $tgmpa->plugins : array();
            foreach (array_slice($list, 0, 60, true) as $key => $p) {
                if (!is_array($p)) { continue; }
                $slug = sanitize_title((string) ($p['slug'] ?? $key));
                if ($slug === '') { continue; }
                $source = (string) ($p['source_type'] ?? '');
                $out[] = array(
                    'slug'     => $slug,
                    'version'  => substr(sanitize_text_field((string) ($p['version'] ?? '')), 0, 40),
                    'source'   => in_array($source, array('repo', 'external', 'bundled'), true) ? $source : 'unknown',
                    'required' => !empty($p['required']),
                );
            }
        } catch (\Throwable $e) {
            return array();
        }
        return $out;
    }

    public static function inventory() {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $update_plugins = get_site_transient('update_plugins');
        $update_themes = get_site_transient('update_themes');
        $update_core = get_site_transient('update_core');
        $network_active = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        $active = (array) get_option('active_plugins', array());
        $headers = array(
            'ElementorTested' => 'Elementor tested up to',
            'ElementorProTested' => 'Elementor Pro tested up to',
            'ElementorRequires' => 'Elementor requires at least',
            'WcTested' => 'WC tested up to',
            'WcRequires' => 'WC requires at least',
        );
        $plugins = array();
        $all_plugins = get_plugins();
        $analysis = self::plugin_code_analysis($all_plugins);
        foreach ($all_plugins as $file => $data) {
            $update = (is_object($update_plugins) && isset($update_plugins->response[$file])) ? $update_plugins->response[$file] : null;
            if (is_array($update)) { $update = (object) $update; }
            $extra = get_file_data(WP_PLUGIN_DIR . '/' . $file, $headers, 'plugin');
            $slug = self::plugin_slug($file);
            $plugins[] = array(
                'file' => $file,
                'slug' => $slug,
                'name' => (string) $data['Name'],
                'version' => (string) $data['Version'],
                'active' => in_array($file, $active, true) || isset($network_active[$file]),
                'requires_wp' => (string) ($data['RequiresWP'] ?? ''),
                'requires_php' => (string) ($data['RequiresPHP'] ?? ''),
                'requires_plugins' => array_values(array_filter(array_map('sanitize_title', array_map('trim', explode(',', (string) ($data['RequiresPlugins'] ?? '')))))),
                'update_uri' => (string) ($data['UpdateURI'] ?? ''),
                'text_domain' => (string) ($data['TextDomain'] ?? ''),
                'elementor_tested' => (string) ($extra['ElementorTested'] ?? ''),
                'elementor_pro_tested' => (string) ($extra['ElementorProTested'] ?? ''),
                'elementor_requires' => (string) ($extra['ElementorRequires'] ?? ''),
                'wc_tested' => (string) ($extra['WcTested'] ?? ''),
                'wc_requires' => (string) ($extra['WcRequires'] ?? ''),
                'author' => wp_strip_all_tags((string) ($data['AuthorName'] ?? '')),
                'plugin_uri' => esc_url_raw((string) ($data['PluginURI'] ?? ''), array('http', 'https')),
                'author_uri' => esc_url_raw((string) ($data['AuthorURI'] ?? ''), array('http', 'https')),
                'channel' => self::update_channel($file, $data, $update_plugins),
                'updaters' => $analysis[$file]['u'] ?? array(),
                'couplings' => $analysis[$file]['c'] ?? array(),
                'hash' => (string) ($analysis[$file]['h'] ?? ''),
                'license' => self::license_status($slug),
                'update' => $update ? array(
                    'version' => (string) ($update->new_version ?? ''),
                    'requires_wp' => (string) ($update->requires ?? ''),
                    'requires_php' => (string) ($update->requires_php ?? ''),
                    'tested' => (string) ($update->tested ?? ''),
                    // Pacchetto vuoto = WordPress mostra "aggiornamento automatico non disponibile": licenza mancante o scaduta.
                    'pkg' => !empty($update->package) ? 1 : 0,
                ) : null,
            );
        }

        // Inventario Must-Use Plugins (mu-plugins) e Drop-ins di sistema.
        $mu_plugins = array();
        if (function_exists('get_mu_plugins')) {
            foreach ((array) get_mu_plugins() as $file => $data) {
                $mu_plugins[] = array(
                    'file'    => (string) $file,
                    'name'    => (string) ($data['Name'] ?: $file),
                    'version' => (string) ($data['Version'] ?? ''),
                );
            }
        }
        if (function_exists('get_dropins')) {
            foreach ((array) get_dropins() as $file => $data) {
                $mu_plugins[] = array(
                    'file'    => 'dropin:' . (string) $file,
                    'name'    => '[Drop-in] ' . (string) ($data['Name'] ?: $file),
                    'version' => (string) ($data['Version'] ?? ''),
                );
            }
        }

        $themes = array();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $update = (is_object($update_themes) && isset($update_themes->response[$stylesheet])) ? $update_themes->response[$stylesheet] : null;
            if (is_object($update)) { $update = (array) $update; }
            $role = $stylesheet === get_stylesheet() ? 'active' : ($stylesheet === get_template() ? 'parent' : '');
            $template = (string) $theme->get_template();
            $functions = $theme->get_stylesheet_directory() . '/functions.php';
            $theme_slug = sanitize_title($stylesheet);
            $themes[] = array(
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === get_stylesheet() || $stylesheet === get_template(),
                'update' => $update ? (string) ($update['new_version'] ?? '') : '',
                'role' => $role,
                'parent' => $template !== '' && $template !== $stylesheet ? sanitize_title($template) : '',
                'author' => wp_strip_all_tags((string) $theme->get('Author')),
                'theme_uri' => esc_url_raw((string) $theme->get('ThemeURI'), array('http', 'https')),
                'author_uri' => esc_url_raw((string) $theme->get('AuthorURI'), array('http', 'https')),
                'requires_wp' => (string) $theme->get('RequiresWP'),
                'requires_php' => (string) $theme->get('RequiresPHP'),
                'channel' => self::theme_channel($stylesheet, $update_themes),
                'update_info' => $update ? array(
                    'pkg' => !empty($update['package']) ? 1 : 0,
                    'requires_wp' => (string) ($update['requires'] ?? ''),
                    'requires_php' => (string) ($update['requires_php'] ?? ''),
                ) : null,
                // Impronta di functions.php solo per i temi in uso: il Master la confronta tra siti con la stessa versione.
                'hash' => ($role !== '' && is_file($functions) && (int) @filesize($functions) <= 2097152) ? (string) @hash_file('sha256', $functions) : '',
                'license' => self::license_status($theme_slug),
            );
        }
        $core_target = '';
        if (is_object($update_core) && !empty($update_core->updates)) {
            foreach ($update_core->updates as $item) {
                if (isset($item->response, $item->current) && $item->response === 'upgrade') {
                    $core_target = (string) $item->current;
                    break;
                }
            }
        }
        return array(
            'url' => untrailingslashit(home_url('/')),
            'name' => get_bloginfo('name'),
            'wp' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'multisite' => is_multisite(),
            'core_update' => $core_target,
            'plugin_updates_checked' => is_object($update_plugins) ? (int) ($update_plugins->last_checked ?? 0) : 0,
            'theme_updates_checked' => is_object($update_themes) ? (int) ($update_themes->last_checked ?? 0) : 0,
            'plugins' => $plugins,
            'mu_plugins' => array_slice($mu_plugins, 0, 60),
            'themes' => $themes,
            'bundled' => self::theme_bundled_plugins(),
            'security' => self::security_telemetry(),
            'env' => self::environment(),
            'core_md5' => self::core_md5(),
            'reported_at' => time(),
            'client_version' => self::VERSION,
            'auto_updated' => array_slice((array) get_option(self::AUTO, array()), -100),
        );
    }

    public static function send() {
        $config = self::config();
        $secret = self::decrypt_secret($config['secret'] ?? '');
        if (empty($config['master']) || empty($config['site_id']) || !$secret) {
            update_option(self::LAST, array('ok' => false, 'date' => time(), 'message' => 'Connessione non configurata o chiave non leggibile.'), false);
            return false;
        }
        $json = wp_json_encode(self::inventory());
        if (!$json || strlen($json) > 512000) {
            update_option(self::LAST, array('ok' => false, 'date' => time(), 'message' => 'Inventario troppo grande.'), false);
            return false;
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $payload = "POST\nintegra-sentinel/v1/report\n{$timestamp}\n{$nonce}\n" . hash('sha256', $json);
        $signature = hash_hmac('sha256', $payload, $secret);
        $endpoints = array(
            'standard' => $config['master'] . '/wp-json/integra-sentinel/v1/report',
            'alternativo' => add_query_arg('rest_route', '/integra-sentinel/v1/report', trailingslashit($config['master'])),
        );
        $arguments = array(
            'timeout' => 20,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Integra-Site' => $config['site_id'],
                'X-Integra-Timestamp' => $timestamp,
                'X-Integra-Nonce' => $nonce,
                'X-Integra-Signature' => $signature,
            ),
            'body' => $json,
            'limit_response_size' => 4096,
        );
        $attempts = array();
        $ok = false;
        $message = 'Nessun endpoint REST raggiungibile.';
        foreach ($endpoints as $mode => $endpoint) {
            $result = wp_safe_remote_post($endpoint, $arguments);
            if (is_wp_error($result)) {
                $message = 'Errore di rete (' . $mode . '): ' . $result->get_error_message();
                $attempts[] = array('mode' => $mode, 'result' => 'rete');
                break;
            }
            $code = (int) wp_remote_retrieve_response_code($result);
            $attempts[] = array('mode' => $mode, 'result' => 'HTTP ' . $code);
            if ($code === 200) {
                $ok = true;
                $message = 'HTTP 200 (' . $mode . ')';
                break;
            }
            $message = 'HTTP ' . $code . ' (' . $mode . ')';
            $decoded = json_decode((string) wp_remote_retrieve_body($result), true);
            if (is_array($decoded) && !empty($decoded['code']) && is_string($decoded['code'])) {
                $message .= ' — ' . sanitize_key($decoded['code']);
            }
            if ($code !== 404 || $mode !== 'standard') { break; }
        }
        if (!$ok && count($attempts) === 2 && $attempts[1]['result'] === 'HTTP 404') {
            $message .= '. Controlla l’URL completo del Master (inclusa l’eventuale sottocartella) e le REST API.';
        }
        update_option(self::LAST, array(
            'ok' => $ok,
            'date' => time(),
            'message' => $message,
            'attempts' => $attempts,
        ), false);
        return $ok;
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $config = self::config();
        $has_secret = (bool) self::decrypt_secret($config['secret'] ?? '');
        $last = (array) get_option(self::LAST, array());
        $baseline = (array) get_option(self::BASELINE, array());
        $telemetry = self::security_telemetry();
        $msg = isset($_GET['integra_msg']) ? sanitize_key($_GET['integra_msg']) : '';
        echo '<div class="wrap"><h1>Integra Sentinel <small>Client v' . esc_html(self::VERSION) . '</small></h1>';
        echo '<p>Collega questo sito alla centrale Integra Sentinel. Il plugin invia versioni, stato aggiornamenti e telemetria di integrità zero-noise (file eseguibili in uploads, hash file critici, amministratori e stato ambiente).</p>';
        if ($msg) {
            $labels = array(
                'saved' => 'Configurazione salvata e chiave cifrata con AES-256-GCM.',
                'invalid' => 'Indirizzo, ID o chiave non validi.',
                'synced' => 'Inventario e telemetria inviati correttamente.',
                'failed' => 'Invio fallito: controlla la connessione o il rate-limit (max 1 invio ogni 45s).',
                'rebaselined' => 'Baseline degli hash di integrità rigenerata e inviata al Master.',
                'paired' => 'Sito collegato al Master con il codice di abbinamento: primo inventario inviato.',
                'paired_nosync' => 'Sito collegato al Master, ma il primo invio non è riuscito: riprova con "Invia inventario adesso".',
                'pair_invalid' => 'URL del Master (HTTPS) o codice di abbinamento non validi: il codice ha 12 caratteri.',
                'pair_failed' => 'Abbinamento non riuscito: il codice potrebbe essere scaduto, già usato o generato per un altro indirizzo.',
            );
            if (isset($labels[$msg])) {
                $detail = isset($_GET['integra_detail']) ? sanitize_key(wp_unslash($_GET['integra_detail'])) : '';
                echo '<div class="notice notice-' . esc_attr(in_array($msg, array('invalid', 'failed', 'pair_invalid', 'pair_failed'), true) ? 'error' : 'success') . '"><p>' . esc_html($labels[$msg]) . ($detail !== '' ? ' <code>' . esc_html($detail) . '</code>' : '') . '</p></div>';
            }
        }
        echo '<h2>Collegamento rapido con codice</h2><p>Nel Master apri la scheda del sito, genera un codice di abbinamento (valido 30 minuti) e incollalo qui insieme all’URL del Master. ID e chiave arrivano cifrati in HTTPS, senza copia-incolla.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('integra_sentinel_client_pair');
        echo '<input type="hidden" name="action" value="integra_sentinel_client_pair"><table class="form-table"><tbody>';
        echo '<tr><th><label for="integra_pair_master">URL del Master</label></th><td><input id="integra_pair_master" class="regular-text" type="url" required placeholder="https://sentinel.example.it" name="master" value="' . esc_attr($config['master'] ?? '') . '"></td></tr>';
        echo '<tr><th><label for="integra_pair_code">Codice di abbinamento</label></th><td><input id="integra_pair_code" class="regular-text code" required name="pair_code" autocomplete="off" placeholder="XXXX-XXXX-XXXX" maxlength="20"></td></tr>';
        echo '</tbody></table>';
        submit_button('Collega con il codice', 'primary', 'submit', true);
        echo '</form><hr><h2>Collegamento manuale</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('integra_sentinel_client_save');
        echo '<input type="hidden" name="action" value="integra_sentinel_client_save"><table class="form-table"><tbody>';
        echo '<tr><th><label for="integra_master">URL del Master</label></th><td><input id="integra_master" class="regular-text" type="url" required placeholder="https://sentinel.example.it" name="master" value="' . esc_attr($config['master'] ?? '') . '"></td></tr>';
        echo '<tr><th><label for="integra_site_id">ID sito</label></th><td><input id="integra_site_id" class="regular-text" required name="site_id" value="' . esc_attr($config['site_id'] ?? '') . '"></td></tr>';
        echo '<tr><th><label for="integra_secret">Chiave segreta</label></th><td><input id="integra_secret" class="regular-text" type="password" autocomplete="new-password" name="secret" placeholder="' . (!$has_secret ? 'Incolla la chiave' : 'Chiave cifrata presente (lascia vuoto per mantenerla)') . '"><p class="description">La chiave è custodita cifrata (AES-256-GCM) e non viene mai mostrata in chiaro.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Salva connessione');
        echo '</form>';

        echo '<hr><h2>Stato Integrità Locale (Zero-Noise)</h2>';
        echo '<p>Baseline hash file critici aggiornata il: <strong>' . (!empty($baseline['created_at']) ? esc_html(wp_date('d/m/Y H:i:s', (int) $baseline['created_at'])) : 'N/D') . '</strong> (' . count((array) ($baseline['hashes'] ?? array())) . ' file di sistema/tema/mu-plugins monitorati).</p>';
        $local_alerts = count($telemetry['uploads_php']) + count($telemetry['root_unexpected']) + count($telemetry['modified_critical']) + count($telemetry['db_injections']);
        if ($local_alerts === 0) {
            echo '<p style="color:#147858;font-weight:600">&#10003; Nessun file eseguibile sospetto in uploads, nessun file anomalo in root e nessuna modifica non autorizzata ai file critici.</p>';
        } else {
            echo '<p style="color:#a91f36;font-weight:600">&#9888; Rilevate ' . (int) $local_alerts . ' anomalie di integrità (visibili nel dettaglio sul Master). Se hai appena applicato modifiche manuali legittime via FTP a functions.php o wp-config.php, rigenera la baseline qui sotto.</p>';
        }

        $env = self::environment();
        $blocks = array();
        if (empty($env['file_mods'])) { $blocks[] = 'DISALLOW_FILE_MODS attivo: WordPress non può installare né aggiornare nulla.'; }
        if ($env['fs_method'] !== '' && $env['fs_method'] !== 'direct' && empty($env['fs_credentials'])) { $blocks[] = 'WordPress chiede le credenziali FTP per aggiornare (metodo ' . $env['fs_method'] . ').'; }
        if (empty($env['writable']['plugins'])) { $blocks[] = 'Cartella dei plugin non scrivibile.'; }
        if ($env['disk_free_mb'] !== null && $env['disk_free_mb'] < 500) { $blocks[] = 'Spazio libero su disco: ' . (int) $env['disk_free_mb'] . ' MB.'; }
        if (!empty($env['cron']['overdue']) && $env['cron']['oldest'] && time() - $env['cron']['oldest'] > 6 * HOUR_IN_SECONDS) { $blocks[] = 'WP-Cron non sembra eseguire i lavori programmati (' . (int) $env['cron']['overdue'] . ' in ritardo).'; }
        echo '<hr><h2>Aggiornabilità del sito</h2>';
        if (!$blocks) { echo '<p style="color:#147858;font-weight:600">&#10003; Nessun impedimento locale agli aggiornamenti rilevato (permessi, spazio, WP-Cron).</p>'; }
        else { echo '<ul style="list-style:disc;margin-left:20px;color:#8a5a00">'; foreach ($blocks as $block) { echo '<li>' . esc_html($block) . '</li>'; } echo '</ul>'; }

        echo '<hr><h2>Sincronizzazione e Azioni</h2><p>URL identificato dal Client: <code>' . esc_html(untrailingslashit(home_url('/'))) . '</code></p>';
        if (!empty($last)) {
            echo '<p><strong>Ultimo tentativo:</strong> ' . esc_html(wp_date('d/m/Y H:i:s', (int) $last['date'])) . ' — <strong>' . esc_html(!empty($last['ok']) ? 'Riuscito' : 'Non riuscito') . '</strong> (' . esc_html($last['message'] ?? '') . ')</p>';
        }
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('integra_sentinel_client_sync');
        echo '<input type="hidden" name="action" value="integra_sentinel_client_sync">';
        submit_button('Invia inventario adesso', 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('integra_sentinel_client_rebaseline');
        echo '<input type="hidden" name="action" value="integra_sentinel_client_rebaseline">';
        submit_button('Approva modifiche file e rigenera Baseline Hash', 'secondary', 'submit', false);
        echo '</form></div>';
        echo '<p><small>Trasferimento HTTPS autenticato HMAC-SHA256. La baseline degli hash si aggiorna automaticamente durante gli aggiornamenti di WordPress, plugin e temi.</small></p></div>';
    }
}

function normalize_path_safe($path) {
    return function_exists('wp_normalize_path') ? wp_normalize_path($path) : str_replace('\\', '/', $path);
}

/**
 * Comandi WP-CLI del Client: utili con il cron di sistema o per diagnosticare un sito.
 */
final class Integra_Sentinel_Client_CLI {
    /**
     * Invia subito inventario e telemetria al Master.
     *
     * ## EXAMPLES
     *
     *     wp sentinel-client send
     */
    public function send() {
        $ok = Integra_Sentinel_Client::send();
        $last = (array) get_option(Integra_Sentinel_Client::LAST, array());
        if ($ok) { WP_CLI::success('Inventario inviato: ' . ($last['message'] ?? 'HTTP 200')); }
        else { WP_CLI::error('Invio non riuscito: ' . ($last['message'] ?? 'errore sconosciuto')); }
    }

    /**
     * Mostra Master configurato ed esito dell'ultimo invio (la chiave non viene mai mostrata).
     */
    public function status() {
        $config = (array) get_option(Integra_Sentinel_Client::OPTION, array());
        $last = (array) get_option(Integra_Sentinel_Client::LAST, array());
        WP_CLI::line('Client: ' . Integra_Sentinel_Client::VERSION);
        WP_CLI::line('Master: ' . ($config['master'] ?? '—'));
        WP_CLI::line('ID sito: ' . ($config['site_id'] ?? '—'));
        WP_CLI::line('Ultimo invio: ' . (!empty($last['date']) ? wp_date('d/m/Y H:i', (int) $last['date']) : 'mai') . ' · ' . (!empty($last['ok']) ? 'riuscito' : 'non riuscito') . ' · ' . ($last['message'] ?? ''));
    }

    /**
     * Collega il sito al Master con un codice di abbinamento.
     *
     * ## OPTIONS
     *
     * <master>
     * : URL HTTPS del Master.
     *
     * <code>
     * : Codice di abbinamento generato nel Master (12 caratteri, trattini facoltativi).
     *
     * ## EXAMPLES
     *
     *     wp sentinel-client pair https://sentinel.example.it ABCD-EFGH-JKLM
     */
    public function pair($args) {
        $master = untrailingslashit(esc_url_raw(trim((string) ($args[0] ?? ''))));
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($args[1] ?? '')));
        if (strpos($master, 'https://') !== 0) { WP_CLI::error('L’URL del Master deve iniziare con https://'); }
        $result = Integra_Sentinel_Client::pair_with($master, $code);
        if ($result['msg'] === 'paired') { WP_CLI::success('Sito collegato e primo inventario inviato.'); }
        elseif ($result['msg'] === 'paired_nosync') { WP_CLI::warning('Sito collegato, ma il primo invio non è riuscito.'); }
        else { WP_CLI::error('Abbinamento non riuscito (' . $result['msg'] . ($result['detail'] !== '' ? ': ' . $result['detail'] : '') . ').'); }
    }
}

register_activation_hook(__FILE__, array('Integra_Sentinel_Client', 'activate'));
register_deactivation_hook(__FILE__, array('Integra_Sentinel_Client', 'deactivate'));
Integra_Sentinel_Client::init();
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('sentinel-client', 'Integra_Sentinel_Client_CLI');
}