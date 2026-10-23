<?php
/**
 * Integra Sentinel — uptime e pagine chiave controllati DAL Master: per il sito cliente e' l'equivalente di
 * una visita alla home (piu' fino a 3 pagine chiave, es. carrello e contatti). Ogni errore viene ricontrollato
 * subito; allarme dopo 3 controlli falliti di fila, mai quando non risponde nessun sito (rete del Master).
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Probe {

    const STATE = 'integra_sentinel_probe_state';
    /** Controlli falliti di fila prima dell'allarme (ogni controllo e' gia' ripetuto una volta prima di contare come fallito). */
    const FAILS_FOR_ALERT = 3;

    /**
     * Il server ha risposto: 2xx/3xx, oppure 401/403/429 (firewall o protezione anti-bot che rifiuta le visite automatiche).
     * Un sito che risponde cosi' e' online, anche se il controllo non vede la pagina.
     */
    public static function reachable($code) {
        return ($code >= 200 && $code < 400) || in_array((int) $code, array(401, 403, 429), true);
    }

    public static function state() {
        $state = get_option(self::STATE, array());
        return is_array($state) ? $state : array();
    }

    /** Home + pagine chiave della scheda cliente (solo URL HTTPS dello stesso dominio del sito). */
    public static function pages($site) {
        $meta = (array) ($site['meta'] ?? array());
        $pages = array(array('url' => trailingslashit((string) $site['url']), 'expect' => (string) ($meta['home_expect'] ?? ''), 'label' => 'Home'));
        $host = strtolower((string) wp_parse_url((string) $site['url'], PHP_URL_HOST));
        foreach (array_slice((array) ($meta['pages'] ?? array()), 0, 3) as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) { continue; }
            $pages[] = array('url' => $url, 'expect' => (string) ($page['expect'] ?? ''), 'label' => (string) ($page['label'] ?? '') ?: wp_parse_url($url, PHP_URL_PATH));
        }
        return $pages;
    }

    /**
     * Controlla i siti scaduti (o solo $only, subito). Massimo 12 siti per giro, richieste in parallelo.
     * @return array site_id => idx => ok, code, ms, error
     */
    public static function run($only = null, $force = false) {
        if (!Integra_Sentinel_Master::acquire_lock('probe_run', 0)) { return array(); }
        try {
            $sites = Integra_Sentinel_Master::sites();
            $state = self::state();
            $policies = Integra_Sentinel_Settings::get('policies');
            $interval = max(5, (int) ($policies['probe_minutes'] ?? 15)) * MINUTE_IN_SECONDS;
            $due = array();
            foreach ($sites as $id => $site) {
                if ($only !== null && $id !== $only) { continue; }
                if (!empty($site['meta']['probe_off']) && !$force) { continue; }
                $last = (int) ($state[$id]['last_at'] ?? 0);
                if ($force || time() - $last >= $interval - 30) { $due[$id] = $last; }
            }
            asort($due);
            $due = array_slice($due, 0, $only !== null ? 1 : 12, true);
            $requests = array(); $map = array();
            foreach (array_keys($due) as $id) {
                foreach (self::pages($sites[$id]) as $idx => $page) {
                    if (!wp_http_validate_url($page['url'])) { continue; }
                    $key = $id . '#' . $idx;
                    $requests[$key] = array('url' => $page['url'], 'timeout' => 15, 'redirects' => 3, 'max_bytes' => 600000, 'headers' => array('Cache-Control' => 'no-cache'));
                    $map[$key] = array($id, $idx, $page['expect']);
                }
            }
            if (!$requests) { return array(); }
            $responses = Integra_Sentinel_Intel::parallel_get($requests);
            $failed_ok = function($r) { return !self::reachable((int) $r['code']); };
            // Seconda prova immediata per le pagine fallite, con un browser comune e piu' tempo: un intoppo di rete non e' un sito spento.
            $retry = array();
            foreach ($requests as $key => $req) {
                if ($failed_ok($responses[$key] ?? array('code' => 0))) {
                    $retry[$key] = array_merge($req, array('timeout' => 25, 'redirects' => 5,
                        'useragent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'));
                }
            }
            if ($retry) {
                sleep(3);
                foreach (Integra_Sentinel_Intel::parallel_get($retry) as $key => $r) { if (!$failed_ok($r)) { $responses[$key] = $r; } }
            }
            $rows = array(); $results = array();
            foreach ($map as $key => $info) {
                list($id, $idx, $expect) = $info;
                $r = $responses[$key] ?? array('code' => 0, 'body' => '', 'error' => 'nessuna risposta', 'ms' => 0);
                $code = (int) $r['code'];
                $ok = self::reachable($code);
                $error = (string) $r['error'];
                $blocked = $ok && $code >= 400;
                if ($blocked) { $error = 'Il sito risponde ma blocca i controlli automatici (HTTP ' . $code . ')'; }
                elseif ($ok && $expect !== '' && stripos((string) $r['body'], $expect) === false) { $ok = false; $error = 'Testo atteso non trovato'; }
                if (!$ok && $error === '') { $error = 'HTTP ' . $code; }
                $rows[] = array('site_id' => $id, 'at' => time(), 'url_idx' => $idx, 'code' => $code, 'ms' => (int) $r['ms'], 'ok' => $ok, 'error' => $error);
                $results[$id][$idx] = array('ok' => $ok, 'code' => $code, 'ms' => (int) $r['ms'], 'error' => $error);
            }
            // Se nello stesso giro non risponde nessun sito (almeno 3), il problema e' la rete del Master: niente conteggi ne' allarmi.
            if (count($results) >= 3) {
                $any_ok = false;
                foreach ($results as $pages) { if (in_array(true, array_column($pages, 'ok'), true)) { $any_ok = true; break; } }
                if (!$any_ok) {
                    Integra_Sentinel_Store::log('probe', 'nessun sito raggiungibile in questo giro: probabile problema di rete del Master, controlli ignorati');
                    return array();
                }
            }
            Integra_Sentinel_Store::add_probes($rows);

            $transitions = array();
            Integra_Sentinel_Master::mutate_option(self::STATE, function($current) use ($results, $sites, &$transitions) {
                foreach ($results as $id => $pages) {
                    $s = (array) ($current[$id] ?? array()) + array('fails' => 0, 'down_since' => 0, 'alerted' => false);
                    $all_ok = !in_array(false, array_column($pages, 'ok'), true);
                    $s['last_at'] = time();
                    $s['pages'] = $pages;
                    if ($all_ok) {
                        if (!empty($s['alerted'])) { $transitions[$id] = array('up', (int) $s['down_since'], $pages); }
                        $s['fails'] = 0; $s['down_since'] = 0; $s['alerted'] = false; $s['last_ok'] = time();
                    } else {
                        $s['fails'] = (int) $s['fails'] + 1;
                        if (!$s['down_since']) { $s['down_since'] = time(); }
                        if ($s['fails'] >= self::FAILS_FOR_ALERT && empty($s['alerted'])) { $transitions[$id] = array('down', (int) $s['down_since'], $pages); }
                    }
                    $current[$id] = $s;
                }
                return array_intersect_key($current, $sites);
            });
            $sent = array();
            foreach ($transitions as $id => $t) {
                if (Integra_Sentinel_Master::uptime_alert($sites[$id], $t[0], $t[1], $t[2], self::pages($sites[$id]))) { $sent[$id] = $t[0]; }
            }
            if ($sent) {
                Integra_Sentinel_Master::mutate_option(self::STATE, function($current) use ($sent) {
                    foreach ($sent as $id => $kind) { if (isset($current[$id])) { $current[$id]['alerted'] = $kind === 'down'; } }
                    return $current;
                });
            }
            return $results;
        } finally {
            Integra_Sentinel_Master::release_lock('probe_run');
        }
    }
}
